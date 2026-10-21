<?php

namespace App\Services;

use App\Models\MniDepartment;
use App\Models\MniInput;
use App\Models\MonthlyCostRow;
use App\Models\User;
use App\Support\MonthlyCost;
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
 * Fee = COD Fee × 12%. Fulfillment Fee = Orders × ₱25.
 *
 * Operating costs, from CRD - MC (today's figures): Salaries = the CRA's own row + an even share of the rest of TOTAL SALARY
 * OF CRD (Manager, Supervisor, other staff and the shared salaries) ÷ number of CRAs. 13th Month = Salaries ÷ 12. SIL =
 * Salaries × 12 ÷ 313. Government Benefits = CRD - MC's Gov. Benefits (half of it on 2M) ÷ number of CRAs. Communication
 * Allowance, Miscellaneous expenses and Product Research to HMO Expense = the department's (typed in) ÷ number of CRAs.
 * Product Research is a selling cost on 2M and an operating cost on 1M, as in each plan's sheet. Total Operating Costs =
 * Salaries to HMO Expense (less Product Research on 2M). NET INCOME = Gross Profit − Total Selling Costs − Total Operating Costs.
 */
class MonthlyMni
{
    public const SIL_DAYS_A_YEAR = 12;

    public const SIL_WORKING_DAYS = 313;

    /** Plans that count CRD - MC's Gov. Benefits in part: plan => share. */
    public const GOV_BENEFITS_SHARE = ['2m' => 0.5];

    /** Plans whose sheet lists Product Research under Selling And Marketing. */
    public const PRODUCT_RESEARCH_SELLING = ['2m'];

    public function __construct(private MonthlyCost $cost) {}

    /**
     * The statement's lines for a plan: on 2M, Product Research sits just above Total Selling Costs.
     *
     * @return list<array{0: string, 1: ?string, 2: string}>
     */
    public static function lines(string $plan): array
    {
        if (! in_array($plan, self::PRODUCT_RESEARCH_SELLING, true)) {
            return self::LINES;
        }

        $research = ['Product Research', 'product_research', 'money'];
        $lines = array_values(array_filter(self::LINES, fn (array $line) => $line[1] !== 'product_research'));
        array_splice($lines, array_search('selling', array_column($lines, 1), true), 0, [$research]);

        return $lines;
    }

    public const SHIPPING_PER_ORDER = 75;

    public const FULFILLMENT_PER_ORDER = 25;

    public const COD_RATE = 0.02;

    public const VAT_ON_COD_RATE = 0.12;

    /** Statement lines: [label, key, kind]; kind: count, percent, money, total, section, net. */
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
        ['Salaries', 'salaries', 'money'], ['Communication Allowance', 'communication', 'money'], ['13th Month Allowance', 'thirteenth_month', 'money'],
        ['SIL', 'sil', 'money'], ['Government Benefits', 'gov_benefits', 'money'], ['Miscellaneous expenses', 'misc', 'money'],
        ['Product Research', 'product_research', 'money'], ['Magic Fund', 'magic_fund', 'money'], ['Company Assets', 'company_assets', 'money'],
        ['Executive Benefits', 'executive_benefits', 'money'], ['Office Miscellaneous', 'office_miscellaneous', 'money'],
        ['Maintenance Expenses', 'maintenance_expenses', 'money'], ['Consultants', 'consultants', 'money'],
        ["Manager's Allowance", 'managers_allowance', 'money'], ['Birthday Cake Allowance', 'birthday_cake_allowance', 'money'],
        ['Water Bill', 'water_bill', 'money'], ['Internet', 'internet', 'money'], ['Rent', 'rent', 'money'], ['Electricity', 'electricity', 'money'],
        ['Geniusmakers Management Fee', 'management_fee', 'money'], ['Business Development Fund', 'business_development_fund', 'money'],
        ['HMO Expense', 'hmo_expense', 'money'],
        ['Total Operating Costs', 'operating', 'total'],
        ['NET INCOME', 'net_income', 'net'],
    ];

    /** Money lines added up for the department (the rest are worked out from them). */
    private const SUMMED = ['leads', 'orders', 'gross', 'cancelled', 'returns', 'delivered', 'tax', 'product_cost', 'gross_profit',
        'advertising', 'ads_vat', 'shipping', 'cod', 'vat_on_cod', 'fulfillment', 'selling',
        'salaries', 'communication', 'thirteenth_month', 'sil', 'gov_benefits', 'misc', 'operating', 'net_income'];

    /**
     * @param  Collection<int, User>  $cras
     * @return array{inputs: Collection<int, MniInput>, department_inputs: MniDepartment, cras: array<int, array<string, float|int|null>>, department: array<string, float|int|null>}
     */
    public function for(Collection $cras, CarbonImmutable $month, string $plan): array
    {
        $inputs = MniInput::where('plan', $plan)->whereDate('month', $month)->whereIn('user_id', $cras->pluck('id'))->get()->keyBy('user_id');
        $department = MniDepartment::where('plan', $plan)->whereDate('month', $month)->first() ?? new MniDepartment;
        $craCount = max($cras->count(), 1);

        // CRD - MC: each CRA's own row, and the rest of TOTAL SALARY OF CRD shared evenly.
        $rows = MonthlyCostRow::all();
        $costs = $this->cost->totals($rows);
        $own = $rows->whereIn('user_id', $cras->pluck('id'))->groupBy('user_id')
            ->map(fn (Collection $mine) => $mine->sum(fn (MonthlyCostRow $row) => $row->monthlySalary()));
        $sharedSalary = ($costs['customer_retention'] - $own->sum()) / $craCount;

        $statements = $cras->mapWithKeys(fn (User $cra) => [
            $cra->id => self::statement($inputs->get($cra->id), $department, $craCount, ($own[$cra->id] ?? 0) + $sharedSalary,
                $costs['gov_benefits'] * (self::GOV_BENEFITS_SHARE[$plan] ?? 1), $plan),
        ]);

        $total = [];
        foreach ([...self::SUMMED, ...array_keys(MniDepartment::OPERATING)] as $key) {
            $total[$key] = $statements->sum($key);
        }
        $total['conversion_rate'] = $total['leads'] ? $total['orders'] / $total['leads'] : null;
        $total['aov'] = $total['orders'] ? $total['gross'] / $total['orders'] : null;

        return ['inputs' => $inputs, 'department_inputs' => $department, 'cras' => $statements->all(), 'department' => $total];
    }

    /**
     * One CRA's statement. Empty inputs count as 0. $salaries is the CRA's (own + share); $govBenefits the department's.
     *
     * @return array<string, float|int|null>
     */
    public static function statement(?MniInput $input, MniDepartment $department, int $craCount, float $salaries = 0.0, float $govBenefits = 0.0, string $plan = '1m'): array
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
        $researchIsSelling = in_array($plan, self::PRODUCT_RESEARCH_SELLING, true);
        $research = ($department->product_research ?? 0) / $craCount;
        $selling = $advertising + $shipping + $cod + $vatOnCod + $fulfillment + ($researchIsSelling ? $research : 0);
        $grossProfit = $delivered - $tax - $productCost;

        $operating = [
            'salaries' => $salaries,
            'communication' => ($department->communication_allowance ?? 0) / $craCount,
            'thirteenth_month' => $salaries / 12,
            'sil' => $salaries * self::SIL_DAYS_A_YEAR / self::SIL_WORKING_DAYS,
            'gov_benefits' => $govBenefits / $craCount,
            'misc' => ($department->miscellaneous_expenses ?? 0) / $craCount,
        ];
        foreach (array_keys(MniDepartment::OPERATING) as $field) {
            $operating[$field] = ($department->{$field} ?? 0) / $craCount;
        }
        $operatingTotal = array_sum($operating) - ($researchIsSelling ? $research : 0);

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
            'gross_profit' => $grossProfit,
            'advertising' => $advertising,
            'ads_vat' => 0.0,
            'shipping' => $shipping,
            'cod' => $cod,
            'vat_on_cod' => $vatOnCod,
            'fulfillment' => $fulfillment,
            'selling' => $selling,
            ...$operating,
            'operating' => $operatingTotal,
            'net_income' => $grossProfit - $selling - $operatingTotal,
        ];
    }
}
