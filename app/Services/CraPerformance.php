<?php

namespace App\Services;

use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Order Database → CRA's Performance: the Pancake POS orders each seller account sold, by the day the order was
 * created, counted per status with their qty (orders) and total sales, and the same per product. Deleted orders
 * (status 7) are left out. An order counts once, under its main product: the first item on Settings → Product
 * Consumption (else "Other products"), with the whole sale before Pancake's discount (Shecom's amount, else Pancake's total plus its discount).
 */
class CraPerformance
{
    public const OTHER_PRODUCTS = 'Other products';

    public const DELETED = 7;

    public function __construct(private ProductCatalog $catalog) {}

    /**
     * One row per seller with orders $from–$to, in the order given; $sellers maps staff key => name shown.
     *
     * @param  array<string, string>  $sellers
     * @return Collection<int, array{seller: string, name: string, statuses: array<int, int>, qty: int, sales: float, discount: float, products: list<array{product: string, statuses: array<int, int>, qty: int, sales: float, discount: float}>}>
     */
    public function rows(array $sellers, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        // Plain rows, 2,000 at a time, kept as just what the table needs: a long range has tens of thousands of orders.
        $orders = [];
        $keys = [];
        PancakeOrder::whereIn('seller_name', array_keys($sellers) ?: [''])
            ->where('ordered_on', '>=', $from->toDateString())->where('ordered_on', '<', $to->addDay()->toDateString())
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', self::DELETED))
            ->toBase()->select(['id', 'seller_name', 'status', 'items', 'total_price', 'shecom_sales', 'total_discount'])
            ->lazyById(2000, 'id')
            ->each(function (object $order) use (&$orders, &$keys) {
                $orders[] = [
                    'seller' => $keys[$order->seller_name] ??= PancakeEngagement::staffKey($order->seller_name),
                    'status' => $order->status === null ? -1 : (int) $order->status,
                    'product' => $this->mainProduct(json_decode((string) $order->items, true) ?? []),
                    // The order's value before Pancake's discount (PancakeOrder::salesBeforeDiscount()).
                    'sales' => (float) ($order->shecom_sales ?? $order->total_price + ($order->total_discount ?? 0)),
                    'discount' => (float) ($order->total_discount ?? 0),
                ];
            });
        $orders = collect($orders)->groupBy('seller');

        return collect($sellers)
            ->filter(fn (string $name, string $key) => $orders->has($key))
            ->map(function (string $name, string $key) use ($orders) {
                $products = $orders[$key]->groupBy('product')
                    ->map(fn (Collection $group, string $product) => ['product' => $product, ...self::sum($group)])
                    ->sortBy([
                        fn (array $a, array $b) => ($a['product'] === self::OTHER_PRODUCTS) <=> ($b['product'] === self::OTHER_PRODUCTS),
                        fn (array $a, array $b) => $b['qty'] <=> $a['qty'],
                    ])
                    ->values()->all();

                return ['seller' => $key, 'name' => $name, ...self::sum($orders[$key]), 'products' => $products];
            })
            ->values();
    }

    /**
     * Status => orders, qty, sales and discount, adding up rows (e.g. for the TOTAL row).
     *
     * @param  iterable<array{statuses: array<int, int>, qty: int, sales: float, discount: float}>  $rows
     * @return array{statuses: array<int, int>, qty: int, sales: float, discount: float}
     */
    public static function total(iterable $rows): array
    {
        $total = ['statuses' => [], 'qty' => 0, 'sales' => 0.0, 'discount' => 0.0];

        foreach ($rows as $row) {
            foreach ($row['statuses'] as $status => $count) {
                $total['statuses'][$status] = ($total['statuses'][$status] ?? 0) + $count;
            }
            $total['qty'] += $row['qty'];
            $total['sales'] += $row['sales'];
            $total['discount'] += $row['discount'];
        }

        return $total;
    }

    /**
     * The order's main product: the first item on the Product Consumption list, else "Other products".
     *
     * @param  list<array{name?: string, qty?: int}>  $items
     */
    private function mainProduct(array $items): string
    {
        foreach ($items as $item) {
            if ($product = $this->catalog->match((string) ($item['name'] ?? ''))) {
                return $product->name;
            }
        }

        return self::OTHER_PRODUCTS;
    }

    /**
     * @param  Collection<int, array{status: int, sales: float, discount: float}>  $orders
     * @return array{statuses: array<int, int>, qty: int, sales: float, discount: float}
     */
    private static function sum(Collection $orders): array
    {
        return [
            'statuses' => $orders->countBy('status')->all(),
            'qty' => $orders->count(),
            'sales' => (float) $orders->sum('sales'),
            // Shown beside the sales so the discount stays visible.
            'discount' => (float) $orders->sum('discount'),
        ];
    }
}
