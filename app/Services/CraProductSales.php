<?php

namespace App\Services;

use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Each CRA's gross sales per product: their own Pancake orders tagged CRD - BROADCAST or CRD - SEGMENTATION
 * (not canceled), the same orders and amounts as Conversion Breakdown's gross sales. Items are filed under the
 * Settings → Product Consumption product they match (CRD or TSD upsell); an order with several products is split by
 * qty × SRP (by qty when a product has no SRP), and an item matching no product counts under its own name.
 */
class CraProductSales
{
    /** @var array<string, string> item name => its product whatever the day */
    private array $keys = [];

    public function __construct(private ProductCatalog $catalog) {}

    /**
     * @param  Collection<int, User>  $cras
     * @return Collection<int, Collection<string, float>> CRA id => product => sales, best seller first
     */
    public function for(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $accounts = PancakeAccounts::craBySeller($cras)->map(fn (User $cra) => $cra->id);

        if ($accounts->isEmpty()) {
            return collect();
        }

        $totals = [];
        // Every status, as in gross sales.
        PancakeOrder::crdSales()->whereIn('seller_name', $accounts->keys())
            ->whereIn('conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION])
            ->where('ordered_on', '>=', $from->toDateString())->where('ordered_on', '<', $to->addDay()->toDateString())
            ->get(['seller_name', 'items', 'total_price', 'total_discount', 'shecom_sales', 'ordered_on'])
            ->each(function (PancakeOrder $order) use ($accounts, &$totals) {
                $craId = $accounts[$order->seller_name];

                foreach ($this->split($order) as $item) {
                    $totals[$craId][$item['name']] = ($totals[$craId][$item['name']] ?? 0) + $item['sales'];
                }
            });

        return collect($totals)->map(fn (array $products) => collect($products)->sortDesc());
    }

    /**
     * The order's sales split over its items: each item under the Product Consumption product it matches (listed) or
     * its own name, by qty × SRP (by qty when a product has no SRP). An order without items is one Unknown item. key is
     * the item's product whatever the order's day (productNames()), for telling a product bought before from a new one.
     *
     * @return Collection<int, array{name: string, key: string, listed: bool, qty: int, sales: float}>
     */
    public function split(PancakeOrder $order): Collection
    {
        $items = $this->items($order);
        $items = $items->isEmpty() ? collect([['name' => 'Unknown', 'key' => 'Unknown', 'listed' => false, 'qty' => 1, 'weight' => 1]]) : $items;
        $weight = $items->sum('weight');

        return $items->map(fn (array $item) => [
            'name' => $item['name'],
            'key' => $item['key'],
            'listed' => $item['listed'],
            'qty' => $item['qty'],
            'sales' => $order->sales() * $item['weight'] / $weight,
        ])->values();
    }

    /**
     * The products on the order whatever its day (split()'s key): a product added later to Product Consumption is the
     * same product in an order from before it counted, so a customer who bought it then has bought it before.
     *
     * @return list<string>
     */
    public function productNames(PancakeOrder $order): array
    {
        return collect($order->items ?? [])->map(fn (array $item) => $this->key(trim((string) ($item['name'] ?? ''))))
            ->unique()->values()->all();
    }

    private function key(string $raw): string
    {
        return $this->keys[$raw] ??= ($raw === '' ? null : $this->catalog->matchSold($raw)?->name) ?? ($raw ?: 'Unknown');
    }

    /**
     * @return Collection<int, array{name: string, key: string, listed: bool, qty: int, weight: int|float}>
     */
    private function items(PancakeOrder $order): Collection
    {
        return collect($order->items ?? [])->map(function (array $item) use ($order) {
            $raw = trim((string) ($item['name'] ?? ''));
            $product = $raw === '' ? null : $this->catalog->matchSold($raw, $order->ordered_on?->toDateString());
            $qty = max(1, (int) ($item['qty'] ?? 1));

            return [
                'name' => $product?->name ?? ($raw ?: 'Unknown'),
                'key' => $this->key($raw),
                'listed' => $product !== null,
                'qty' => $qty,
                'weight' => $qty * ((float) $product?->srp ?: 1),
            ];
        });
    }
}
