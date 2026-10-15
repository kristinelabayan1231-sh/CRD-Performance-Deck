<?php

namespace App\Services;

use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\Product;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * Order Database → Product Report: the Pancake POS orders the seller accounts sold, by the day the order was
 * created, per product (Settings → Product Consumption, else "Other products"). Deleted orders (status 7) are left out.
 *
 * Unlike CRA's Performance, every item counts: Total qty is the pieces sold (3 + 5 bottles in two orders = 8) and an
 * order with several products splits its sale (before Pancake's discount) between them by SRP × qty, or by qty when
 * a product has no SRP. Product cost is qty × the product's unit cost.
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
     * One row per product sold $from–$to by $sellers (staff key => name shown), most pieces first, each opening to
     * its sellers; only $product (a product name) when given.
     *
     * @param  array<string, string>  $sellers
     * @return Collection<int, array{product: string, statuses: array<int, int>, qty: int, sales: float, cost: ?float, delivered: float, rts: float, shipped: float, sellers: list<array{seller: string, name: string, statuses: array<int, int>, qty: int, sales: float, cost: ?float, delivered: float, rts: float, shipped: float}>}>
     */
    public function rows(array $sellers, CarbonImmutable $from, CarbonImmutable $to, ?string $product = null): Collection
    {
        $lines = $this->lines($sellers, $from, $to)
            ->when(filled($product), fn (Collection $lines) => $lines->where('product', $product));

        return $this->rowsFrom($lines, $sellers);
    }

    /**
     * Each order's products sold $from–$to by $sellers, with the order's day and hour (segmentation timezone).
     *
     * @param  array<string, string>  $sellers
     * @return Collection<int, array{order: int, seller: string, status: int, product: string, qty: int, sales: float, cost: ?float, day: string, hour: int}>
     */
    public function lines(array $sellers, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $costs = Product::pluck('unit_cost', 'name');
        $timezone = new DateTimeZone(config('segmentation.timezone'));
        $lines = [];

        // Plain rows, 2,000 at a time: a long range has tens of thousands of orders, too many to hold as models.
        PancakeOrder::whereIn('seller_name', array_keys($sellers) ?: [''])
            ->where('ordered_on', '>=', $from->toDateString())->where('ordered_on', '<', $to->addDay()->toDateString())
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', CraPerformance::DELETED))
            ->toBase()->select(['id', 'seller_name', 'status', 'items', 'total_price', 'shecom_sales', 'total_discount', 'ordered_on', 'ordered_at'])
            ->lazyById(2000, 'id')
            ->each(function (object $order) use (&$lines, $costs, $timezone) {
                foreach ($this->linesOf($order, $costs, $timezone) as $line) {
                    $lines[] = $line;
                }
            });

        return collect($lines);
    }

    /**
     * One row per product in $lines, most pieces first, each opening to its sellers.
     *
     * @param  Collection<int, array{order: int, seller: string, status: int, product: string, qty: int, sales: float, cost: ?float}>  $lines
     * @param  array<string, string>  $sellers
     * @return Collection<int, array{product: string, statuses: array<int, int>, qty: int, sales: float, cost: ?float, delivered: float, rts: float, shipped: float, sellers: list<array<string, mixed>>}>
     */
    public function rowsFrom(Collection $lines, array $sellers): Collection
    {
        return $lines->groupBy('product')
            ->map(function (Collection $group, string $name) use ($sellers) {
                $bySeller = $group->groupBy('seller');
                $rows = collect($sellers)
                    ->filter(fn (string $sellerName, string $key) => $bySeller->has($key))
                    ->map(fn (string $sellerName, string $key) => ['seller' => $key, 'name' => $sellerName, ...self::sum($bySeller[$key])])
                    ->sortByDesc('qty')->values()->all();

                return ['product' => $name, ...self::sum($group), 'sellers' => $rows];
            })
            ->sortBy([
                fn (array $a, array $b) => ($a['product'] === CraPerformance::OTHER_PRODUCTS) <=> ($b['product'] === CraPerformance::OTHER_PRODUCTS),
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
     * @return list<array{order: int, seller: string, status: int, product: string, qty: int, sales: float, cost: ?float, day: string, hour: int}>
     */
    private function linesOf(object $order, Collection $costs, DateTimeZone $timezone): array
    {
        $products = [];
        foreach (json_decode((string) $order->items, true) ?? [] as $item) {
            $product = $this->catalog->match((string) ($item['name'] ?? ''));
            $name = $product?->name ?? CraPerformance::OTHER_PRODUCTS;
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $products[$name]['qty'] = ($products[$name]['qty'] ?? 0) + $qty;
            $products[$name]['weight'] = ($products[$name]['weight'] ?? 0) + $qty * (float) ($product?->srp ?? 0);
        }

        if ($products === []) {
            $products[CraPerformance::OTHER_PRODUCTS] = ['qty' => 0, 'weight' => 0];
        }

        // Split by SRP × qty only when every product has an SRP; else by pieces.
        $bySrp = true;
        foreach ($products as $product) {
            $bySrp = $bySrp && $product['weight'] > 0;
        }
        $weights = array_map(fn (array $product) => $bySrp ? $product['weight'] : $product['qty'], $products);
        $totalWeight = array_sum($weights);
        // The order's value before Pancake's discount (PancakeOrder::salesBeforeDiscount()).
        $sales = (float) ($order->shecom_sales ?? $order->total_price + ($order->total_discount ?? 0));
        $seller = self::$sellerKeys[$order->seller_name] ??= PancakeEngagement::staffKey($order->seller_name);
        $hour = $order->ordered_at === null ? 0 : (int) (new DateTimeImmutable($order->ordered_at, new DateTimeZone('UTC')))->setTimezone($timezone)->format('G');

        $lines = [];
        foreach ($products as $name => $product) {
            $lines[] = [
                'order' => (int) $order->id,
                'seller' => $seller,
                'status' => $order->status === null ? -1 : (int) $order->status,
                'product' => (string) $name,
                'qty' => $product['qty'],
                'sales' => $totalWeight > 0 ? $sales * $weights[$name] / $totalWeight : $sales / count($products),
                'cost' => isset($costs[$name]) ? $product['qty'] * (float) $costs[$name] : null,
                'day' => substr((string) $order->ordered_on, 0, 10),
                'hour' => $hour,
            ];
        }

        return $lines;
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
