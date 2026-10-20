<?php

namespace App\Services;

use App\Models\MniDepartment;
use App\Models\MniInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monthly MNI: each CRA's target income statement for a month and plan (1M / 2M), worked out from the typed-in inputs,
 * as in the CRAs' sheet. The department adds up the CRAs (its conversion rate and AOV are worked out from the totals).
 *
 * Target leads = Orders ÷ Conversion Rate. Gross Sales = Orders × AOV. Projected Cancelled / Returns = Gross Sales × their
 * %. Projected Delivered = Gross Sales × (100% − % Cancelled − % Returns). Tax Allocation and Advertising Cost = the
 * department's ÷ number of CRAs. Product Cost = Gross Sales × % Product Cost. Gross Profit = Projected Delivered − Tax
 * Allocation − Product Cost. Ads VAT = 0. Shipping Fee = Orders × ₱75. COD Fee = Projected Delivered × 2%. VAT on COD
 * Fee = COD Fee × 12%. Fulfillment Fee = Orders × ₱25. Operating Costs and NET INCOME wait for their formulas.
 */
class MonthlyMni
{
    public const SHIPPING_PER_ORDER = 75;

    public const FULFILLMENT_PER_ORDER = 25;

    public const COD_RATE = 0.02;

    public const VAT_ON_COD_RATE = 0.12;

    /** Statement lines: [label, key, kind]; kind: count, percent, money, total, section, pending, net. */
    public const LINES = [
        ['Target Number of Leads in one month', 'leads', 'count'],
        ['Number of Orders', 'orders', 'count'],
        ['Conversion Rate', 'conversion_rate', 'percent'],
        ['Average Order Value', 'aov', 'money'],
        ['Gross Sales', 'gross', 'money'],
        ['Projected Cancelled', 'cancelled', 'money'],
        ['Projected Returns', 'returns', 'money'],
        ['Projected Delivered', 'delivered', 'money'],
        ['Tax Allocation', 'tax', 'money'],
        ['Product Cost', 'product_cost', 'money'],
        ['Gross Profit', 'gross_profit', 'total'],
        ['Selling And Marketing', null, 'section'],
        ['Advertising Cost', 'advertising', 'money'],
        ['Ads VAT', 'ads_vat', 'money'],
        ['Shipping Fee', 'shipping', 'money'],
        ['COD Fee', 'cod', 'money'],
        ['VAT on COD Fee', 'vat_on_cod', 'money'],
        ['Fulfillment Fee', 'fulfillment', 'money'],
        ['Total Selling Costs', 'selling', 'total'],
        ['Operating Costs', null, 'section'],
        ['Salaries', null, 'pending'], ['Communication Allowance', null, 'pending'], ['13th Month Allowance', null, 'pending'],
        ['SIL', null, 'pending'], ['Government Benefits', null, 'pending'], ['Miscellaneous expenses', null, 'pending'],
        ['Product Research', null, 'pending'], ['Magic Fund', null, 'pending'], ['Company Assets', null, 'pending'],
        ['Executive Benefits', null, 'pending'], ['Office Miscellaneous', null, 'pending'], ['Maintenance Expenses', null, 'pending'],
        ['Consultants', null, 'pending'], ["Manager's Allowance", null, 'pending'], ['Birthday Cake Allowance', null, 'pending'],
        ['Water Bill', null, 'pending'], ['Internet', null, 'pending'], ['Rent', null, 'pending'], ['Electricity', null, 'pending'],
        ['Geniusmakers Management Fee', null, 'pending'], ['Business Development Fund', null, 'pending'], ['HMO Expense', null, 'pending'],
        ['Total Operating Costs', null, 'pending'],
        ['NET INCOME', null, 'net'],
    ];

    /** Money lines added up for the department (the rest are worked out from them). */
    private const SUMMED = ['leads', 'orders', 'gross', 'cancelled', 'returns', 'delivered', 'tax', 'product_cost', 'gross_profit',
        'advertising', 'ads_vat', 'shipping', 'cod', 'vat_on_cod', 'fulfillment', 'selling'];

    /**
     * @param  Collection<int, User>  $cras
     * @return array{inputs: Collection<int, MniInput>, department_inputs: MniDepartment, cras: array<int, array<string, float|int|null>>, department: array<string, float|int|null>}
     */
    public function for(Collection $cras, CarbonImmutable $month, string $plan): array
    {
        $inputs = MniInput::where('plan', $plan)->whereDate('month', $month)->whereIn('user_id', $cras->pluck('id'))->get()->keyBy('user_id');
        $department = MniDepartment::where('plan', $plan)->whereDate('month', $month)->first() ?? new MniDepartment;

        $statements = $cras->mapWithKeys(fn (User $cra) => [
            $cra->id => self::statement($inputs->get($cra->id), $department, max($cras->count(), 1)),
        ]);

        $total = [];
        foreach (self::SUMMED as $key) {
            $total[$key] = $statements->sum($key);
        }
        $total['conversion_rate'] = $total['leads'] ? $total['orders'] / $total['leads'] : null;
        $total['aov'] = $total['orders'] ? $total['gross'] / $total['orders'] : null;

        return ['inputs' => $inputs, 'department_inputs' => $department, 'cras' => $statements->all(), 'department' => $total];
    }

    /**
     * One CRA's statement. Empty inputs count as 0.
     *
     * @return array<string, float|int|null>
     */
    public static function statement(?MniInput $input, MniDepartment $department, int $craCount): array
    {
        $orders = (int) ($input?->orders ?? 0);
        $rate = ($input?->conversion_rate ?? 0) / 100;
        $aov = (float) ($input?->aov ?? 0);
        $cancelledPct = ($input?->cancelled_pct ?? 0) / 100;
        $returnsPct = ($input?->returns_pct ?? 0) / 100;

        $gross = $orders * $aov;
        $delivered = $gross * (1 - $cancelledPct - $returnsPct);
        $tax = ($department->tax_allocation ?? 0) / $craCount;
        $productCost = $gross * ($input?->product_cost_pct ?? 0) / 100;
        $advertising = ($department->advertising_cost ?? 0) / $craCount;
        $shipping = $orders * self::SHIPPING_PER_ORDER;
        $cod = $delivered * self::COD_RATE;
        $vatOnCod = $cod * self::VAT_ON_COD_RATE;
        $fulfillment = $orders * self::FULFILLMENT_PER_ORDER;

        return [
            'leads' => $rate > 0 ? $orders / $rate : 0.0,
            'orders' => $orders,
            'conversion_rate' => $rate ?: null,
            'aov' => $input?->aov,
            'gross' => $gross,
            'cancelled' => $gross * $cancelledPct,
            'returns' => $gross * $returnsPct,
            'delivered' => $delivered,
            'tax' => $tax,
            'product_cost' => $productCost,
            'gross_profit' => $delivered - $tax - $productCost,
            'advertising' => $advertising,
            'ads_vat' => 0.0,
            'shipping' => $shipping,
            'cod' => $cod,
            'vat_on_cod' => $vatOnCod,
            'fulfillment' => $fulfillment,
            'selling' => $advertising + $shipping + $cod + $vatOnCod + $fulfillment,
        ];
    }
}
