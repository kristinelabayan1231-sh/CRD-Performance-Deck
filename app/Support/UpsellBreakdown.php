<?php

namespace App\Support;

use App\Services\PresentationBoard;

/**
 * Main vs upsell, for showing: a split of PresentationBoard::products()' figures, nothing counted again. Main = the
 * products' own sales; Upsell = what was upsold on their orders plus the TSD products' own sales, as a TSD product is
 * always an upsell, also when it was bought with no main product. A product's Main % and Upsold % split everything on
 * its orders, its own sales and what was upsold on them, so the two add up to 100%.
 */
class UpsellBreakdown
{
    /**
     * @return array{main: float, upsold: float} shares of 1; both 0 when there is nothing
     */
    public static function rates(float $sales, float $upsold): array
    {
        $total = $sales + $upsold;

        return $total > 0 ? ['main' => $sales / $total, 'upsold' => $upsold / $total] : ['main' => 0.0, 'upsold' => 0.0];
    }

    /**
     * The dates' main and upsell: main = the non-TSD products' own sales (Others too); upsold = what was upsold on their
     * orders; tsd = the TSD products' own sales and what was upsold on theirs, with tsd_products those products.
     *
     * @param  array{products: list<array<string, mixed>>}  $top  PresentationBoard::products()
     * @param  list<string>  $tsdNames
     * @return array{main: float, upsold: float, tsd: float, upsell: float, total: float, tsd_products: list<array{name: string, value: float, orders: int, qty: int}>}
     */
    public static function totals(array $top, array $tsdNames): array
    {
        $main = 0.0;
        $upsold = 0.0;
        $tsdProducts = [];
        foreach ($top['products'] as $product) {
            if (in_array($product['name'], $tsdNames, true)) {
                $tsdProducts[] = [
                    'name' => $product['name'],
                    'value' => (float) $product['sales'] + (float) ($product['upsold_value'] ?? 0),
                    'orders' => (int) $product['orders'],
                    'qty' => (int) $product['qty'],
                ];

                continue;
            }
            $main += (float) $product['sales'];
            $upsold += (float) ($product['upsold_value'] ?? 0);
        }
        $tsdProducts = collect($tsdProducts)->filter(fn (array $product) => $product['value'] > 0)->sortByDesc('value')->values()->all();
        $tsd = (float) array_sum(array_column($tsdProducts, 'value'));

        return [
            'main' => $main,
            'upsold' => $upsold,
            'tsd' => $tsd,
            'upsell' => $upsold + $tsd,
            'total' => $main + $upsold + $tsd,
            'tsd_products' => $tsdProducts,
        ];
    }

    /**
     * The products to list beside the TSD row: every product but the TSD ones and Others.
     *
     * @param  list<array<string, mixed>>  $products
     * @param  list<string>  $tsdNames
     * @return list<array<string, mixed>>
     */
    public static function listed(array $products, array $tsdNames): array
    {
        return collect($products)
            ->reject(fn (array $product) => $product['name'] === PresentationBoard::OTHERS || in_array($product['name'], $tsdNames, true))
            ->values()->all();
    }
}
