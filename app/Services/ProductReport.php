<?php

namespace App\Services;

use App\Models\CvrOrderEntry;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\Product;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * Order Database → Product Report: the Pancake POS orders the seller accounts sold, by the day the order was
 * created, per product (Settings → Product Consumption); an item not on that list is its own row under its Pancake
 * name, after the listed ones ("Other products" in the filter shows them all). Deleted orders (status 7) are left out.
 *
 * As Pancake's own Product Performance: Total qty is the pieces sold (a "5 Pterygium Drops" bundle is 5; 3 + 5 bottles
 * in two orders = 8), sales are each item at its Pancake price and Product cost each item at its variation's cost
 * (else pieces × the product's unit cost). Orders saved before item prices were kept split their sale (before
 * Pancake's discount) by SRP × qty, or by qty when a product has no SRP; Shecom's sales cover only the listed
 * products, so with both kinds in an order the items not on the list get the rest of the price.
 *
 * The rates go by sales: Actual Del% is delivered ÷ (delivered + RTS), Actual RTS% is RTS ÷ the same, and Running
 * Del% is delivered ÷ (delivered + RTS + still shipped), so parcels on the way pull it down until they arrive.
 */
class ProductReport
{
    /** Pancake seller name => staff key, worked out once per name. */
    private static array $sellerKeys = [];

    /** Pancake POS statuses: delivered or payment collected. */
    public const DELIVERED = [3, 16];

    /** Returning, returned, partially returned. */
    public const RTS = [4, 5, 15];

    /** Shipped, not delivered or returned yet. */
    public const SHIPPED = [2];

    public function __construct(private ProductCatalog $catalog) {}

    /**
     * One row per product sold $from–$to by $sellers (staff key => name shown), most pieces first; only $product (a
     * product or item name, or "Other products" for every item not on the list) when given. Per-CRA figures live on CRA's Performance, not here.
     *
     * @param  array<string, string>  $sellers
     * @return Collection<int, array{product: string, statuses: array<int, int>, qty: int, sales: float, cost: ?float, delivered: float, rts: float, shipped: float, listed: bool}>
     */
    public function rows(array $sellers, CarbonImmutable $from, CarbonImmutable $to, ?string $product = null): Collection
    {
        $lines = $this->lines($sellers, $from, $to)
            ->when(filled($product), fn (Collection $lines) => $product === CraPerformance::OTHER_PRODUCTS
                ? $lines->where('listed', false)
                : $lines->where('product', $product));

        return $this->rowsFrom($lines);
    }

    /**
     * Each order's products sold $from–$to by $sellers, with the order's day and hour (segmentation timezone).
     *
     * @param  array<string, string>  $sellers
     * @return Collection<int, array{order: int, seller: string, status: int, product: string, qty: int, sales: float, cost: ?float, day: string, hour: int, listed: bool}>
     */
    public function lines(array $sellers, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $costs = Product::pluck('unit_cost', 'name');
        $timezone = new DateTimeZone(config('segmentation.timezone'));
        $lines = [];

        // Plain rows, 2,000 at a time: a long range has tens of thousands of orders, too many to hold as models.
        // Orders counted for another CRA in the CVR Tracker go to that CRA's account (CraPerformance does the same).
        $claimed = CvrOrderEntry::claimedAccounts();
        $claimedHere = array_keys(array_intersect($claimed, array_keys($sellers)));
        PancakeOrder::where(fn ($q) => $q->whereIn('seller_name', array_keys($sellers) ?: [''])->orWhereIn('pancake_order_id', $claimedHere ?: ['']))
            ->where('ordered_on', '>=', $from->toDateString())->where('ordered_on', '<', $to->addDay()->toDateString())
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', CraPerformance::DELETED))
            ->toBase()->select(['id', 'pancake_order_id', 'seller_name', 'status', 'items', 'total_price', 'shecom_sales', 'total_discount', 'ordered_on', 'ordered_at'])
            ->lazyById(2000, 'id')
            ->each(function (object $order) use (&$lines, $costs, $timezone, $claimed, $sellers) {
                $seller = $claimed[$order->pancake_order_id] ?? null;
                if ($seller !== null && ! isset($sellers[$seller])) {
                    return;
                }
                foreach ($this->linesOf($order, $costs, $timezone, $seller) as $line) {
                    $lines[] = $line;
                }
            });

        return collect($lines);
    }

    /**
     * One row per product in $lines: the listed products, then the items not on the list, each most pieces first.
     *
     * @param  Collection<int, array{order: int, seller: string, status: int, product: string, qty: int, sales: float, cost: ?float}>  $lines
     * @return Collection<int, array{product: string, statuses: array<int, int>, qty: int, sales: float, cost: ?float, delivered: float, rts: float, shipped: float, listed: bool}>
     */
    public function rowsFrom(Collection $lines): Collection
    {
        return $lines->groupBy('product')
            ->map(fn (Collection $group, string $name) => ['product' => $name, ...self::sum($group), 'listed' => $group->first()['listed']])
            ->sortBy([
                fn (array $a, array $b) => $b['listed'] <=> $a['listed'],
                fn (array $a, array $b) => $b['qty'] <=> $a['qty'],
            ])
            ->values();
    }

    /**
     * The TOTAL row: every product's orders (an order with two products counts once per status), pieces, sales and cost.
     *
     * @param  Collection<int, array{qty: int, sales: float, cost: ?float, delivered: float, rts: float, shipped: float, orders: array<int, int>}>  $rows
     * @return array{statuses: array<int, int>, qty: int, sales: float, cost: ?float, delivered: float, rts: float, shipped: float}
     */
    public static function total(Collection $rows): array
    {
        $costs = $rows->pluck('cost')->filter(fn (?float $cost) => $cost !== null);
        $orders = $rows->reduce(fn (array $carry, array $row) => array_replace($carry, $row['orders']), []);

        return [
            'statuses' => collect($orders)->countBy()->all(),
            'qty' => (int) $rows->sum('qty'),
            'sales' => (float) $rows->sum('sales'),
            'cost' => $costs->isEmpty() ? null : (float) $costs->sum(),
            'delivered' => (float) $rows->sum('delivered'),
            'rts' => (float) $rows->sum('rts'),
            'shipped' => (float) $rows->sum('shipped'),
        ];
    }

    /**
     * Actual Del%, Actual RTS% and Running Del% (0–100, rounded) of a row; null where nothing has arrived or been shipped yet.
     *
     * @param  array{delivered: float, rts: float, shipped: float}  $row
     * @return array{actual_del: ?int, actual_rts: ?int, running_del: ?int}
     */
    public static function rates(array $row): array
    {
        $finished = $row['delivered'] + $row['rts'];
        $running = $finished + $row['shipped'];

        return [
            'actual_del' => $finished > 0 ? (int) round($row['delivered'] / $finished * 100) : null,
            'actual_rts' => $finished > 0 ? (int) round($row['rts'] / $finished * 100) : null,
            'running_del' => $running > 0 ? (int) round($row['delivered'] / $running * 100) : null,
        ];
    }

    /**
     * The order's products with their pieces and share of the sale.
     *
     * @param  Collection<string, ?string>  $costs  product name => unit cost
     * @return list<array{order: int, seller: string, status: int, product: string, qty: int, sales: float, cost: ?float, day: string, hour: int, listed: bool}>
     */
    private function linesOf(object $order, Collection $costs, DateTimeZone $timezone, ?string $seller = null): array
    {
        $products = [];
        foreach (json_decode((string) $order->items, true) ?? [] as $item) {
            $itemName = trim((string) ($item['name'] ?? ''));
            $product = $this->catalog->match($itemName);
            // Not on the list: its own row under its Pancake name.
            $name = $product?->name ?? ($itemName === '' ? CraPerformance::OTHER_PRODUCTS : $itemName);
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $products[$name] ??= ['units' => 0, 'qty' => 0, 'weight' => 0, 'listed' => $product !== null, 'price' => 0.0, 'cost' => 0.0];
            $products[$name]['units'] += $qty;
            // Pieces: a 5-piece bundle bought twice is 10.
            $products[$name]['qty'] += $qty * (int) ($item['pieces'] ?? 1);
            $products[$name]['weight'] += $qty * (float) ($product?->srp ?? 0);
            // Pancake's price and cost of the variation; null once an item has none (saved before they were kept).
            $products[$name]['price'] = isset($item['price'], $products[$name]['price']) ? $products[$name]['price'] + $qty * (float) $item['price'] : null;
            $products[$name]['cost'] = isset($item['cost'], $products[$name]['cost']) ? $products[$name]['cost'] + $qty * (float) $item['cost'] : null;
        }

        if ($products === []) {
            $products[CraPerformance::OTHER_PRODUCTS] = ['units' => 0, 'qty' => 0, 'weight' => 0, 'listed' => false, 'price' => null, 'cost' => null];
        }

        $salesOf = self::salesOf($order, $products);
        // The CRA's account the order counts for: the CVR Tracker's, else its seller's.
        $seller ??= self::$sellerKeys[$order->seller_name] ??= PancakeEngagement::staffKey($order->seller_name);
        $hour = $order->ordered_at === null ? 0 : (int) (new DateTimeImmutable($order->ordered_at, new DateTimeZone('UTC')))->setTimezone($timezone)->format('G');

        $lines = [];
        foreach ($products as $name => $product) {
            $lines[] = [
                'order' => (int) $order->id,
                'seller' => $seller,
                'status' => $order->status === null ? -1 : (int) $order->status,
                'product' => (string) $name,
                'qty' => $product['qty'],
                'sales' => $salesOf[$name],
                // Pancake's variation cost, else the pieces × the product's unit cost (Settings → Product Consumption).
                'cost' => $product['cost'] ?? (isset($costs[$name]) ? $product['qty'] * (float) $costs[$name] : null),
                'day' => substr((string) $order->ordered_on, 0, 10),
                'hour' => $hour,
                'listed' => $product['listed'],
            ];
        }

        return $lines;
    }

    /**
     * Each product's share of the order's sale. With Pancake's item prices (as its own Product Performance): each
     * product's items at their price. Orders saved before those were kept: the value before Pancake's discount
     * (PancakeOrder::salesBeforeDiscount()), where Shecom's sales cover only the listed products, so with both kinds
     * in the order the listed ones share Shecom's sales and the others the rest of the price (a ₱1,999 Pterygium +
     * Lumicare with ₱999 Shecom sales: ₱999 and ₱1,000).
     *
     * @param  array<string, array{units: int, weight: float, listed: bool, price: ?float}>  $products
     * @return array<string, float>
     */
    private static function salesOf(object $order, array $products): array
    {
        if (! in_array(null, array_column($products, 'price'), true)) {
            return array_map(fn (array $product) => (float) $product['price'], $products);
        }

        $gross = (float) $order->total_price + (float) ($order->total_discount ?? 0);
        $listed = array_filter($products, fn (array $product) => $product['listed']);
        $unlisted = array_diff_key($products, $listed);
        $pools = $order->shecom_sales !== null && $listed !== [] && $unlisted !== []
            ? [[$listed, (float) $order->shecom_sales], [$unlisted, max(0.0, $gross - (float) $order->shecom_sales)]]
            : [[$products, (float) ($order->shecom_sales ?? $gross)]];
        $salesOf = [];
        foreach ($pools as [$group, $amount]) {
            $salesOf += self::split($group, $amount);
        }

        return $salesOf;
    }

    /**
     * $amount split between $products by SRP × qty when every one has an SRP, else by items.
     *
     * @param  array<string, array{units: int, weight: float}>  $products
     * @return array<string, float>
     */
    private static function split(array $products, float $amount): array
    {
        $bySrp = true;
        foreach ($products as $product) {
            $bySrp = $bySrp && $product['weight'] > 0;
        }
        $weights = array_map(fn (array $product) => $bySrp ? $product['weight'] : $product['units'], $products);
        $totalWeight = array_sum($weights);

        return array_map(fn (int|float $weight) => $totalWeight > 0 ? $amount * $weight / $totalWeight : $amount / count($products), $weights);
    }

    /**
     * @param  Collection<int, array{order: int, status: int, qty: int, sales: float, cost: ?float}>  $lines
     * @return array{statuses: array<int, int>, qty: int, sales: float, cost: ?float, delivered: float, rts: float, shipped: float, orders: array<int, int>}
     */
    private static function sum(Collection $lines): array
    {
        $costs = $lines->pluck('cost')->filter(fn (?float $cost) => $cost !== null);
        $salesOf = fn (array $statuses) => (float) $lines->whereIn('status', $statuses)->sum('sales');

        return [
            'statuses' => $lines->unique('order')->countBy('status')->all(),
            'qty' => (int) $lines->sum('qty'),
            'sales' => (float) $lines->sum('sales'),
            'cost' => $costs->isEmpty() ? null : (float) $costs->sum(),
            'delivered' => $salesOf(self::DELIVERED),
            'rts' => $salesOf(self::RTS),
            'shipped' => $salesOf(self::SHIPPED),
            // Order id => status, so the TOTAL row counts an order with two products once.
            'orders' => $lines->pluck('status', 'order')->all(),
        ];
    }
}
