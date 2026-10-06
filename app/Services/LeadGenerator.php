<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LeadGenerator
{
    public function __construct(private ShecomClient $client) {}

    /**
     * Pull customers whose product runs out on $date, store them as leads and
     * assign them to CRAs.
     *
     * @return array{found: int, created: int, crd: int, new: int, assigned: int, unassigned: int}
     */
    public function generate(CarbonImmutable $date): array
    {
        $rows = $this->client->retentionStockouts();
        $date = $date->startOfDay();

        // Customers with enough delivered orders are CRD Leads (see segmentation.crd_lead_min_orders).
        $ordersPerPhone = collect($rows)->countBy(fn (array $row) => self::normalizePhone($row['phone_number'] ?? ''));

        // Product names are grouped under Settings → Product Consumption products.
        $catalog = new ProductCatalog;

        $leads = collect($rows)
            ->map(fn (array $row) => $this->toLead($row, $catalog, $ordersPerPhone))
            ->filter(fn (?array $lead) => $lead && $lead['est_out_of_stock_date'] === $date->toDateString())
            ->values();

        $created = 0;

        DB::transaction(function () use ($leads, &$created) {
            foreach ($leads as $data) {
                $lead = Lead::firstOrNew(['order_id' => $data['order_id']]);
                $created += $lead->exists ? 0 : 1;
                // Refresh order details but keep any assignment and status already set.
                $lead->fill($data)->save();
            }
        });

        $assigned = $this->assign($date);

        $forDay = Lead::whereDate('est_out_of_stock_date', $date)->get();

        $result = [
            'found' => $leads->count(),
            'created' => $created,
            'crd' => $forDay->where('lead_type', Lead::TYPE_CRD)->count(),
            'new' => $forDay->where('lead_type', Lead::TYPE_NEW)->count(),
            'assigned' => $assigned,
            'unassigned' => $forDay->whereNull('assigned_to')->count(),
        ];

        Cache::put(self::syncKey($date), ['at' => now()->toIso8601String(), ...$result], now()->addDays(40));

        return $result;
    }

    /**
     * Sync $date unless it was synced within $maxAgeMinutes. Returns the sync
     * result, or null when the last sync is still fresh or another sync is
     * already running.
     */
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
     * Hand out the day's unassigned leads, CRD Leads first, always to the CRA
     * with the fewest leads that day. Everyone fills toward the daily quota
     * together, and any excess beyond the quota is spread evenly the same way.
     */
    public function assign(CarbonImmutable $date): int
    {
        $cras = self::cras();

        if ($cras->isEmpty()) {
            return 0;
        }

        $load = Lead::whereDate('est_out_of_stock_date', $date)
            ->whereIn('assigned_to', $cras->pluck('id'))
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $load = $cras->mapWithKeys(fn (User $cra) => [$cra->id => (int) ($load[$cra->id] ?? 0)])->all();

        $pending = Lead::whereDate('est_out_of_stock_date', $date)
            ->whereNull('assigned_to')
            ->orderByRaw('lead_type = ? desc', [Lead::TYPE_CRD])
            ->orderBy('delivered_date')
            ->orderBy('id')
            ->get();

        $assigned = 0;

        foreach ($pending as $lead) {
            // Least-loaded CRA; ties go to the earliest CRA so the split is stable.
            $craId = array_search(min($load), $load, true);

            $lead->update(['assigned_to' => $craId, 'assigned_at' => now()]);
            $load[$craId]++;
            $assigned++;
        }

        return $assigned;
    }

    /**
     * Active users holding the CRA role, in a stable order.
     *
     * @return Collection<int, User>
     */
    public static function cras(): Collection
    {
        return User::whereHas('role', fn ($q) => $q->where('slug', Role::CRA))
            ->where('is_active', true)
            ->orderBy('id')
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

        $todayCounts = Lead::whereDate('est_out_of_stock_date', $today)->whereIn('assigned_to', $ids)
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
        $product = $catalog->match($raw);

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
            'lead_type' => ($ordersPerPhone[$phone] ?? 0) >= config('segmentation.crd_lead_min_orders') ? Lead::TYPE_CRD : Lead::TYPE_NEW,
        ];
    }
}
