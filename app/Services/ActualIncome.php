<?php

namespace App\Services;

use App\Models\ActualIncomeInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Actual Income: the month's real income statement, as in the CRD sheet: the overall CRD card and a card per CRA.
 *
 * CRA card: Target Number of Leads, Number of Orders, Gross / Cancelled / Fulfilled Sales, Returns/Returning,
 * Delivered Sales and Product Cost are typed in. Unfulfilled Sales = Gross − Cancelled − Fulfilled − Returns − Delivered.
 * Conversion Rate = Orders ÷ Target Leads; AOV = Gross Sales ÷ Orders. Tax Allocation, Advertising Cost, Ads VAT,
 * Packaging Costs and Communication Allowance to HMO Expense = the CRD card's ÷ number of CRAs; Product Research = the
 * CRD card's ÷ 3. Shipping Fee = Orders × ₱75, COD Fee = Delivered × 2%, Fulfillment Fee = Orders × ₱25. Salaries, 13th
 * Month Allowance, SIL and Government Benefits = the CRA's 1M Monthly MNI figures for the month (as the Daily I.S.).
 *
 * CRD card: Tax Allocation, Advertising Cost, Ads VAT, Product Research, Packaging Costs and Communication Allowance to
 * HMO Expense are typed in; every other line adds up the CRA cards (rates worked out from the totals).
 *
 * Gross Profit = Delivered − Tax Allocation − Product Cost. Total Selling Costs = Advertising Cost to Packaging Costs.
 * Total Operating Costs = Salaries to HMO Expense. NET INCOME = Gross Profit − Total Selling Costs − Total Operating Costs.
 */
class ActualIncome
{
    public const SHIPPING_PER_ORDER = 75;

    public const FULFILLMENT_PER_ORDER = 25;

    public const COD_RATE = 0.02;

    /** The CRD card's Product Research is split over this many on each CRA card. */
    public const PRODUCT_RESEARCH_SPLIT = 3;

    /**
     * Lines in card order: [label, key, kind, CRD card's source, CRA card's source]. kind: count, percent, money,
     * total, section, net. Source: input (typed in), sum (CRA cards added up), share (CRD ÷ CRAs), research (CRD ÷ 3),
     * mni (Monthly MNI) or formula.
     */
    public const LINES = [
        ['Target Number of Leads in one month', 'target_leads', 'count', 'sum', 'input'],
        ['Number of Orders', 'orders', 'count', 'sum', 'input'],
        ['Conversion Rate', 'conversion', 'percent', 'formula', 'formula'],
        ['Average Order Value', 'aov', 'money', 'formula', 'formula'],
        ['Gross Sales', 'gross', 'money', 'sum', 'input'],
        ['Cancelled Sales', 'cancelled', 'money', 'sum', 'input'],
        ['Unfulfilled Sales', 'unfulfilled', 'money', 'formula', 'formula'],
        ['Fulfilled Sales', 'fulfilled', 'money', 'sum', 'input'],
        ['Returns/Returning', 'returns', 'money', 'sum', 'input'],
        ['Delivered Sales', 'delivered', 'money', 'sum', 'input'],
        ['Tax Allocation', 'tax', 'money', 'input', 'share'],
        ['Product Cost', 'product_cost', 'money', 'sum', 'input'],
        ['Gross Profit', 'gross_profit', 'total', 'formula', 'formula'],
        ['Selling And Marketing', null, 'section', null, null],
        ['Advertising Cost', 'advertising', 'money', 'input', 'share'],
        ['Ads VAT', 'ads_vat', 'money', 'input', 'share'],
        ['Shipping Fee', 'shipping', 'money', 'sum', 'formula'],
        ['COD Fee', 'cod', 'money', 'sum', 'formula'],
        ['Fulfillment Fee', 'fulfillment', 'money', 'sum', 'formula'],
        ['Product Research', 'product_research', 'money', 'input', 'research'],
        ['Packaging Costs', 'packaging', 'money', 'input', 'share'],
        ['Total Selling Costs', 'selling', 'total', 'formula', 'formula'],
        ['Operating Costs', null, 'section', null, null],
        ['Salaries', 'salaries', 'money', 'sum', 'mni'],
        ['13th Month Allowance', 'thirteenth_month', 'money', 'sum', 'mni'],
        ['SIL', 'sil', 'money', 'sum', 'mni'],
        ['Government Benefits', 'gov_benefits', 'money', 'sum', 'mni'],
        ['Communication Allowance', 'communication', 'money', 'input', 'share'],
        ['Miscellaneous expenses', 'misc', 'money', 'input', 'share'],
        ['Magic Fund', 'magic_fund', 'money', 'input', 'share'],
        ['Company Assets', 'company_assets', 'money', 'input', 'share'],
        ['Executive Benefits', 'executive_benefits', 'money', 'input', 'share'],
        ['Office Miscellaneous', 'office_miscellaneous', 'money', 'input', 'share'],
        ['Maintenance Expenses', 'maintenance_expenses', 'money', 'input', 'share'],
        ['Consultants', 'consultants', 'money', 'input', 'share'],
        ["Manager's Allowance", 'managers_allowance', 'money', 'input', 'share'],
        ['Birthday Cake Allowance', 'birthday_cake_allowance', 'money', 'input', 'share'],
        ['Water Bill', 'water_bill', 'money', 'input', 'share'],
        ['Internet', 'internet', 'money', 'input', 'share'],
        ['Rent', 'rent', 'money', 'input', 'share'],
        ['Electricity', 'electricity', 'money', 'input', 'share'],
        ['Geniusmakers Management Fee', 'management_fee', 'money', 'input', 'share'],
        ['Business Development Fund', 'business_development_fund', 'money', 'input', 'share'],
        ['HMO Expense', 'hmo_expense', 'money', 'input', 'share'],
        ['Total Operating Costs', 'operating', 'total', 'formula', 'formula'],
        ['NET INCOME', 'net_income', 'net', 'formula', 'formula'],
    ];

    /** Selling costs, Advertising Cost to Packaging Costs. */
    private const SELLING = ['advertising', 'ads_vat', 'shipping', 'cod', 'fulfillment', 'product_research', 'packaging'];

    public function __construct(private MonthlyMni $mni) {}

    /**
     * The lines typed in on a card: key => label.
     *
     * @return array<string, string>
     */
    public static function inputs(bool $department): array
    {
        $column = $department ? 3 : 4;

        return collect(self::LINES)->filter(fn (array $line) => $line[$column] === 'input')->mapWithKeys(fn (array $line) => [$line[1] => $line[0]])->all();
    }

    /**
     * Operating costs, Salaries to HMO Expense.
     *
     * @return list<string>
     */
    private static function operatingKeys(): array
    {
        $keys = array_column(self::LINES, 1);
        $from = array_search('salaries', $keys, true);

        return array_slice($keys, $from, array_search('operating', $keys, true) - $from);
    }

    /**
     * @param  Collection<int, User>  $cras
     * @return array{department: array<string, float|null>, cras: array<int, array<string, float|null>>, typed: array{department: array<string, float>, cras: array<int, array<string, float>>}}
     */
    public function month(Collection $cras, CarbonImmutable $month): array
    {
        $typed = ActualIncomeInput::whereDate('month', $month->startOfMonth())
            ->where(fn ($query) => $query->whereNull('user_id')->orWhereIn('user_id', $cras->pluck('id')))
            ->get(['user_id', 'field', 'amount']);
        $department = $typed->whereNull('user_id')->pluck('amount', 'field')->all();
        $craCount = max($cras->count(), 1);
        $mni = $this->mni->for(LeadGenerator::cras(), $month->startOfMonth(), '1m')['cras'];

        $cards = $cras->mapWithKeys(fn (User $cra) => [$cra->id => self::craCard(
            $typed->where('user_id', $cra->id)->pluck('amount', 'field')->all(), $department, $mni[$cra->id] ?? [], $craCount,
        )])->all();

        return [
            'department' => self::departmentCard($cards, $department),
            'cras' => $cards,
            'typed' => ['department' => $department, 'cras' => array_map(fn (User $cra) => $typed->where('user_id', $cra->id)->pluck('amount', 'field')->all(), $cras->keyBy('id')->all())],
        ];
    }

    /**
     * @param  array<string, float>  $typed  the CRA's typed-in amounts
     * @param  array<string, float>  $department  the CRD card's typed-in amounts
     * @param  array<string, float|int|null>  $mni  the CRA's 1M Monthly MNI statement
     * @return array<string, float|null>
     */
    private static function craCard(array $typed, array $department, array $mni, int $craCount): array
    {
        $card = [];
        foreach (self::LINES as [, $key, , , $source]) {
            $card[$key] = match ($source) {
                'input' => (float) ($typed[$key] ?? 0),
                'share' => (float) ($department[$key] ?? 0) / $craCount,
                'research' => (float) ($department[$key] ?? 0) / self::PRODUCT_RESEARCH_SPLIT,
                'mni' => (float) ($mni[$key] ?? 0),
                default => null,
            };
        }
        $card['shipping'] = $card['orders'] * self::SHIPPING_PER_ORDER;
        $card['cod'] = $card['delivered'] * self::COD_RATE;
        $card['fulfillment'] = $card['orders'] * self::FULFILLMENT_PER_ORDER;

        return self::derive($card);
    }

    /**
     * @param  array<int, array<string, float|null>>  $cards  the CRA cards
     * @param  array<string, float>  $typed  the CRD card's typed-in amounts
     * @return array<string, float|null>
     */
    private static function departmentCard(array $cards, array $typed): array
    {
        $card = [];
        foreach (self::LINES as [, $key, , $source]) {
            $card[$key] = match ($source) {
                'input' => (float) ($typed[$key] ?? 0),
                'sum' => (float) array_sum(array_column($cards, $key)),
                default => null,
            };
        }

        return self::derive($card);
    }

    /**
     * Work out the rates, Unfulfilled Sales and the totals from a card's lines.
     *
     * @param  array<string, float|null>  $card
     * @return array<string, float|null>
     */
    private static function derive(array $card): array
    {
        $card['conversion'] = $card['target_leads'] > 0 ? $card['orders'] / $card['target_leads'] : null;
        $card['aov'] = $card['orders'] > 0 ? $card['gross'] / $card['orders'] : null;
        $card['unfulfilled'] = $card['gross'] - $card['cancelled'] - $card['fulfilled'] - $card['returns'] - $card['delivered'];
        $card['gross_profit'] = $card['delivered'] - $card['tax'] - $card['product_cost'];
        $card['selling'] = array_sum(array_map(fn (string $key) => $card[$key], self::SELLING));
        $card['operating'] = array_sum(array_map(fn (string $key) => $card[$key], self::operatingKeys()));
        $card['net_income'] = $card['gross_profit'] - $card['selling'] - $card['operating'];

        return $card;
    }
}
