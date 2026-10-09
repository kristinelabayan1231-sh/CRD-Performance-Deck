<?php

namespace App\Services;

use App\Models\CustomerHistory;
use App\Models\LogisticsOrder;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Customer Database: every customer with an FSD- or CRD-delivered order in the
 * logistics retention report since config customers.delivered_from, one row
 * per contact number (last 10 digits).
 *
 * QTY = delivered orders. Total spent = their Pancake POS totals.
 * An order is handled by a CRA when logistics lists it as CRD-delivered or its
 * POS seller is a CRD account (config customers.crd_accounts, past CRAs too) or a
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

    /** @var Collection<string, User>|null */
    private ?Collection $craAccounts = null;

    /**
     * One page of customers.
     *
     * @param  array{from?: ?CarbonImmutable, to?: ?CarbonImmutable, search?: ?string, segment?: ?string, sort?: ?string}  $filters
     */
    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
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

        return $query->orderBy('lo.phone_key')->paginate($perPage)->withQueryString();
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
     * @return array{name: string, phone: string, segment: ?string, purchases: int, cra_orders: int, total_spent: float, statuses: list<array{label: string, classes: string, orders: int, amount: float, unpriced: int}>, products: list<array{name: string, units: int, srp: ?float, spent: ?float, cltv: ?float, progress: ?float, reached: bool}>, orders: list<array<string, mixed>>}|null
     */
    public function profile(string $phoneKey): ?array
    {
        $delivered = LogisticsOrder::covered()->where('phone_key', $phoneKey)->orderByDesc('delivered_date')->get()->keyBy('order_id');

        if ($delivered->isEmpty()) {
            return null;
        }

        // The delivered orders, plus their other POS orders (pending, canceled…) placed since the backfill start.
        $pos = PancakeOrder::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('phone_key', $phoneKey)->whereDate('ordered_on', '>=', config('customers.backfill_from')))
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
                'by_cra' => $logistics?->team === LogisticsOrder::TEAM_CRD || $cra !== null || PancakeOrder::isCrdAccount($order->seller_name),
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
        $history = CustomerHistory::firstWhere('phone_key', $phoneKey);
        $craOrders = $deliveredOrders->where('by_cra', true)->count();
        $latest = $delivered->first();

        return [
            'name' => $latest->customer_name ?: 'Unknown',
            'phone' => $latest->phone_number,
            'segment' => self::segmentFor($craOrders + ($history->prior_cra_orders ?? 0), $craOrders),
            'purchases' => $deliveredOrders->count(),
            'cra_orders' => $craOrders,
            // CRA-handled orders before the database's first day; null until Pancake has been checked.
            'prior_cra_orders' => $history?->prior_cra_orders,
            'prior_last_ordered_on' => $history?->prior_last_ordered_on,
            'total_spent' => (float) $deliveredOrders->sum('amount'),
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
            'products' => $this->productCltv($orders->whereIn('status', config('customers.delivered_statuses'))),
            'orders' => $orders->map(fn (array $o) => [
                ...$o,
                'status_label' => $statuses[$o['status']][0] ?? 'Status '.($o['status'] ?? '—'),
                'status_classes' => $statuses[$o['status']][1] ?? 'bg-canvas text-muted',
            ])->all(),
        ];
    }

    /**
     * Contact numbers with a CRA-handled delivery whose earlier history hasn't been checked in Pancake yet.
     *
     * @return list<string>
     */
    public function uncheckedHistories(int $limit): array
    {
        [$total] = $this->craOrdersSql(null, null, withPrior: false);

        return $this->grouped([])
            ->whereNotExists(fn ($q) => $q->from('customer_histories')->whereColumn('customer_histories.phone_key', 'lo.phone_key'))
            ->havingRaw("{$total['sql']} >= 1", $total['bindings'])
            ->orderByDesc('last_delivered')
            ->limit($limit)
            ->pluck('phone_key')->all();
    }

    /**
     * Search each customer's whole order history in Pancake and save how many CRA-handled orders
     * were delivered before the database's first day. Numbers whose search fails are retried later.
     *
     * @param  list<string>  $phoneKeys
     * @return int customers checked
     */
    public function checkHistories(array $phoneKeys, PancakeClient $client): int
    {
        $before = CarbonImmutable::parse(config('customers.delivered_from'));
        $delivered = config('customers.delivered_statuses');
        $now = now();

        $rows = collect($client->ordersByPhone($phoneKeys))->map(function (array $orders, string $phone) use ($before, $delivered, $now) {
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
            'checked' => (clone $crd)->whereExists(fn ($q) => $q->from('customer_histories')->whereColumn('customer_histories.phone_key', 'c.phone_key'))->count(),
            'total' => $crd->count(),
        ];
    }

    /**
     * Whether a seller (staff key) is a CRA's Pancake account or one of the CRD team's accounts.
     */
    private function isCraSeller(?string $seller): bool
    {
        return $seller !== null && ($this->craAccounts()->has($seller) || PancakeOrder::isCrdAccount($seller));
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
     * Units bought of each Product Consumption product, valued at its SRP, against its CLTV (SRP × 30).
     *
     * @param  Collection<int, array<string, mixed>>  $orders  delivered orders
     * @return list<array{name: string, units: int, srp: ?float, spent: ?float, cltv: ?float, progress: ?float, reached: bool}>
     */
    private function productCltv(Collection $orders): array
    {
        $catalog = new ProductCatalog;
        $units = [];
        $products = [];

        foreach ($orders as $order) {
            foreach ($order['items'] as $item) {
                $product = $catalog->match((string) ($item['name'] ?? ''));

                if ($product) {
                    $products[$product->id] = $product;
                    $units[$product->id] = ($units[$product->id] ?? 0) + (int) ($item['qty'] ?? 1);
                }
            }
        }

        return collect($products)->map(function (Product $product) use ($units) {
            $srp = $product->srp === null ? null : (float) $product->srp;
            $spent = $srp === null ? null : $units[$product->id] * $srp;
            $cltv = $product->cltv();

            return [
                'name' => $product->name,
                'units' => $units[$product->id],
                'srp' => $srp,
                'spent' => $spent,
                'cltv' => $cltv,
                'progress' => $cltv ? min(1, $spent / $cltv) : null,
                'reached' => $cltv !== null && $spent >= $cltv,
            ];
        })->sortByDesc('progress')->values()->all();
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
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('logistics_orders as lo')
            ->leftJoin('pancake_orders as po', 'po.pancake_order_id', '=', 'lo.order_id')
            // One row per customer: their CRA-handled orders before the first covered day.
            ->leftJoin('customer_histories as ch', 'ch.phone_key', '=', 'lo.phone_key')
            ->where('lo.delivered_date', '>=', LogisticsOrder::coveredFrom())
            ->when($from || $search !== '', fn (Builder $q) => $q->whereIn('lo.phone_key', $this->matching($from, $to, $search)))
            ->groupBy('lo.phone_key')
            ->select('lo.phone_key')
            ->selectRaw('max(lo.customer_name) as customer_name')
            ->selectRaw('max(lo.phone_number) as phone_number')
            ->selectRaw('count(*) as purchases')
            ->selectRaw('coalesce(sum(po.total_price), 0) as total_spent')
            ->selectRaw('max(lo.delivered_date) as last_delivered')
            ->selectRaw("{$total['sql']} as cra_orders", $total['bindings'])
            ->selectRaw("{$inPeriod['sql']} as cra_in_period", $inPeriod['bindings']);
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
        $accounts = array_values(array_unique([...$this->craAccounts()->keys()->all(), ...PancakeOrder::crdAccounts()]));
        $bySeller = $accounts ? ' or po.seller_name in ('.implode(', ', array_fill(0, count($accounts), '?')).')' : '';
        $handled = "(lo.team = '".LogisticsOrder::TEAM_CRD."'{$bySeller})";
        $count = fn (string $when, array $dates) => [
            'sql' => "sum(case when {$handled}{$when} then 1 else 0 end)",
            'bindings' => [...$accounts, ...$dates],
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
}
