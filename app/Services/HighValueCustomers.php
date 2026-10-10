<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\CustomerLink;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Customer Database → High AOV CVR & VIP. Among CRA-handled customers (merged numbers count
 * as one): High AOV = total spent ÷ delivered orders ≥ config customers.high_aov_min; VIP =
 * delivered units of one product × its SRP reached that product's CLTV (SRP × 30).
 * Each has a CRA (assigning seller): claimed by a CRA, or, for a customer not on the
 * list yet, the CRA their Segmentation Tracker lead is handed to. Their leads then go to that CRA.
 */
class HighValueCustomers
{
    public const LISTS = [
        'all' => 'High AOV & VIP',
        'high_aov' => 'High AOV',
        'vip' => 'VIP',
    ];

    public const VIEWS = [
        'customers' => 'Per customer',
        'orders' => 'Per order',
    ];

    public function __construct(private CustomerDatabase $customers) {}

    /**
     * Every High AOV or VIP customer by main number, highest total spent first; kept for
     * CustomerDatabase::CACHE_MINUTES like the rest of the Customer Database.
     *
     * @return array<string, array{phone_key: string, customer_name: string, phone_number: string, purchases: int, total_spent: float, aov: float, last_delivered: ?string, high_aov: bool, vip: bool, vip_products: list<string>}>
     */
    public function all(): array
    {
        return Cache::remember(CustomerDatabase::cacheKey('high_value', []), now()->addMinutes(CustomerDatabase::CACHE_MINUTES), fn () => $this->build());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function build(): array
    {
        $minAov = (float) config('customers.high_aov_min');
        // Nobody can have spent a product's CLTV on it with a smaller total, so only bigger spenders are checked for VIP.
        $minSrp = Product::where('srp', '>', 0)->min('srp');
        $minCltv = $minSrp === null ? null : (float) $minSrp * (int) config('customers.cltv_units');

        $rows = DB::query()->fromSub($this->customers->craCustomers(), 'c')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('priced_orders', '>', 0)->whereRaw('total_spent >= priced_orders * ?', [$minAov]))
                ->when($minCltv !== null, fn ($q) => $q->orWhere('total_spent', '>=', $minCltv)))
            ->orderByDesc('total_spent')
            ->get();

        $vip = $minCltv === null ? [] : $this->vipProducts($rows->where('total_spent', '>=', $minCltv)->pluck('phone_key')->map(fn ($key) => (string) $key)->all());

        return $rows->mapWithKeys(function (object $row) use ($minAov, $vip) {
            $key = (string) $row->phone_key;
            // AOV over the orders Pancake has an amount for, so a missing copy doesn't pull it down.
            $aov = $row->priced_orders > 0 ? (float) $row->total_spent / $row->priced_orders : 0.0;

            return [$key => [
                'phone_key' => $key,
                'customer_name' => $row->customer_name ?: 'Unknown',
                'phone_number' => (string) $row->phone_number,
                'purchases' => (int) $row->cra_delivered,
                'total_spent' => (float) $row->total_spent,
                'aov' => $aov,
                'last_delivered' => $row->last_delivered,
                'high_aov' => $aov >= $minAov,
                'vip' => isset($vip[$key]),
                'vip_products' => $vip[$key] ?? [],
            ]];
        })->filter(fn (array $customer) => $customer['high_aov'] || $customer['vip'])->all();
    }

    /**
     * The products each customer reached the CLTV of, from their delivered orders (as in the customer pop-up).
     *
     * @param  list<string>  $keys  main numbers
     * @return array<string, list<string>> main number => product names
     */
    private function vipProducts(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $owner = $this->numbersOf($keys);
        $numbers = array_keys($owner);
        $delivered = LogisticsOrder::covered()->whereIn('phone_key', $numbers)->get(['order_id', 'team', 'phone_key', 'product', 'qty']);
        $inPancake = PancakeOrder::whereIn('pancake_order_id', $delivered->pluck('order_id'))->pluck('pancake_order_id')->flip();

        $pos = PancakeOrder::query()
            ->whereIn('status', config('customers.delivered_statuses'))
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('phone_key', $numbers)->whereDate('ordered_on', '>=', config('customers.backfill_from')))
                ->orWhereIn('pancake_order_id', $delivered->pluck('order_id')))
            ->get(['pancake_order_id', 'phone_key', 'seller_name', 'items', 'total_price']);
        $deliveredTo = $delivered->pluck('phone_key', 'order_id');
        $teams = $delivered->pluck('team', 'order_id');

        $orders = collect();
        // CRA-handled orders only.
        foreach ($pos as $order) {
            $key = $owner[$order->phone_key] ?? $owner[$deliveredTo[$order->pancake_order_id] ?? ''] ?? null;
            if ($key !== null && $this->customers->handledByCra($teams[$order->pancake_order_id] ?? null, $order->seller_name)) {
                $orders->push(['key' => $key, 'items' => $order->items ?? [], 'amount' => $order->total_price]);
            }
        }
        // Delivered orders Pancake has no copy of yet count with logistics' product and qty.
        foreach ($delivered->reject(fn (LogisticsOrder $order) => isset($inPancake[$order->order_id]) || $order->team !== LogisticsOrder::TEAM_CRD) as $order) {
            $orders->push(['key' => $owner[$order->phone_key], 'items' => [['name' => $order->product, 'qty' => $order->qty ?? 1]], 'amount' => null]);
        }

        return $orders->groupBy('key')
            ->map(fn (Collection $own) => collect($this->customers->productCltv($own))->where('reached', true)->pluck('name')->all())
            ->filter()
            ->all();
    }

    /**
     * Each number of these customers (merged ones too) => the customer's main number.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function numbersOf(array $keys): array
    {
        $owner = array_combine($keys, $keys);
        foreach (CustomerLink::whereIn('primary_phone_key', $keys)->get(['phone_key', 'primary_phone_key']) as $link) {
            $owner[$link->phone_key] = $link->primary_phone_key;
        }

        return $owner;
    }

    /**
     * The listed customers matching the filters, with their CRA and notes.
     *
     * @param  array{list?: ?string, cra?: ?string, search?: ?string}  $filters
     * @return Collection<string, array<string, mixed>>
     */
    public function filtered(array $filters, ?int $userId): Collection
    {
        $customers = collect($this->all());
        $accounts = CustomerAccount::with('cra')->whereIn('phone_key', $customers->keys())->get()->keyBy('phone_key');
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $digits = preg_replace('/^(63|0)/', '', preg_replace('/\D/', '', $search));
        $cra = $filters['cra'] ?? null;

        return $customers
            ->map(fn (array $customer) => [...$customer, 'account' => $accounts->get($customer['phone_key'])])
            ->filter(fn (array $c) => match ($filters['list'] ?? 'all') {
                'high_aov' => $c['high_aov'],
                'vip' => $c['vip'],
                default => true,
            })
            ->filter(fn (array $c) => match (true) {
                $cra === null || $cra === '' => true,
                $cra === 'none' => $c['account']?->assigned_cra_id === null,
                $cra === 'mine' => $userId !== null && $c['account']?->assigned_cra_id === $userId,
                default => $c['account']?->assigned_cra_id === (int) $cra,
            })
            ->filter(fn (array $c) => $search === ''
                || str_contains(mb_strtolower($c['customer_name']), $search)
                || (strlen($digits) >= 4 && str_contains(preg_replace('/\D/', '', $c['phone_number']), $digits)));
    }

    /**
     * Tile counts for the list picked: High AOV, VIP and customers still without a CRA.
     *
     * @return array{high_aov: int, vip: int, unassigned: int}
     */
    public function counts(): array
    {
        $customers = collect($this->all());
        $assigned = CustomerAccount::whereIn('phone_key', $customers->keys())->whereNotNull('assigned_cra_id')->pluck('phone_key')->flip();

        return [
            'high_aov' => $customers->where('high_aov', true)->count(),
            'vip' => $customers->where('vip', true)->count(),
            'unassigned' => $customers->reject(fn (array $c) => isset($assigned[$c['phone_key']]))->count(),
        ];
    }

    /**
     * One page of customers, each with all their numbers and their latest delivered order.
     *
     * @param  Collection<string, array<string, mixed>>  $customers
     */
    public function customerPage(Collection $customers, int $perPage = 25): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();
        $rows = $customers->values()->forPage($page, $perPage);
        $owner = $this->numbersOf($rows->pluck('phone_key')->all());
        $orders = $this->craHandled(LogisticsOrder::covered()->whereIn('lo.phone_key', array_keys($owner)))
            ->orderBy('lo.delivered_date')->orderBy('lo.id')
            ->get(['lo.order_id', 'lo.phone_key', 'lo.phone_number']);
        $latest = $orders->mapWithKeys(fn (LogisticsOrder $o) => [$owner[$o->phone_key] => $o->order_id]);
        $phones = $this->phoneLists($owner, $orders);

        $rows = $rows->map(fn (array $c) => [...$c, 'order_id' => $latest[$c['phone_key']] ?? null, 'phone_number' => $phones[$c['phone_key']] ?? $c['phone_number']]);

        return (new LengthAwarePaginator($rows, $customers->count(), $perPage, $page, ['path' => Paginator::resolveCurrentPath()]))->withQueryString();
    }

    /**
     * One page of these customers' delivered orders, newest first, each with the customer's
     * current totals (orders, AOV) beside the order's own amount.
     *
     * @param  Collection<string, array<string, mixed>>  $customers
     */
    public function orderPage(Collection $customers, int $perPage = 25): LengthAwarePaginator
    {
        $owner = $customers->isEmpty() ? [] : $this->numbersOf($customers->keys()->map(fn ($key) => (string) $key)->all());

        $paginator = $this->craHandled(LogisticsOrder::covered()->whereIn('lo.phone_key', array_keys($owner) ?: ['']))
            ->orderByDesc('lo.delivered_date')->orderByDesc('lo.id')
            ->select('lo.order_id', 'lo.phone_key', 'lo.phone_number', 'lo.delivered_date', 'po.total_price', 'po.seller_name')
            ->paginate($perPage)->withQueryString();

        $phones = $this->phoneLists($owner, LogisticsOrder::covered()->whereIn('phone_key', array_keys($owner) ?: [''])->orderBy('delivered_date')->get(['phone_key', 'phone_number']));

        return $paginator->through(function (LogisticsOrder $order) use ($owner, $customers, $phones) {
            $key = $owner[$order->phone_key];

            return [
                ...$customers[$key],
                'phone_number' => $phones[$key] ?? $customers[$key]['phone_number'],
                'order_id' => $order->order_id,
                'delivered_on' => $order->delivered_date,
                'amount' => $order->total_price === null ? null : (float) $order->total_price,
                'order_seller' => $order->seller_name ? ucwords(strtolower($order->seller_name)) : null,
            ];
        });
    }

    /**
     * Logistics orders (as `lo`, with their Pancake copy as `po`) narrowed to CRA-handled ones.
     *
     * @param  Builder<LogisticsOrder>  $query
     * @return Builder<LogisticsOrder>
     */
    private function craHandled(Builder $query): Builder
    {
        $handled = $this->customers->handledSql();

        return $query->from('logistics_orders as lo')
            ->leftJoin('pancake_orders as po', 'po.pancake_order_id', '=', 'lo.order_id')
            ->whereRaw($handled['sql'], $handled['bindings']);
    }

    /**
     * Each customer's numbers as "number 1 / number 2", main number first.
     *
     * @param  array<string, string>  $owner
     * @param  Collection<int, LogisticsOrder>  $orders
     * @return array<string, string>
     */
    private function phoneLists(array $owner, Collection $orders): array
    {
        $byNumber = $orders->pluck('phone_number', 'phone_key');
        $lists = [];
        foreach ($owner as $number => $key) {
            $lists[$key][$number === $key ? 0 : $number] = $byNumber[$number] ?? $number;
        }

        return array_map(function (array $numbers) {
            ksort($numbers);

            return implode(' / ', $numbers);
        }, $lists);
    }

    /**
     * The CRA set for each of these numbers' customers, by number (only customers with one).
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, int>
     */
    public function crasFor(array $phoneKeys): array
    {
        $primaries = CustomerLink::whereIn('phone_key', $phoneKeys)->pluck('primary_phone_key', 'phone_key');
        $primaryOf = collect($phoneKeys)->mapWithKeys(fn (string $key) => [$key => $primaries[$key] ?? $key]);
        $cras = CustomerAccount::whereIn('phone_key', $primaryOf->unique()->values())->whereNotNull('assigned_cra_id')->pluck('assigned_cra_id', 'phone_key');

        return $primaryOf->map(fn (string $primary) => $cras[$primary] ?? null)->filter()->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Save the CRA each new High AOV / VIP customer's lead was handed to as their CRA,
     * unless they already have one.
     *
     * @param  array<string, int>  $craByNumber  number => CRA id
     * @return int customers given a CRA
     */
    public function claimForLeads(array $craByNumber): int
    {
        if ($craByNumber === []) {
            return 0;
        }

        $listed = $this->all();
        $primaries = CustomerLink::whereIn('phone_key', array_map('strval', array_keys($craByNumber)))->pluck('primary_phone_key', 'phone_key');
        $claimed = 0;

        foreach ($craByNumber as $number => $craId) {
            $key = $primaries[(string) $number] ?? (string) $number;
            if (! isset($listed[$key])) {
                continue;
            }

            $account = CustomerAccount::firstOrNew(['phone_key' => $key]);
            if ($account->assigned_cra_id === null) {
                $account->fill(['assigned_cra_id' => $craId, 'assigned_at' => now()])->save();
                $claimed++;
            }
        }

        return $claimed;
    }
}
