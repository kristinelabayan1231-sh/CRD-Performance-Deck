<?php

namespace App\Services;

use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\User;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Each CRA's gross sales per product: their own Pancake orders tagged CRD - BROADCAST or CRD - SEGMENTATION
 * (not canceled), the same orders and amounts as Conversion Breakdown's gross sales. Items are filed under the
 * Settings → Product Consumption product they match (CRD or TSD upsell); an order with several products is split by
 * qty × the item's Pancake price (qty × SRP when it has none, else by qty), and an item matching no product counts
 * under its own name. A TSD add-on next to a CRD product gets none of a sale Shecom has, as Shecom leaves it out (split()).
 */
class CraProductSales
{
    /** @var array<string, Product|null> "item name|day" => the product it's sold as that day */
    private array $products = [];

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
     * The order's items, each under the Product Consumption product it matches on the order's day (listed) or its own
     * name, with two amounts. An order without items is one Unknown item.
     *
     * sales: its share of the order's gross as Shecom counts it (PancakeOrder::sales()), by qty × its Pancake price
     * (qty × SRP for an item without one, else by qty). Once Shecom has the order, its sale is only the CRD products'
     * (it leaves out the child TSD row), so a TSD or unlisted add-on next to a CRD product gets none of it.
     * value: what the item sold for, as Order Database → Product Report has it: its Pancake price × qty; in an order
     * saved before item prices were kept, Shecom's sale for the CRD products and the rest of the order for the others.
     *
     * @return Collection<int, array{name: string, listed: bool, qty: int, sales: float, value: float}>
     */
    public function split(PancakeOrder $order): Collection
    {
        $on = $order->ordered_on?->toDateString();
        $items = collect($order->items ?? [])->map(function (array $item) use ($on) {
            $raw = trim((string) ($item['name'] ?? ''));
            $product = $this->productOn($raw, $on);
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $price = isset($item['price']) && (float) $item['price'] > 0 ? $qty * (float) $item['price'] : null;

            return [
                'name' => $product?->name ?? ($raw ?: 'Unknown'),
                'listed' => $product !== null,
                'crd' => $product !== null && ! $product->is_tsd,
                'qty' => $qty,
                'price' => $price,
                'weight' => $price ?? $qty * ((float) $product?->srp ?: 1),
            ];
        })->values();
        $items = $items->isEmpty() ? collect([['name' => 'Unknown', 'listed' => false, 'crd' => false, 'qty' => 1, 'price' => null, 'weight' => 1]]) : $items;

        $crd = $items->where('crd', true);
        $addOns = $items->where('crd', false);
        $childLeftOut = $order->shecom_sales !== null && $crd->isNotEmpty() && $addOns->isNotEmpty();
        $sales = $childLeftOut ? self::spread($crd, (float) $order->shecom_sales) : self::spread($items, $order->sales());
        $everyPriced = $items->every(fn (array $item) => $item['price'] !== null);
        $pancakeGross = (float) $order->total_price + (float) ($order->total_discount ?? 0);
        $leftOut = $childLeftOut && ! $everyPriced ? self::spread($addOns, max(0.0, $pancakeGross - (float) $order->shecom_sales)) : [];

        return $items->map(fn (array $item, int $index) => [
            'name' => $item['name'],
            'listed' => $item['listed'],
            'qty' => $item['qty'],
            'sales' => $sales[$index] ?? 0.0,
            'value' => $everyPriced ? $item['price'] : ($leftOut[$index] ?? $sales[$index] ?? 0.0),
        ])->values();
    }

    /**
     * $amount spread over $items by their weight, by index.
     *
     * @param  Collection<int, array{weight: int|float}>  $items
     * @return array<int, float>
     */
    private static function spread(Collection $items, float $amount): array
    {
        $weight = $items->sum('weight');

        return $items->map(fn (array $item) => $weight > 0 ? $amount * $item['weight'] / $weight : $amount / $items->count())->all();
    }

    /**
     * The item names on the order, as typed in Pancake.
     *
     * @return list<string>
     */
    public function rawNames(PancakeOrder $order): array
    {
        return collect($order->items ?? [])->map(fn (array $item) => trim((string) ($item['name'] ?? '')))
            ->filter(fn (string $raw) => $raw !== '')->unique()->values()->all();
    }

    /**
     * The name split() files an item named $raw under in an order of $on (Y-m-d): so an item from an older order can
     * be compared with one on $on's order, under the same products.
     */
    public function nameOn(string $raw, ?string $on): string
    {
        return $this->productOn($raw, $on)?->name ?? ($raw ?: 'Unknown');
    }

    private function productOn(string $raw, ?string $on): ?Product
    {
        if ($raw === '') {
            return null;
        }

        $key = $raw.'|'.$on;

        return array_key_exists($key, $this->products) ? $this->products[$key] : $this->products[$key] = $this->catalog->matchSold($raw, $on);
    }
}
