<?php

namespace App\Services;

use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\Role;
use App\Models\User;
use App\Support\NicheRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LeadGenerator
{
    public function __construct(private ShecomClient $client) {}

    /**
     * Pull customers whose product runs out on $date, store them as leads and
     * assign them to CRAs.
     *
     * When the retention API is down, the leads come from the saved delivered
     * orders (retention API + Pancake POS) and Settings → Product Consumption
     * days instead; existing leads are then left exactly as they are.
     *
     * @return array{found: int, created: int, crd: int, fsd: int, assigned: int, unassigned: int, source: string, fallback_reason: ?string}
     */
    public function generate(CarbonImmutable $date): array
    {
        $date = $date->startOfDay();
        // Product names are grouped under Settings → Product Consumption products.
        $catalog = new ProductCatalog;
        $fallbackReason = null;

        try {
            // CRD Leads: CRD-delivered orders (Shecom's out-of-stock list). FSD Leads:
            // FSD-delivered orders, their out-of-stock date worked out here.
            $sources = $this->client->leadSources();
            $this->rememberDelivered($sources['crd']);
            $rows = [
                ...array_map(fn (array $row) => [...$row, 'lead_type' => Lead::TYPE_CRD], $sources['crd']),
                ...$this->fsdRows($sources['fsd'], $catalog, $date),
            ];
        } catch (Throwable $e) {
            $rows = $this->fallbackRows($catalog);

            if (empty($rows)) {
                throw $e;
            }

            $fallbackReason = $e->getMessage();
            Log::warning('Retention API unavailable; leads from saved delivered orders', ['date' => $date->toDateString(), 'message' => $fallbackReason]);
        }

        // Rows without a type (fallback): customers with enough delivered orders are
        // CRD Leads (see segmentation.crd_lead_min_orders).
        $ordersPerPhone = collect($rows)->countBy(fn (array $row) => self::normalizePhone($row['phone_number'] ?? ''));

        $leads = collect($rows)
            ->map(fn (array $row) => $this->toLead($row, $catalog, $ordersPerPhone))
            ->filter(fn (?array $lead) => $lead && $lead['est_out_of_stock_date'] === $date->toDateString())
            ->values();

        $created = 0;

        // One query for the leads that already exist; only changed ones are written back.
        $existing = $leads->pluck('order_id')->chunk(1000)
            ->flatMap(fn (Collection $ids) => Lead::whereIn('order_id', $ids->all())->get())
            ->keyBy('order_id');

        DB::transaction(function () use ($leads, &$created, $fallbackReason, $existing) {
            foreach ($leads as $data) {
                $lead = $existing[$data['order_id']] ?? new Lead(['order_id' => $data['order_id']]);

                // Fallback numbers are estimates: never move or change a lead that already exists.
                if ($lead->exists && $fallbackReason !== null) {
                    continue;
                }

                $created += $lead->exists ? 0 : 1;
                // Refresh order details but keep any assignment, status and type already
                // set (imported sheet leads carry the sheet's CRD/FSD type).
                $lead->fill($lead->exists ? Arr::except($data, ['lead_type']) : $data)->save();
            }
        });

        $assigned = $this->assign($date);

        // Today's on-duty CRAs topped up to the daily maximum with backlog, after their own leads.
        if ($date->isSameDay(Lead::today())) {
            app(BacklogFill::class)->fillOnDuty($date);
        }

        $forDay = Lead::whereDate('listed_on', $date)->get();

        $result = [
            'found' => $leads->count(),
            'created' => $created,
            'crd' => $forDay->where('lead_type', Lead::TYPE_CRD)->count(),
            'fsd' => $forDay->where('lead_type', Lead::TYPE_FSD)->count(),
            'assigned' => $assigned,
            'unassigned' => $forDay->whereNull('assigned_to')->count(),
            'source' => $fallbackReason === null ? 'retention_api' : 'fallback',
            'fallback_reason' => $fallbackReason,
        ];

        Cache::put(self::syncKey($date), ['at' => now()->toIso8601String(), ...$result], now()->addDays(40));

        return $result;
    }

    /**
     * Keep a copy of every delivered order the retention API returns, for the fallback.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function rememberDelivered(array $rows): void
    {
        $now = now();

        collect($rows)
            ->filter(fn (array $row) => ! empty($row['order_id']) && ! empty($row['delivered_date']))
            ->map(fn (array $row) => [
                'order_id' => (string) $row['order_id'],
                'tracking_number' => $row['tracking_number'] ?: null,
                'customer_name' => trim($row['customer_name'] ?? ''),
                'phone_number' => (string) ($row['phone_number'] ?? ''),
                'product_raw' => trim($row['product_name'] ?? ''),
                'qty' => max(1, (int) ($row['qty'] ?? 1)),
                'delivered_date' => CarbonImmutable::parse($row['delivered_date'])->toDateString(),
                'consumption_days_per_unit' => isset($row['consumption_days_per_unit']) ? (int) $row['consumption_days_per_unit'] : null,
                'source' => DeliveredOrder::SOURCE_SHECOM,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->unique('order_id')
            ->chunk(500)
            ->each(fn (Collection $chunk) => DeliveredOrder::upsert(
                $chunk->values()->all(),
                ['order_id'],
                ['tracking_number', 'customer_name', 'phone_number', 'product_raw', 'qty', 'delivered_date', 'consumption_days_per_unit', 'source', 'updated_at'],
            ));
    }

    /**
     * FSD-delivered orders that run out on $date, in the retention API's shape.
     * Out of stock = delivered date + (qty × consumption days) − 1, with days
     * from Settings → Product Consumption and qty from the Pancake POS order.
     * An order Pancake has no qty for is taken as qty 1 and flagged.
     *
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    private function fsdRows(array $orders, ProductCatalog $catalog, CarbonImmutable $date): array
    {
        $candidates = [];
        // Days whose Pancake delivered orders are saved. Orders from other days wait
        // until that day is synced (an hourly run picks them up) rather than guess qty 1.
        $synced = DeliveredOrder::where('source', DeliveredOrder::SOURCE_PANCAKE)
            ->distinct()->pluck('delivered_date')->map(fn ($day) => CarbonImmutable::parse($day)->toDateString())->flip();

        foreach ($orders as $order) {
            $days = $catalog->match($order['product_name'] ?? '')?->consumption_days;

            if (empty($order['order_id']) || empty($order['delivered_date']) || ! $days
                || ! isset($synced[substr((string) $order['delivered_date'], 0, 10)])) {
                continue;
            }

            // Days from delivery to $date, the delivery day being day 1: a whole number of units.
            $span = (int) CarbonImmutable::parse($order['delivered_date'])->diffInDays($date, false) + 1;

            if ($span >= $days && $span % $days === 0) {
                $candidates[(string) $order['order_id']] = [$order, $days, intdiv($span, $days)];
            }
        }

        // Merged with + so numeric order IDs stay keys (flatMap/array_merge would renumber them).
        $qty = [];
        foreach (array_chunk(array_keys($candidates), 1000) as $ids) {
            $qty += DeliveredOrder::where('source', DeliveredOrder::SOURCE_PANCAKE)
                ->whereIn('order_id', array_map('strval', $ids))->pluck('qty', 'order_id')->all();
        }

        $rows = [];

        foreach ($candidates as $id => [$order, $days, $units]) {
            $known = $qty[$id] ?? null;

            if ($known === null ? $units !== 1 : (int) $known !== $units) {
                continue;
            }

            $rows[] = [
                ...$order,
                'qty' => $units,
                'consumption_days_per_unit' => $days,
                'estimated_out_of_stock_date' => $date->toDateString(),
                'lead_type' => Lead::TYPE_FSD,
                'qty_unknown' => $known === null,
            ];
        }

        return $rows;
    }

    /**
     * The saved delivered orders in the retention API's shape, with the
     * out-of-stock date worked out the way the retention API does:
     * delivered date + (qty × consumption days) − 1, the delivery day being day 1.
     * Days per unit come from Settings → Product Consumption, else the last
     * value the retention API gave; orders with neither are skipped.
     *
     * @return list<array<string, mixed>>
     */
    private function fallbackRows(ProductCatalog $catalog): array
    {
        $rows = [];

        DeliveredOrder::query()->orderBy('id')->each(function (DeliveredOrder $order) use ($catalog, &$rows) {
            $days = $catalog->match($order->product_raw)?->consumption_days ?: $order->consumption_days_per_unit;

            if (! $days) {
                return;
            }

            $rows[] = [
                'order_id' => $order->order_id,
                'tracking_number' => $order->tracking_number,
                'customer_name' => $order->customer_name,
                'phone_number' => $order->phone_number,
                'product_name' => $order->product_raw,
                'qty' => $order->qty,
                'delivered_date' => $order->delivered_date->toDateString(),
                'consumption_days_per_unit' => $days,
                'estimated_out_of_stock_date' => $order->delivered_date->addDays($order->qty * $days - 1)->toDateString(),
            ];
        }, 1000);

        return $rows;
    }

    /**
     * Sync $date unless it was synced within $maxAgeMinutes. Returns the sync
     * result, or null when the last sync is still fresh or another sync is
     * already running.
     */
    /**
     * syncIfStale() after the page has been sent, so a slow or failing sync
     * (Shecom, a remote database, a short request time limit) never breaks the
     * page. A failure is kept for lastSyncError().
     * Skipped under `php artisan serve`: its built-in server holds the page until deferred work ends.
     */
    public function syncLater(CarbonImmutable $date): void
    {
        if (self::isBuiltInServer()) {
            return;
        }

        defer(function () use ($date) {
            // The page is already sent: let the sync finish past the request time limit.
            set_time_limit(0);
            ignore_user_abort(true);

            try {
                $this->syncIfStale($date);
                Cache::forget(self::errorKey($date));
            } catch (Throwable $e) {
                Log::warning('Automatic lead sync failed', ['date' => $date->toDateString(), 'message' => $e->getMessage()]);
                Cache::put(self::errorKey($date), $e->getMessage(), now()->addDay());
            }
        }, 'lead-sync-'.$date->toDateString());
    }

    /**
     * Why the last automatic sync of $date failed, if it did.
     */
    public static function lastSyncError(CarbonImmutable $date): ?string
    {
        return Cache::get(self::errorKey($date));
    }

    private static function errorKey(CarbonImmutable $date): string
    {
        return 'segmentation.sync_error.'.$date->toDateString();
    }

    public function syncIfStale(CarbonImmutable $date, int $maxAgeMinutes = 60): ?array
    {
        $last = self::lastSync($date);

        if ($last && $last->greaterThan(now()->subMinutes($maxAgeMinutes))) {
            return null;
        }

        return Cache::lock('segmentation-sync-'.$date->toDateString(), 120)
            ->get(fn () => $this->generate($date)) ?: null;
    }

    /**
     * When $date's leads were last synced from the API, if ever.
     */
    /**
     * The last sync's result for $date (when, how many, and whether it used the fallback).
     *
     * @return array<string, mixed>|null
     */
    public static function lastSyncResult(CarbonImmutable $date): ?array
    {
        return Cache::get(self::syncKey($date));
    }

    public static function lastSync(CarbonImmutable $date): ?CarbonImmutable
    {
        $at = Cache::get(self::syncKey($date))['at'] ?? null;

        return $at ? CarbonImmutable::parse($at) : null;
    }

    private static function syncKey(CarbonImmutable $date): string
    {
        return 'segmentation.sync.'.$date->toDateString();
    }

    /**
     * Hand out the day's unassigned leads. First each customer's own CRA gets their leads: a High AOV / VIP
     * customer's CRA (Customer Database → High AOV CVR & VIP), else the CRA who has handled two of their orders
     * (CustomerOwners) when the product is in their niche, whether or not they are on duty. The rest go one at a time,
     * CRD Leads first, to the CRA with the fewest leads that day whose niche (Settings → Niche) has the product, so
     * everyone fills toward the daily quota together and any excess is spread evenly; a lead no CRA's niche takes
     * waits unassigned. Today's own leads still untouched with another CRA (and not transferred) are moved to their
     * customer's CRA.
     */
    public function assign(CarbonImmutable $date): int
    {
        // Oldest account first, not the display order: ties go to the earliest CRA, as they always have.
        $cras = self::cras()->sortBy('id')->values();

        if ($cras->isEmpty()) {
            return 0;
        }

        $this->reclaimForOwners($date, $cras->pluck('id')->all());

        // Leads added from a CRA's own POS sale don't use up their daily quota, nor does backlog the fill moved here (BacklogFill).
        $load = Lead::whereDate('est_out_of_stock_date', $date)
            ->whereIn('assigned_to', $cras->pluck('id'))
            ->withoutPosSales()
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $load = $cras->mapWithKeys(fn (User $cra) => [$cra->id => (int) ($load[$cra->id] ?? 0)])->all();

        $pending = Lead::whereDate('listed_on', $date)
            ->whereNull('assigned_to')
            ->orderByRaw('lead_type = ? desc', [Lead::TYPE_CRD])
            ->orderBy('delivered_date')
            ->orderBy('id')
            ->get();

        $highValue = app(HighValueCustomers::class);
        $niches = NicheRules::current();
        $phones = $pending->mapWithKeys(fn (Lead $lead) => [$lead->id => self::normalizePhone((string) $lead->phone_number)]);
        // A High AOV / VIP customer new to the list gets their CRA for good first, so their lead goes to them.
        rescue(fn () => $highValue->assignNewCustomers($phones->unique()->values()->all()));
        $owners = $this->ownersFor($phones->unique()->values()->all(), $highValue);
        $highValueFlags = rescue(fn () => $highValue->flagsForNumbers($phones->unique()->values()->all()), []);
        $ownerOf = function (Lead $lead) use ($owners, $phones, $niches, $load) {
            $owner = self::ownerFor($owners, $phones[$lead->id], $lead->product_name, $niches);

            return $owner !== null && array_key_exists($owner, $load) ? $owner : null;
        };
        // Customers' own CRAs first, then the even split.
        [$owned, $rest] = $pending->partition(fn (Lead $lead) => $ownerOf($lead) !== null);
        $handedOut = [];
        $assigned = 0;

        foreach ($owned->concat($rest) as $lead) {
            $owner = $ownerOf($lead);
            // Else the least-loaded CRA whose niche has the product; ties go to the earliest CRA so the split is stable.
            $open = array_filter($load, fn (int $total, int $craId) => $niches->allows($craId, $lead->product_name), ARRAY_FILTER_USE_BOTH);
            $craId = $owner ?? ($open === [] ? null : array_search(min($open), $open, true));

            if ($craId === null) {
                continue;
            }

            $lead->update(['assigned_to' => $craId, 'assigned_at' => now()]);
            $load[$craId]++;
            $assigned++;

            if ($owner === null && $phones[$lead->id] !== '') {
                $handedOut[$phones[$lead->id]] ??= $craId;
            }
        }

        // A High AOV / VIP customer nobody could be found for is kept with whoever got their lead.
        rescue(fn () => $highValue->claimForLeads($handedOut));

        // Their leads are tagged High Value Customers when no tag is picked yet (without a Date or Time of contact).
        $highValueLeads = $pending->filter(fn (Lead $lead) => $lead->customer_tag === null && $lead->assigned_to !== null)
            ->filter(fn (Lead $lead) => isset($highValueFlags[$phones[$lead->id]]));
        if ($highValueLeads->isNotEmpty() && array_key_exists(Lead::HIGH_VALUE_TAG, config('segmentation.customer_tags'))) {
            Lead::whereIn('id', $highValueLeads->modelKeys())->update(['customer_tag' => Lead::HIGH_VALUE_TAG]);
        }

        return $assigned;
    }

    /**
     * Each number's own CRAs: their High AOV / VIP CRA and the CRA who has handled two of their orders (CustomerOwners).
     * ownerFor() picks between them for a lead.
     *
     * @param  list<string>  $phoneKeys
     * @return array{high_value: array<string, int>, handled: array<string, int>} phone key => CRA id
     */
    public function ownersFor(array $phoneKeys, ?HighValueCustomers $highValue = null): array
    {
        $highValue ??= app(HighValueCustomers::class);

        return [
            'high_value' => rescue(fn () => $highValue->crasFor($phoneKeys), []),
            'handled' => rescue(fn () => app(CustomerOwners::class)->for($phoneKeys), []),
        ];
    }

    /**
     * A lead's own CRA: the customer's High AOV / VIP CRA whatever the product, else the CRA who has handled two of
     * their orders when the product is in that CRA's niche (Settings → Niche).
     *
     * @param  array{high_value: array<string, int>, handled: array<string, int>}  $owners  from ownersFor()
     */
    public static function ownerFor(array $owners, string $phoneKey, ?string $product, NicheRules $niches): ?int
    {
        $handled = $owners['handled'][$phoneKey] ?? null;

        return $owners['high_value'][$phoneKey] ?? ($handled !== null && $niches->allows($handled, $product) ? $handled : null);
    }

    /**
     * Move $date's own leads (out of stock that day, not backlog moved in) that nobody has touched or transferred to
     * their customer's CRA when another CRA has them.
     *
     * @param  list<int>  $craIds  the active CRAs
     */
    private function reclaimForOwners(CarbonImmutable $date, array $craIds): void
    {
        $leads = Lead::whereDate('listed_on', $date)->whereDate('est_out_of_stock_date', $date)
            ->whereNotNull('assigned_to')->untouched()->withoutPosSales()->doesntHave('transfers')
            ->get(['id', 'phone_number', 'product_name', 'assigned_to']);
        $owners = $this->ownersFor($leads->map(fn (Lead $lead) => self::normalizePhone((string) $lead->phone_number))->unique()->values()->all());
        $niches = NicheRules::current();

        foreach ($leads as $lead) {
            $owner = self::ownerFor($owners, self::normalizePhone((string) $lead->phone_number), $lead->product_name, $niches);

            if ($owner !== null && $owner !== $lead->assigned_to && in_array($owner, $craIds, true)) {
                $lead->update(['assigned_to' => $owner, 'assigned_at' => now()]);
            }
        }
    }

    /**
     * After Settings → Niche is saved: a CRA's untouched leads (nobody worked or transferred them) of a product outside
     * their niche leave them, unless the customer is their High AOV / VIP customer. Worked leads stay where they are.
     * Today's go to a CRA whose niche has the product now (assign()); earlier days' wait unassigned for the backlog
     * fill, kept on their day's total (LeadMove) so past days don't change.
     *
     * @return int leads released
     */
    public function releaseOutsideNiches(CarbonImmutable $today): int
    {
        $niches = NicheRules::current();

        if (! $niches->active()) {
            return 0;
        }

        $leads = Lead::whereIn('assigned_to', $niches->craIds())->untouched()->withoutPosSales()->doesntHave('transfers')
            ->get(['id', 'phone_number', 'product_name', 'assigned_to', 'listed_on'])
            ->reject(fn (Lead $lead) => $niches->allows($lead->assigned_to, $lead->product_name));
        $highValue = rescue(fn () => app(HighValueCustomers::class)->crasFor($leads->map(fn (Lead $lead) => self::normalizePhone((string) $lead->phone_number))->unique()->values()->all()), []);
        $leads = $leads->reject(fn (Lead $lead) => ($highValue[self::normalizePhone((string) $lead->phone_number)] ?? null) === $lead->assigned_to);

        DB::transaction(function () use ($leads, $today) {
            foreach ($leads->filter(fn (Lead $lead) => $lead->listed_on->lt($today))->chunk(500) as $chunk) {
                LeadMove::insert($chunk->map(fn (Lead $lead) => [
                    'lead_id' => $lead->id, 'assigned_to' => $lead->assigned_to, 'listed_on' => $lead->listed_on->toDateString(),
                    'moved_to' => $today->toDateString(), 'created_at' => now(), 'updated_at' => now(),
                ])->values()->all());
            }
            foreach ($leads->pluck('id')->chunk(500) as $ids) {
                Lead::whereIn('id', $ids->all())->update(['assigned_to' => null, 'assigned_at' => null]);
            }
        });

        $this->assign($today);

        return $leads->count();
    }

    /**
     * Active users holding the CRA role, in the order every module lists them (User::scopeInCraOrder()).
     *
     * @return Collection<int, User>
     */
    public static function cras(): Collection
    {
        return User::whereHas('role', fn ($q) => $q->where('slug', Role::CRA))
            ->where('is_active', true)
            ->inCraOrder()
            ->get();
    }

    /**
     * Each active CRA's load right now: today's assigned leads and their
     * unprocessed backlog. Shown before transferring a backlog.
     *
     * @return list<array{id: int, name: string, today: int, backlog: int}>
     */
    public static function workload(?CarbonImmutable $today = null): array
    {
        $today ??= Lead::today();
        $cras = self::cras();
        $ids = $cras->pluck('id');

        $todayCounts = Lead::whereDate('listed_on', $today)->whereIn('assigned_to', $ids)
            ->selectRaw('assigned_to, count(*) as total')->groupBy('assigned_to')->pluck('total', 'assigned_to');
        $backlogCounts = Lead::carryOver($today)->whereIn('assigned_to', $ids)
            ->selectRaw('assigned_to, count(*) as total')->groupBy('assigned_to')->pluck('total', 'assigned_to');

        return $cras->map(fn (User $cra) => [
            'id' => $cra->id,
            'name' => $cra->displayName(),
            'today' => (int) ($todayCounts[$cra->id] ?? 0),
            'backlog' => (int) ($backlogCounts[$cra->id] ?? 0),
        ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        // 09xx…, 639xx… and 9xx… all refer to the same PH mobile number.
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /**
     * @param  Collection<string, int>  $ordersPerPhone
     */
    private function toLead(array $row, ProductCatalog $catalog, Collection $ordersPerPhone): ?array
    {
        // The logistics API computes the out-of-stock date; it is used as given.
        if (empty($row['order_id']) || empty($row['delivered_date']) || empty($row['estimated_out_of_stock_date'])) {
            return null;
        }

        $raw = trim($row['product_name'] ?? '');

        // Only products on the Product Consumption list (on the delivered day) are CRD's to call.
        $deliveredOn = substr((string) $row['delivered_date'], 0, 10);
        if ($catalog->unlistedText($raw, $deliveredOn)) {
            return null;
        }

        $product = $catalog->match($raw, $deliveredOn);

        $delivered = CarbonImmutable::parse($row['delivered_date'])->startOfDay();
        $phone = self::normalizePhone($row['phone_number'] ?? '');

        return [
            'order_id' => (string) $row['order_id'],
            'tracking_number' => $row['tracking_number'] ?: null,
            'customer_name' => trim($row['customer_name'] ?? ''),
            'phone_number' => (string) ($row['phone_number'] ?? ''),
            'product_name' => $product?->name ?? $raw,
            'product_raw' => $raw,
            'qty' => max(1, (int) ($row['qty'] ?? 1)),
            'delivered_date' => $delivered->toDateString(),
            'consumption_days' => (int) ($row['consumption_days_per_unit'] ?? 0),
            'est_out_of_stock_date' => CarbonImmutable::parse($row['estimated_out_of_stock_date'])->toDateString(),
            'lead_type' => $row['lead_type']
                ?? (($ordersPerPhone[$phone] ?? 0) >= config('segmentation.crd_lead_min_orders') ? Lead::TYPE_CRD : Lead::TYPE_FSD),
            'qty_unknown' => (bool) ($row['qty_unknown'] ?? false),
        ];
    }

    /**
     * `php artisan serve` (PHP's built-in server) keeps the browser waiting until deferred work
     * finishes, so local pages would hang for minutes; `php artisan schedule:work` syncs instead.
     */
    private static function isBuiltInServer(): bool
    {
        return PHP_SAPI === 'cli-server';
    }
}
