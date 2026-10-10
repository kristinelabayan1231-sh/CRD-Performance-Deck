<?php

namespace App\Services;

use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
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
        $accounts = $cras->filter(fn (User $cra) => $cra->pancake_name)
            ->mapWithKeys(fn (User $cra) => [PancakeEngagement::staffKey($cra->pancake_name) => $cra->id]);

        if ($accounts->isEmpty()) {
            return collect();
        }

        $totals = [];
        // Every status, as in gross sales.
        PancakeOrder::whereIn('seller_name', $accounts->keys())
            ->whereIn('conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION])
            ->whereDate('ordered_on', '>=', $from)->whereDate('ordered_on', '<=', $to)
            ->get(['seller_name', 'items', 'total_price', 'shecom_sales'])
            ->each(function (PancakeOrder $order) use ($accounts, &$totals) {
                $items = collect($order->items ?? [])->map(function (array $item) {
                    $raw = trim((string) ($item['name'] ?? ''));
                    $product = $raw === '' ? null : $this->catalog->match($raw);

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
