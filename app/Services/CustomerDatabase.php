<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\CustomerHistory;
use App\Models\CustomerLink;
use App\Models\LogisticsOrder;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Customer Database: every customer with an FSD- or CRD-delivered order in the
 * logistics retention report since config customers.delivered_from, one row
 * per contact number (last 10 digits), or per customer once numbers are merged
 * (customer_links: a number filed under the customer's main number).
 *
 * QTY = delivered orders. Total spent = Pancake POS totals of their CRA-handled delivered orders.
 * An order is handled by a CRA when logistics lists it as CRD-delivered or its
 * POS seller is a CRD account (Settings → Pancake Accounts, past CRAs too) or a
 * CRA's Pancake account. Within a period (by delivered date):
 * CRD Lead = a CRA-handled order in the period; Retained = it is their only
 * CRA-handled order so far; Repeat Customer = they had an earlier one too.
 * Over all time that is one CRA-handled order vs two or more.
 */
class CustomerDatabase
{
    public const SEGMENTS = [
        'crd' => 'CRD Leads',
        'retained' => 'Retained',
        'repeat' => 'Repeat Customers',
        'fsd' => 'FSD only',
    ];

    public const SORTS = [
        'spent' => 'Total spent',
        'purchases' => 'QTY',
        'recent' => 'Last delivered',
        'name' => 'Name',
    ];

    /** Names too generic to suggest two customers are the same person. */
    public const UNMATCHED_NAMES = ['', 'unknown', 'facebook user', 'customer', 'n/a', 'na'];

    /** SQL for a delivery's customer: the main number it is merged under, else its own (needs `cl` joined). */
    private const CUSTOMER_KEY = 'coalesce(cl.primary_phone_key, lo.phone_key)';

    /** Cache key prefix: a number whose earlier-history lookup failed, retried after a few hours. */
    public const HISTORY_FAILED = 'customers.history_failed.';

    /** @var Collection<string, User>|null */
    private ?Collection $craAccounts = null;

    /** @var list<string>|null */
    private ?array $crdAccounts = null;

    /**
     * Results are kept this long; every page and tile works through all the deliveries.
     */
    public const CACHE_MINUTES = 3;

    /**
     * Key for a cached Customer Database result; flushCache() makes every earlier one unused.
     */
    public static function cacheKey(string $name, array $parts): string
    {
        return 'customers.'.Cache::get('customers.cache_version', 0).'.'.$name.'.'.md5(serialize($parts));
    }

    /**
     * Drop every cached list page and count (after a change that moves customers between groups).
     */
    public static function flushCache(): void
    {
        Cache::forever('customers.cache_version', Cache::get('customers.cache_version', 0) + 1);
    }

    /**
     * counts(), kept for CACHE_MINUTES.
     *
     * @param  array{from?: ?CarbonImmutable, to?: ?CarbonImmutable, search?: ?string}  $filters
     * @return array{all: int, crd: int, retained: int, repeat: int}
     */
    public function cachedCounts(array $filters): array
    {
        $dates = fn (?CarbonImmutable $day) => $day?->toDateString();

        return Cache::remember(self::cacheKey('counts', [$dates($filters['from'] ?? null), $dates($filters['to'] ?? null), $filters['search'] ?? null]),
            now()->addMinutes(self::CACHE_MINUTES), fn () => $this->counts($filters));
    }

    /**
     * One page of customers, kept for CACHE_MINUTES. The total comes from the tile counts
     * already worked out, which saves a second pass over every delivery.
     *
     * @param  array{from?: ?CarbonImmutable, to?: ?CarbonImmutable, search?: ?string, segment?: ?string, sort?: ?string}  $filters
     * @param  array{all: int, crd: int, retained: int, repeat: int}  $counts
     */
    public function cachedList(array $filters, array $counts, int $perPage = 25): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();
        $total = match ($filters['segment'] ?? null) {
            'crd' => $counts['crd'],
            'retained' => $counts['retained'],
            'repeat' => $counts['repeat'],
            'fsd' => $counts['all'] - $counts['crd'],
            default => $counts['all'],
        };

        // Kept as plain arrays: the cache doesn't unserialize objects.
        $key = [($filters['from'] ?? null)?->toDateString(), ($filters['to'] ?? null)?->toDateString(), $filters['search'] ?? null, $filters['segment'] ?? null, $filters['sort'] ?? null, $perPage, $page];
        $rows = collect(Cache::remember(self::cacheKey('list', $key), now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->withNumbers($this->listQuery($filters)->forPage($page, $perPage)->get()->map(fn (object $row) => (array) $row))->all()))
            ->map(fn (array $row) => (object) $row);

        return (new LengthAwarePaginator($rows, $total, $perPage, $page, ['path' => Paginator::resolveCurrentPath()]))->withQueryString();
    }

    /**
     * Customers matching the filters, in the order picked.
     *
     * @param  array{from?: ?CarbonImmutable, to?: ?CarbonImmutable, search?: ?string, segment?: ?string, sort?: ?string}  $filters
     */
    private function listQuery(array $filters): Builder
    {
        $query = $this->grouped($filters);
        [$total, $inPeriod] = $this->craOrdersSql($filters['from'] ?? null, $filters['to'] ?? null);
        $having = fn (string $sql, array ...$bindings) => $query->havingRaw($sql, array_merge(...$bindings));

        match ($filters['segment'] ?? null) {
            'crd' => $having("{$inPeriod['sql']} >= 1", $inPeriod['bindings']),
            'retained' => $having("{$inPeriod['sql']} >= 1 and {$total['sql']} = 1", $inPeriod['bindings'], $total['bindings']),
            'repeat' => $having("{$inPeriod['sql']} >= 1 and {$total['sql']} >= 2", $inPeriod['bindings'], $total['bindings']),
            'fsd' => $having("{$inPeriod['sql']} = 0", $inPeriod['bindings']),
            default => null,
        };

        match ($filters['sort'] ?? 'spent') {
            'purchases' => $query->orderByDesc('purchases')->orderByDesc('total_spent'),
            'recent' => $query->orderByDesc('last_delivered')->orderByDesc('total_spent'),
            'name' => $query->orderBy('customer_name'),
            default => $query->orderByDesc('total_spent')->orderByDesc('purchases'),
        };

        return $query->orderBy('phone_key');
    }

    /**
     * Each listed customer's contact numbers (main number first) and how many other customers
     * share their name (a possible same customer to merge).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withNumbers(Collection $rows): Collection
    {
        $keys = $rows->pluck('phone_key')->map(fn ($key) => (string) $key)->all();
        $members = CustomerLink::whereIn('primary_phone_key', $keys)->orderBy('id')->get()->groupBy('primary_phone_key');
        $numbers = $this->phoneNumbers([...$keys, ...$members->flatten()->pluck('phone_key')->all()]);
        $matches = $this->sameNameCustomers($rows->pluck('customer_name')->all());

        return $rows->map(function (array $row) use ($members, $numbers, $matches) {
            $key = (string) $row['phone_key'];
            $own = [$key, ...($members[$key] ?? collect())->pluck('phone_key')->all()];

            return [
                ...$row,
                'phone_numbers' => array_map(fn (string $phone) => $numbers[$phone] ?? $phone, $own),
                'possible_matches' => count(array_diff($matches[self::nameKey($row['customer_name'])] ?? [], [$key])),
            ];
        });
    }

    /**
     * Each number's contact number as last delivered to.
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, string>
     */
    private function phoneNumbers(array $phoneKeys): array
    {
        return LogisticsOrder::whereIn('phone_key', array_values(array_unique($phoneKeys)))
            ->orderBy('delivered_date')->pluck('phone_number', 'phone_key')->all();
    }

    /**
     * Customers (main numbers) per name, for the names given; generic names are left out.
     *
     * @param  list<?string>  $names
     * @return array<string, list<string>> name key => customer keys
     */
    private function sameNameCustomers(array $names): array
    {
        $names = array_values(array_diff(array_unique(array_map(fn (?string $name) => self::nameKey($name), $names)), self::UNMATCHED_NAMES));

        if ($names === []) {
            return [];
        }

        return DB::table('logistics_orders as lo')
            ->leftJoin('customer_links as cl', 'cl.phone_key', '=', 'lo.phone_key')
            ->whereIn(DB::raw('lower(trim(lo.customer_name))'), $names)
            ->where('lo.delivered_date', '>=', LogisticsOrder::coveredFrom())
            ->distinct()
            ->selectRaw('lower(trim(lo.customer_name)) as name_key')
            ->selectRaw(self::CUSTOMER_KEY.' as customer_key')
            ->get()
            ->groupBy('name_key')
            ->map(fn (Collection $rows) => $rows->pluck('customer_key')->map(fn ($key) => (string) $key)->unique()->values()->all())
            ->all();
    }

    /**
     * A customer name as compared for possible matches: trimmed, lower case, single spaces.
     */
    public static function nameKey(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $name)));
    }

    /**
     * Customers, CRD Leads, Retained and Repeat Customers for the same period and search.
     *
     * @param  array{from?: ?CarbonImmutable, to?: ?CarbonImmutable, search?: ?string}  $filters
     * @return array{all: int, crd: int, retained: int, repeat: int}
     */
    public function counts(array $filters): array
    {
        $row = DB::query()->fromSub($this->grouped($filters), 'customers')
            ->selectRaw('count(*) as all_customers')
            ->selectRaw('coalesce(sum(case when cra_in_period >= 1 then 1 else 0 end), 0) as crd')
            ->selectRaw('coalesce(sum(case when cra_in_period >= 1 and cra_orders = 1 then 1 else 0 end), 0) as retained')
            ->selectRaw('coalesce(sum(case when cra_in_period >= 1 and cra_orders >= 2 then 1 else 0 end), 0) as repeat_customers')
            ->first();

        return [
            'all' => (int) $row->all_customers,
            'crd' => (int) $row->crd,
            'retained' => (int) $row->retained,
            'repeat' => (int) $row->repeat_customers,
        ];
    }

    /**
     * Everything the customer pop-up shows, or null for an unknown contact number.
     *
     * @return array{phone_key: string, name: string, phone: string, numbers: list<array{phone_key: string, phone: string, main: bool}>, possible_matches: list<array{phone_key: string, phone: string, purchases: int, last_delivered: ?string}>, segment: ?string, purchases: int, cra_orders: int, total_spent: float, statuses: list<array{label: string, classes: string, orders: int, amount: float, unpriced: int}>, products: list<array{name: string, units: int, srp: ?float, spent: ?float, cltv: ?float, progress: ?float, reached: bool}>, orders: list<array<string, mixed>>}|null
     */
    public function profile(string $phoneKey): ?array
    {
        // A merged customer: every one of their numbers, main number first.
        $primary = CustomerLink::primaryFor($phoneKey);
        $members = CustomerLink::membersOf($primary);
        $delivered = LogisticsOrder::covered()->whereIn('phone_key', $members)->orderByDesc('delivered_date')->get()->keyBy('order_id');

        if ($delivered->isEmpty()) {
            return null;
        }

        // The delivered orders, plus their other POS orders (pending, canceled…) placed since the backfill start.
        $pos = PancakeOrder::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('phone_key', $members)->whereDate('ordered_on', '>=', config('customers.backfill_from')))
                ->orWhereIn('pancake_order_id', $delivered->keys()->all()))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 7))
            ->get()
            ->keyBy('pancake_order_id');

        $statuses = config('customers.pos_statuses');
        $accounts = $this->craAccounts();

        $orders = $pos->map(function (PancakeOrder $order) use ($delivered, $accounts) {
            $logistics = $delivered->get($order->pancake_order_id);
            $cra = $order->seller_name ? $accounts->get($order->seller_name) : null;

            return [
                'order_id' => $order->pancake_order_id,
                'date' => $order->ordered_on,
                'delivered_on' => $logistics?->delivered_date,
                'status' => $order->status,
                'amount' => (float) $order->total_price,
                'team' => $logistics?->team,
                'seller' => $cra?->displayName() ?? ($order->seller_name ? ucwords(strtolower($order->seller_name)) : null),
                'by_cra' => $cra !== null || $this->handledByCra($logistics?->team, $order->seller_name),
                'items' => $order->items ?? [],
            ];
        });

        // Delivered orders Pancake has no copy of yet (before the POS backfill reached them).
        foreach ($delivered->reject(fn (LogisticsOrder $order) => $pos->has($order->order_id)) as $order) {
            $orders[$order->order_id] = [
                'order_id' => $order->order_id,
                'date' => null,
                'delivered_on' => $order->delivered_date,
                'status' => 3,
                'amount' => null,
                'team' => $order->team,
                'seller' => null,
                'by_cra' => $order->team === LogisticsOrder::TEAM_CRD,
                'items' => [['name' => $order->product, 'qty' => $order->qty ?? 1]],
            ];
        }

        $orders = $orders->sortByDesc(fn (array $o) => ($o['date'] ?? $o['delivered_on'])?->toDateString())->values();
        $deliveredOrders = $orders->whereNotNull('delivered_on');
        $histories = CustomerHistory::whereIn('phone_key', $members)->get();
        $prior = $histories->isEmpty() ? null : (int) $histories->sum('prior_cra_orders');
        $craOrders = $deliveredOrders->where('by_cra', true)->count();
        $latest = $delivered->first();
        $numbers = $this->phoneNumbers($members);

        return [
            'phone_key' => $primary,
            'name' => $latest->customer_name ?: 'Unknown',
            'phone' => implode(' / ', array_map(fn (string $phone) => $numbers[$phone] ?? $phone, $members)),
            // Each number, so a merged one can be separated again.
            'numbers' => array_map(fn (string $phone) => ['phone_key' => $phone, 'phone' => $numbers[$phone] ?? $phone, 'main' => $phone === $primary], $members),
            'possible_matches' => $this->possibleMatches($primary, $latest->customer_name),
            'segment' => self::segmentFor($craOrders + ($prior ?? 0), $craOrders),
            'purchases' => $deliveredOrders->count(),
            'cra_orders' => $craOrders,
            // CRA-handled orders before the database's first day; null until Pancake has been checked.
            'prior_cra_orders' => $prior,
            'prior_last_ordered_on' => $histories->max('prior_last_ordered_on'),
            // CRA-handled orders only, like the product CLTV and VIP below.
            'total_spent' => (float) $deliveredOrders->where('by_cra', true)->sum('amount'),
            'statuses' => collect($statuses)
                ->map(function (array $status, int $code) use ($orders) {
                    $inStatus = $orders->where('status', $code);

                    return [
                        'label' => $status[0],
                        'classes' => $status[1],
                        'orders' => $inStatus->count(),
                        'amount' => (float) $inStatus->sum('amount'),
                        'unpriced' => $inStatus->whereNull('amount')->count(),
                    ];
                })
                ->filter(fn (array $row) => $row['orders'] > 0)
                ->values()->all(),
            'products' => $this->productCltv($orders->whereIn('status', config('customers.delivered_statuses'))->where('by_cra', true)),
            'orders' => $orders->map(fn (array $o) => [
                ...$o,
                'status_label' => $statuses[$o['status']][0] ?? 'Status '.($o['status'] ?? '—'),
                'status_classes' => $statuses[$o['status']][1] ?? 'bg-canvas text-muted',
            ])->all(),
        ];
    }

    /**
     * Other customers with the same name as this one, to confirm as the same person: their main number,
     * contact numbers, deliveries and last delivery. Generic names (Unknown, Facebook User…) suggest nobody.
     *
     * @return list<array{phone_key: string, phone: string, purchases: int, last_delivered: ?string}>
     */
    public function possibleMatches(string $primary, ?string $name): array
    {
        $others = array_values(array_diff(($this->sameNameCustomers([$name]))[self::nameKey($name)] ?? [], [$primary]));

        if ($others === []) {
            return [];
        }

        $members = CustomerLink::whereIn('primary_phone_key', $others)->get()->groupBy('primary_phone_key');
        $numbers = $this->phoneNumbers([...$others, ...$members->flatten()->pluck('phone_key')->all()]);
        $stats = DB::table('logistics_orders as lo')
            ->leftJoin('customer_links as cl', 'cl.phone_key', '=', 'lo.phone_key')
            ->whereIn('lo.phone_key', array_keys($numbers))
            ->where('lo.delivered_date', '>=', LogisticsOrder::coveredFrom())
            ->groupBy(DB::raw(self::CUSTOMER_KEY))
            ->selectRaw(self::CUSTOMER_KEY.' as customer_key')
            ->selectRaw('count(*) as purchases')
            ->selectRaw('max(lo.delivered_date) as last_delivered')
            ->get()->keyBy(fn (object $row) => (string) $row->customer_key);

        return collect($others)->map(fn (string $key) => [
            'phone_key' => $key,
            'phone' => implode(' / ', array_map(fn (string $phone) => $numbers[$phone] ?? $phone, [$key, ...($members[$key] ?? collect())->pluck('phone_key')->all()])),
            'purchases' => (int) ($stats[$key]->purchases ?? 0),
            'last_delivered' => $stats[$key]->last_delivered ?? null,
        ])->sortByDesc('last_delivered')->values()->all();
    }

    /**
     * File $otherKey's customer (all its numbers) under $phoneKey's customer, as the same person.
     */
    public function merge(string $phoneKey, string $otherKey, ?int $userId): void
    {
        $primary = CustomerLink::primaryFor($phoneKey);
        $other = CustomerLink::primaryFor($otherKey);

        if ($primary === $other) {
            return;
        }

        DB::transaction(function () use ($primary, $other, $userId) {
            CustomerLink::where('primary_phone_key', $other)->update(['primary_phone_key' => $primary]);
            CustomerLink::updateOrCreate(['phone_key' => $other], ['primary_phone_key' => $primary, 'linked_by' => $userId]);

            // The merged customer keeps a High AOV / VIP CRA set on either number (the main number's wins).
            if (! CustomerAccount::where('phone_key', $primary)->exists()) {
                CustomerAccount::where('phone_key', $other)->update(['phone_key' => $primary]);
            }
        });

        self::flushCache();
    }

    /**
     * Take a merged number back out of its customer, as a customer of its own again.
     */
    public function separate(string $phoneKey): void
    {
        CustomerLink::where('phone_key', $phoneKey)->delete();

        self::flushCache();
    }

    /**
     * Contact numbers with a CRA-handled delivery whose earlier history hasn't been checked in Pancake yet.
     *
     * @return list<string>
     */
    public function uncheckedHistories(int $limit): array
    {
        [$total] = $this->craOrdersSql(null, null, withPrior: false);

        // Numbers whose lookup just failed wait a few hours, so they don't hold up the rest.
        return $this->grouped([])
            ->whereNotExists(fn ($q) => $this->checkedHistory($q, 'lo.phone_key'))
            ->havingRaw("{$total['sql']} >= 1", $total['bindings'])
            ->orderByDesc('last_delivered')
            ->limit($limit * 3)
            ->pluck('phone_key')
            ->reject(fn (string $phone) => Cache::has(self::HISTORY_FAILED.$phone))
            ->take($limit)
            ->values()->all();
    }

    /**
     * Search each customer's whole order history in Pancake and save how many CRA-handled orders
     * were delivered before the database's first day, 50 customers at a time so a run cut short
     * (restart, deploy, the host sleeping) keeps what it did. Numbers whose search fails are retried
     * after a few hours.
     *
     * @param  list<string>  $phoneKeys
     * @return int customers checked
     */
    public function checkHistories(array $phoneKeys, PancakeClient $client): int
    {
        $checked = 0;

        foreach (array_chunk($phoneKeys, 50) as $chunk) {
            $found = $client->ordersByPhone($chunk);
            $checked += $this->saveHistories($found);

            foreach (array_diff($chunk, array_keys($found)) as $failed) {
                Cache::put(self::HISTORY_FAILED.$failed, true, now()->addHours(6));
            }
        }

        return $checked;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $ordersByPhone
     * @return int customers saved
     */
    private function saveHistories(array $ordersByPhone): int
    {
        $before = CarbonImmutable::parse(config('customers.delivered_from'));
        $delivered = config('customers.delivered_statuses');
        $now = now();

        $rows = collect($ordersByPhone)->map(function (array $orders, string $phone) use ($before, $delivered, $now) {
            $prior = collect($orders)->filter(function (array $order) use ($before, $delivered) {
                $seller = $order['assigning_seller'] ?? $order['creator'] ?? null;
                $orderedOn = isset($order['inserted_at'])
                    ? CarbonImmutable::parse($order['inserted_at'], 'UTC')->setTimezone(config('segmentation.timezone'))->startOfDay()
                    : null;

                return $orderedOn?->lessThan($before)
                    && in_array((int) ($order['status'] ?? -1), $delivered, true)
                    && $this->isCraSeller(isset($seller['name']) ? PancakeEngagement::staffKey($seller['name']) : null);
            });

            return [
                'phone_key' => $phone,
                'prior_cra_orders' => $prior->count(),
                'prior_last_ordered_on' => $prior->max(fn (array $o) => CarbonImmutable::parse($o['inserted_at'], 'UTC')->setTimezone(config('segmentation.timezone'))->toDateString()),
                'checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->values();

        foreach ($rows->chunk(500) as $chunk) {
            CustomerHistory::upsert($chunk->all(), ['phone_key'], ['prior_cra_orders', 'prior_last_ordered_on', 'checked_at', 'updated_at']);
        }

        return $rows->count();
    }

    /**
     * How far the earlier-history check has got: customers with a CRA-handled delivery, and how many are checked.
     *
     * @return array{checked: int, total: int}
     */
    public function historyProgress(): array
    {
        [$total] = $this->craOrdersSql(null, null, withPrior: false);
        $crd = DB::query()->fromSub($this->grouped([])->havingRaw("{$total['sql']} >= 1", $total['bindings']), 'c');

        return [
            'checked' => (clone $crd)->whereExists(fn ($q) => $this->checkedHistory($q, 'c.phone_key'))->count(),
            'total' => $crd->count(),
        ];
    }

    /**
     * The customer's saved history, counted as checked unless the CRD team accounts changed since it was
     * checked and the customer had a delivery in the month before that change (Settings → Pancake Accounts).
     */
    private function checkedHistory(Builder $query, string $phoneColumn): Builder
    {
        $recheckFrom = PancakeAccounts::historyRecheckFrom();

        return $query->from('customer_histories')->whereColumn('customer_histories.phone_key', $phoneColumn)
            ->when($recheckFrom, fn (Builder $q) => $q->where(fn (Builder $current) => $current
                ->where('customer_histories.checked_at', '>=', $recheckFrom)
                ->orWhereNotExists(fn (Builder $recent) => $recent->from('logistics_orders as recent')
                    ->whereColumn('recent.phone_key', 'customer_histories.phone_key')
                    ->where('recent.delivered_date', '>=', $recheckFrom->subMonths(PancakeAccounts::RECHECK_MONTHS)->toDateString()))));
    }

    /**
     * Whether a seller (staff key) is a CRA's Pancake account or one of the CRD team's accounts.
     */
    private function isCraSeller(?string $seller): bool
    {
        return $seller !== null && ($this->craAccounts()->has($seller) || PancakeOrder::isCrdAccount($seller, $this->crdAccounts()));
    }

    /**
     * Look up in Pancake the customer's delivered orders that have no POS copy yet (the backfill
     * hasn't reached them), so the pop-up shows every amount. Numbers Pancake doesn't have are
     * not searched again for a day.
     */
    public function fetchMissingOrders(string $phoneKey, PancakeSync $pancake): void
    {
        $missing = LogisticsOrder::covered()->where('phone_key', $phoneKey)
            ->whereNotExists(fn ($q) => $q->from('pancake_orders')->whereColumn('pancake_orders.pancake_order_id', 'logistics_orders.order_id'))
            ->pluck('order_id')
            ->reject(fn (string $id) => Cache::has("customers.not_in_pancake.{$id}"))
            ->take(50)
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        rescue(fn () => $pancake->syncOrdersByNumber($missing->all()));

        $found = PancakeOrder::whereIn('pancake_order_id', $missing)->pluck('pancake_order_id')->flip();
        $missing->reject(fn (string $id) => isset($found[$id]))
            ->each(fn (string $id) => Cache::put("customers.not_in_pancake.{$id}", true, now()->addDay()));
    }

    /**
     * "Retained" when the CRA-handled order in the period is their only one so far, "Repeat Customer"
     * when there was an earlier one too, else null (no CRA-handled order in the period).
     */
    public static function segmentFor(int $craOrders, ?int $craInPeriod = null): ?string
    {
        return match (true) {
            ($craInPeriod ?? $craOrders) < 1 => null,
            $craOrders >= 2 => 'Repeat Customer',
            default => 'Retained',
        };
    }

    /**
     * What the customer spent on each Product Consumption product, against its CLTV (SRP × 30).
     * Pancake's qty is often a package (1 × Pterygium for ₱6,000), so spend comes from order
     * amounts: a one-product order counts in full, a mixed one is split by qty. An order with
     * no amount yet (no Pancake copy) counts its units × SRP.
     *
     * @param  Collection<int, array<string, mixed>>  $orders  delivered orders with items and amount
     * @return list<array{name: string, units: int, srp: ?float, spent: ?float, cltv: ?float, progress: ?float, reached: bool}>
     */
    public function productCltv(Collection $orders): array
    {
        $catalog = new ProductCatalog;
        $units = [];
        $spent = [];
        $products = [];

        foreach ($orders as $order) {
            $items = collect($order['items'])->map(fn (array $item) => [
                'product' => $catalog->match((string) ($item['name'] ?? '')),
                'qty' => max(1, (int) ($item['qty'] ?? 1)),
            ]);
            $totalQty = $items->sum('qty');
            $amount = isset($order['amount']) ? (float) $order['amount'] : null;

            foreach ($items->whereNotNull('product') as ['product' => $product, 'qty' => $qty]) {
                $products[$product->id] = $product;
                $units[$product->id] = ($units[$product->id] ?? 0) + $qty;
                $share = $amount !== null ? $amount * $qty / $totalQty : ($product->srp === null ? 0.0 : $qty * (float) $product->srp);
                $spent[$product->id] = ($spent[$product->id] ?? 0) + $share;
            }
        }

        return collect($products)->map(function (Product $product) use ($units, $spent) {
            $cltv = $product->cltv();
            $productSpent = round($spent[$product->id], 2);

            return [
                'name' => $product->name,
                'units' => $units[$product->id],
                'srp' => $product->srp === null ? null : (float) $product->srp,
                'spent' => $productSpent,
                'cltv' => $cltv,
                'progress' => $cltv ? min(1, $productSpent / $cltv) : null,
                'reached' => $cltv !== null && $productSpent >= $cltv,
            ];
        })->sortByDesc(fn (array $p) => [$p['progress'] ?? -1, $p['spent']])->values()->all();
    }

    /**
     * Every customer with a CRA-handled delivered order (any time), one row each, with
     * phone_key, customer_name, phone_number, purchases (all deliveries), cra_delivered, total_spent and
     * priced_orders (CRA-handled only; priced = with a Pancake amount) and last_delivered.
     */
    public function craCustomers(): Builder
    {
        [$total] = $this->craOrdersSql(null, null);

        return $this->grouped([])->havingRaw("{$total['sql']} >= 1", $total['bindings']);
    }

    /**
     * One row per contact number with the period and search applied (they pick customers;
     * the totals still cover every delivered order).
     *
     * @param  array{from?: ?CarbonImmutable, to?: ?CarbonImmutable, search?: ?string}  $filters
     */
    private function grouped(array $filters): Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        [$total, $inPeriod] = $this->craOrdersSql($from, $to);
        $handled = $this->handledSql();
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('logistics_orders as lo')
            ->leftJoin('customer_links as cl', 'cl.phone_key', '=', 'lo.phone_key')
            ->leftJoin('pancake_orders as po', 'po.pancake_order_id', '=', 'lo.order_id')
            // One row per customer: their CRA-handled orders before the first covered day, all their numbers together.
            ->leftJoinSub($this->priorOrders(), 'ch', 'ch.customer_key', '=', DB::raw(self::CUSTOMER_KEY))
            ->where('lo.delivered_date', '>=', LogisticsOrder::coveredFrom())
            ->when($from || $search !== '', function (Builder $q) use ($from, $to, $search) {
                // By number (indexed), plus the other numbers of matched merged customers so their totals stay whole.
                $numbers = $this->matching($from, $to, $search);
                $linked = $this->linkedNumbers($numbers);

                $q->where(fn (Builder $q) => $q->whereIn('lo.phone_key', $numbers)->when($linked, fn (Builder $q) => $q->orWhereIn('lo.phone_key', $linked)));
            })
            ->groupBy(DB::raw(self::CUSTOMER_KEY))
            ->selectRaw(self::CUSTOMER_KEY.' as phone_key')
            ->selectRaw('max(lo.customer_name) as customer_name')
            ->selectRaw('max(lo.phone_number) as phone_number')
            ->selectRaw('count(*) as purchases')
            // Total spent counts CRA-handled orders only; priced = those with a Pancake amount.
            ->selectRaw("coalesce(sum(case when {$handled['sql']} then po.total_price end), 0) as total_spent", $handled['bindings'])
            ->selectRaw("sum(case when {$handled['sql']} and po.total_price is not null then 1 else 0 end) as priced_orders", $handled['bindings'])
            ->selectRaw("sum(case when {$handled['sql']} then 1 else 0 end) as cra_delivered", $handled['bindings'])
            ->selectRaw('max(lo.delivered_date) as last_delivered')
            ->selectRaw("{$total['sql']} as cra_orders", $total['bindings'])
            ->selectRaw("{$inPeriod['sql']} as cra_in_period", $inPeriod['bindings']);
    }

    /**
     * Each customer's CRA-handled orders before the first covered day, summed over their numbers.
     */
    private function priorOrders(): Builder
    {
        $key = 'coalesce(cl.primary_phone_key, h.phone_key)';

        return DB::table('customer_histories as h')
            ->leftJoin('customer_links as cl', 'cl.phone_key', '=', 'h.phone_key')
            ->groupBy(DB::raw($key))
            ->selectRaw("{$key} as customer_key")
            ->selectRaw('sum(h.prior_cra_orders) as prior_cra_orders');
    }

    /**
     * Every number of the merged customers that $numbers belong to (main numbers and the numbers merged under them).
     *
     * @return list<string>
     */
    private function linkedNumbers(Builder $numbers): array
    {
        if (! CustomerLink::query()->exists()) {
            return [];
        }

        $primaries = CustomerLink::whereIn('phone_key', clone $numbers)->pluck('primary_phone_key')
            ->merge(CustomerLink::whereIn('primary_phone_key', clone $numbers)->pluck('primary_phone_key'))
            ->unique()->values();

        return $primaries->merge(CustomerLink::whereIn('primary_phone_key', $primaries)->pluck('phone_key'))->unique()->values()->all();
    }

    /**
     * Contact numbers with a delivery in $from–$to that match $search (name or number).
     */
    private function matching(?CarbonImmutable $from, ?CarbonImmutable $to, string $search): Builder
    {
        $digits = preg_replace('/\D/', '', $search);
        // Numbers are matched after a leading 0 / 63, the way they are stored.
        $phone = preg_replace('/^(63|0)/', '', $digits);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return DB::table('logistics_orders')->select('phone_key')->distinct()
            ->when($from, fn (Builder $q) => $q->whereBetween('delivered_date', [$from->toDateString(), $to->toDateString()]))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereLike('customer_name', $like, caseSensitive: false)
                ->when(strlen($phone) >= 4, fn (Builder $q) => $q->orWhere('phone_key', 'like', '%'.$phone.'%'))));
    }

    /**
     * SQL counting a customer's CRA-handled delivered orders: up to the period's end (the period's
     * one and any earlier ones, including those before the first covered day found in Pancake
     * unless !$withPrior), and within the period. Without a period both cover every order.
     *
     * @return array{0: array{sql: string, bindings: list<string>}, 1: array{sql: string, bindings: list<string>}}
     */
    private function craOrdersSql(?CarbonImmutable $from, ?CarbonImmutable $to, bool $withPrior = true): array
    {
        $handled = $this->handledSql();
        $count = fn (string $when, array $dates) => [
            'sql' => "sum(case when {$handled['sql']}{$when} then 1 else 0 end)",
            'bindings' => [...$handled['bindings'], ...$dates],
        ];

        $prior = fn (array $count) => $withPrior
            ? ['sql' => "({$count['sql']} + coalesce(max(ch.prior_cra_orders), 0))", 'bindings' => $count['bindings']]
            : $count;

        if (! $from) {
            return [$prior($count('', [])), $count('', [])];
        }

        return [
            $prior($count(' and lo.delivered_date <= ?', [$to->toDateString()])),
            $count(' and lo.delivered_date between ? and ?', [$from->toDateString(), $to->toDateString()]),
        ];
    }

    /**
     * SQL for "this delivery was handled by a CRA" (needs logistics_orders `lo` and pancake_orders `po`):
     * CRD-delivered, or sold by a CRD team account or a CRA's own Pancake account.
     *
     * @return array{sql: string, bindings: list<string>}
     */
    public function handledSql(): array
    {
        $accounts = array_values(array_unique([...$this->craAccounts()->keys()->all(), ...$this->crdAccounts()]));
        $bySeller = $accounts ? ' or po.seller_name in ('.implode(', ', array_fill(0, count($accounts), '?')).')' : '';

        return ['sql' => "(lo.team = '".LogisticsOrder::TEAM_CRD."'{$bySeller})", 'bindings' => $accounts];
    }

    /**
     * Whether an order was handled by a CRA: CRD-delivered, or its Pancake seller is a CRA or CRD team account.
     */
    public function handledByCra(?string $team, ?string $seller): bool
    {
        return $team === LogisticsOrder::TEAM_CRD || ($seller !== null && $this->isCraSeller($seller));
    }

    /**
     * Every CRA's Pancake account (staff key), active or not, since their past orders still count.
     *
     * @return Collection<string, User>
     */
    private function craAccounts(): Collection
    {
        return $this->craAccounts ??= User::whereHas('role', fn ($q) => $q->where('slug', Role::CRA))
            ->whereNotNull('pancake_name')
            ->get()
            ->keyBy(fn (User $cra) => PancakeEngagement::staffKey($cra->pancake_name));
    }

    /**
     * The CRD team's accounts as staff keys, read once per use of this service.
     *
     * @return list<string>
     */
    private function crdAccounts(): array
    {
        return $this->crdAccounts ??= PancakeOrder::crdAccounts();
    }
}
