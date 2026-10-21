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
 * Settings → Product Consumption product they match; an order with several products is split by qty × SRP
 * (by qty when a product has no SRP), and an item matching no product counts under its own name.
 */
class CraProductSales
{
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
        PancakeOrder::crdProducts()->whereIn('seller_name', $accounts->keys())
            ->whereIn('conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION])
            ->where('ordered_on', '>=', $from->toDateString())->where('ordered_on', '<', $to->addDay()->toDateString())
            ->get(['seller_name', 'items', 'total_price', 'shecom_sales', 'ordered_on'])
            ->each(function (PancakeOrder $order) use ($accounts, &$totals) {
                $items = collect($order->items ?? [])->map(function (array $item) use ($order) {
                    $raw = trim((string) ($item['name'] ?? ''));
                    $product = $raw === '' ? null : $this->catalog->match($raw, $order->ordered_on?->toDateString());

                    return [
                        'name' => $product?->name ?? ($raw ?: 'Unknown'),
                        'weight' => max(1, (int) ($item['qty'] ?? 1)) * ((float) $product?->srp ?: 1),
                    ];
                });
                $items = $items->isEmpty() ? collect([['name' => 'Unknown', 'weight' => 1]]) : $items;
                $weight = $items->sum('weight');
                $craId = $accounts[$order->seller_name];

                foreach ($items as $item) {
                    $totals[$craId][$item['name']] = ($totals[$craId][$item['name']] ?? 0) + $order->sales() * $item['weight'] / $weight;
                }
            });

        return collect($totals)->map(fn (array $products) => collect($products)->sortDesc());
    }
}
