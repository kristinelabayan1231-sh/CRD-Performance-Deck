<?php

namespace App\Services;

use App\Models\ActualIncomeInput;
use App\Models\ActualIncomeStatement;
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
 *
 * A month taken from the CRD Sales Report sheet (actual_income_statements: January to August 2026) shows the sheet's cards
 * as they are, every line, nothing worked out. A supervisor can type over its lines in edit mode (editSheetLine). Its cards are the CRAs on the sheet, inactive
 * ones included; lines only the sheet had (SHEET_ONLY_LINES) show when a card has an amount on them.
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

    /** Lines only some of the sheet's months have, each shown after a line of LINES: that line's key => [label, key]. */
    public const SHEET_ONLY_LINES = [
        'ads_vat' => ['AI Expense', 'ai_expense'],
        'cod' => ['VAT on COD Fee', 'vat_on_cod'],
        'fulfillment' => ['Product Samples', 'product_samples'],
        'managers_allowance' => ['Training Allowance', 'training_allowance'],
        'electricity' => ['Other Company Car Expenses', 'other_car_expenses'],
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
     * The lines a supervisor can type over on a card of a month taken from the sheet: every count and money line but
     * the worked-out ones (AOV, Unfulfilled Sales), sheet-only lines included.
     *
     * @return list<string>
     */
    public static function sheetInputs(): array
    {
        $keys = collect(self::LINES)
            ->filter(fn (array $line) => in_array($line[2], ['count', 'money'], true) && ! in_array($line[1], ['aov', 'unfulfilled'], true))
            ->pluck(1);

        return [...$keys, ...array_column(self::SHEET_ONLY_LINES, 1)];
    }

    /**
     * A sheet card with one line typed over: the lines worked out from it (Unfulfilled Sales, Gross Profit, the totals,
     * NET INCOME, Conversion Rate, AOV) move with it, so the rest of the sheet's figures stay as they were.
     *
     * @param  array<string, float|int|null>  $figures
     * @return array<string, float|int|null>
     */
    public static function editSheetLine(array $figures, string $key, float $value): array
    {
        $change = $value - (float) ($figures[$key] ?? 0);
        $figures[$key] = $value;
        $move = function (string $total, float $by) use (&$figures): void {
            $figures[$total] = (float) ($figures[$total] ?? 0) + $by;
        };

        $unfulfilled = match ($key) {
            'gross' => $change,
            'cancelled', 'fulfilled', 'returns', 'delivered' => -$change,
            default => 0.0,
        };
        $grossProfit = match ($key) {
            'delivered' => $change,
            'tax', 'product_cost' => -$change,
            default => 0.0,
        };
        $selling = in_array($key, [...self::SELLING, 'ai_expense', 'vat_on_cod', 'product_samples'], true) ? $change : 0.0;
        $operating = in_array($key, [...self::operatingKeys(), 'training_allowance', 'other_car_expenses'], true) ? $change : 0.0;

        $move('unfulfilled', $unfulfilled);
        $move('gross_profit', $grossProfit);
        $move('selling', $selling);
        $move('operating', $operating);
        $move('net_income', $grossProfit - $selling - $operating);

        $orders = (float) ($figures['orders'] ?? 0);
        $leads = (float) ($figures['target_leads'] ?? 0);
        if (in_array($key, ['orders', 'target_leads'], true)) {
            $figures['conversion'] = $leads > 0 ? $orders / $leads : null;
        }
        if (in_array($key, ['orders', 'gross'], true)) {
            $figures['aov'] = $orders > 0 ? (float) ($figures['gross'] ?? 0) / $orders : null;
        }

        return $figures;
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
     * The month's cards. $cras are the CRAs shown (and the number the CRD's costs are shared by), except in a month taken
     * from the sheet, whose cards are the CRAs on it.
     *
     * @param  Collection<int, User>  $cras
     * @return array{shown: Collection<int, User>, fromSheet: bool, lines: list<array{0: string, 1: ?string, 2: string, 3: ?string, 4: ?string}>, department: array<string, float|null>, cras: array<int, array<string, float|null>>, filled: array<int, bool>, typed: array{department: array<string, float>, cras: array<int, array<string, float>>}}
     */
    public function month(Collection $cras, CarbonImmutable $month): array
    {
        $sheet = ActualIncomeStatement::whereDate('month', $month->startOfMonth())->get(['user_id', 'figures']);
        if ($sheet->isNotEmpty()) {
            return self::fromSheet($sheet);
        }

        $typed = ActualIncomeInput::whereDate('month', $month->startOfMonth())
            ->where(fn ($query) => $query->whereNull('user_id')->orWhereIn('user_id', $cras->pluck('id')))
            ->get(['user_id', 'field', 'amount']);
        $department = $typed->whereNull('user_id')->pluck('amount', 'field')->all();
        $craTyped = $cras->mapWithKeys(fn (User $cra) => [$cra->id => $typed->where('user_id', $cra->id)->pluck('amount', 'field')->all()])->all();
        $craCount = max($cras->count(), 1);
        $mni = $this->mni->for(LeadGenerator::cras(), $month->startOfMonth(), '1m')['cras'];

        $cards = $cras->mapWithKeys(fn (User $cra) => [$cra->id => self::craCard($craTyped[$cra->id], $department, $mni[$cra->id] ?? [], $craCount)])->all();

        return [
            'shown' => $cras,
            'fromSheet' => false,
            'lines' => self::LINES,
            'department' => self::departmentCard($cards, $department),
            'cras' => $cards,
            'filled' => array_map(fn (array $amounts) => $amounts !== [], $craTyped),
            'typed' => ['department' => $department, 'cras' => $craTyped],
        ];
    }

    /**
     * A month taken from the sheet: its cards as they are, every line; the sheet-only lines a card has an amount on.
     *
     * @param  Collection<int, ActualIncomeStatement>  $sheet
     * @return array{shown: Collection<int, User>, fromSheet: bool, lines: list<array{0: string, 1: ?string, 2: string, 3: ?string, 4: ?string}>, department: array<string, float|null>, cras: array<int, array<string, float|null>>, filled: array<int, bool>, typed: array{department: array<string, float>, cras: array<int, array<string, float>>}}
     */
    private static function fromSheet(Collection $sheet): array
    {
        $extras = array_column(self::SHEET_ONLY_LINES, 1);
        $card = function (array $figures) use ($extras): array {
            $values = [];
            foreach ([...array_filter(array_column(self::LINES, 1)), ...$extras] as $key) {
                $values[$key] = in_array($key, ['conversion', 'aov'], true)
                    ? (isset($figures[$key]) ? (float) $figures[$key] : null)
                    : (float) ($figures[$key] ?? 0);
            }

            return $values;
        };

        $shown = User::whereIn('id', $sheet->pluck('user_id')->filter())->inCraOrder()->get();
        $cards = $shown->mapWithKeys(fn (User $cra) => [$cra->id => $card($sheet->firstWhere('user_id', $cra->id)->figures)])->all();
        $department = $card($sheet->firstWhere('user_id', null)?->figures ?? []);

        $lines = [];
        foreach (self::LINES as $line) {
            $lines[] = $line;
            $extra = $line[1] === null ? null : (self::SHEET_ONLY_LINES[$line[1]] ?? null);
            if ($extra !== null && collect([$department, ...$cards])->contains(fn (array $values) => $values[$extra[1]] !== 0.0)) {
                $lines[] = [$extra[0], $extra[1], 'money', 'input', 'input'];
            }
        }

        return [
            'shown' => $shown,
            'fromSheet' => true,
            'lines' => $lines,
            'department' => $department,
            'cras' => $cards,
            'filled' => array_fill_keys($shown->pluck('id')->all(), true),
            'typed' => ['department' => [], 'cras' => []],
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
            if ($key === null) {
                continue;
            }
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
            if ($key === null) {
                continue;
            }
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
