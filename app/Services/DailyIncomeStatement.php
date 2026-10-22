<?php

namespace App\Services;

use App\Models\MniDepartment;
use App\Models\PancakeOrder;
use App\Models\PcReportEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\PancakeAccounts;
use App\Support\ProductGroups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Daily I.S.: each CRA's income statement per product (Settings → Pancake Pages → Product groups there that day) for a
 * day, laid out like the CRAs' sheet. A product card:
 *
 * Leads/inquiries and engagements = the CRA's PC Report Total Inquiries and Total Engagement on the product's pages.
 * Orders = the CRA's Pancake orders on those pages that day, canceled and deleted left out (PC Report's # of Orders).
 * Gross Sales = the sales of every one of those orders but deleted ones (Shecom's amount once synced), canceled included.
 * Conversion rate = orders ÷ engagements; AOV = Gross Sales ÷ orders.
 * Actual Cancelled = those orders now Canceled or Waiting for goods, by their status today: one that comes back
 * leaves an earlier day's Actual Cancelled. Projected Returns = Gross Sales × the month's % Projected Returns (typed once
 * a month, for every card). Projected Delivered = Gross Sales − Actual Cancelled − Projected Returns. Product Cost = the
 * orders' cost as Order Database → Product Report has it (items not on Product Consumption left out). Gross Profit = Projected Delivered − Tax Allocation − Product Cost.
 * Advertising Cost, Ads VAT and VAT on COD Fee = 0. Shipping Fee = orders × ₱75; COD Fee = Projected Delivered × 2.24%;
 * Fulfillment Fee = orders × ₱25.
 * Tax Allocation and Salaries to HMO Expense = the CRA's 1M Monthly MNI figure for the month ÷ 24 working days, then ÷ the
 * day's number of products. NET INCOME = Gross Profit − Total Selling Costs − Total Operating Costs.
 *
 * A CRA's card adds up their product cards (so its Tax Allocation and operating costs are their MNI ÷ 24); the department
 * adds up every CRA.
 */
class DailyIncomeStatement
{
    /** The MNI's monthly figures are spread over this many working days. */
    public const WORKING_DAYS = 24;

    public const SHIPPING_PER_ORDER = 75;

    public const FULFILLMENT_PER_ORDER = 25;

    public const COD_RATE = 0.0224;

    /** % Projected Returns per month (Y-m => percent as typed). */
    public const RETURNS_KEY = 'daily_is.returns_pct';

    /** Operating costs, in statement order: key => label. */
    public const OPERATING = [
        'salaries' => 'Salaries', 'communication' => 'Communication Allowance', 'thirteenth_month' => '13th Month Allowance',
        'sil' => 'SIL', 'gov_benefits' => 'Government Benefits', 'misc' => 'Miscellaneous expenses',
        ...MniDepartment::OPERATING,
    ];

    /** Statement lines below the KPIs, as in the sheet: [label, kind, key]; kind 'line', 'total', 'section' or 'net'. */
    public const LINES = [
        ['Gross Sales', 'line', 'gross'],
        ['Actual Cancelled', 'line', 'cancelled'], ['Projected Returns', 'line', 'returns'], ['Projected Delivered', 'line', 'delivered'],
        ['Tax Allocation', 'line', 'tax'], ['Product Cost', 'line', 'product_cost'], ['Gross Profit', 'total', 'gross_profit'],
        ['Selling And Marketing', 'section', null],
        ['Advertising Cost', 'line', 'advertising'], ['Ads VAT', 'line', 'ads_vat'], ['Shipping Fee', 'line', 'shipping'],
        ['COD Fee', 'line', 'cod'], ['VAT on COD Fee', 'line', 'vat_on_cod'], ['Fulfillment Fee', 'line', 'fulfillment'],
        ['Total Selling Costs', 'total', 'selling'],
        ['Operating Costs', 'section', null],
        ['Salaries', 'line', 'salaries'], ['Communication Allowance', 'line', 'communication'], ['13th Month Allowance', 'line', 'thirteenth_month'],
        ['SIL', 'line', 'sil'], ['Government Benefits', 'line', 'gov_benefits'], ['Miscellaneous expenses', 'line', 'misc'],
        ['Product Research', 'line', 'product_research'], ['Magic Fund', 'line', 'magic_fund'], ['Company Assets', 'line', 'company_assets'],
        ['Executive Benefits', 'line', 'executive_benefits'], ['Office Miscellaneous', 'line', 'office_miscellaneous'],
        ['Maintenance Expenses', 'line', 'maintenance_expenses'], ['Consultants', 'line', 'consultants'],
        ["Manager's Allowance", 'line', 'managers_allowance'], ['Birthday Cake Allowance', 'line', 'birthday_cake_allowance'],
        ['Water Bill', 'line', 'water_bill'], ['Internet', 'line', 'internet'], ['Rent', 'line', 'rent'], ['Electricity', 'line', 'electricity'],
        ['Geniusmakers Management Fee', 'line', 'management_fee'], ['Business Development Fund', 'line', 'business_development_fund'],
        ['HMO Expense', 'line', 'hmo_expense'], ['Total Operating Costs', 'total', 'operating'],
        ['NET INCOME', 'net', 'net_income'],
    ];

    /** Settings → Daily I.S.: the product groups deactivated (shown only on a day they have sales). */
    public const DEACTIVATED_KEY = 'daily_is.deactivated_products';

    /**
     * The product cards in order, each with the full name shown on its card: start of the group's name (lower case, no
     * spaces or symbols) => card name.
     *
     * @var array<string, string>
     */
    private const PRODUCT_ORDER = [
        'clearsight' => 'CLEAR SIGHT EYEDROPS',
        'pterygium' => 'PTERYGIUM EYE DROPS',
        'sinuxyl' => 'SINUXYL NASAL SPRAY',
        'canpro' => 'CANPRO HERBAL DRINK',
        'audi' => 'AUDICURE',
        'ginseng' => 'GINSENG',
        'scarcream' => 'SCAR CREAM',
        'nutrilay' => 'NUTRILAY',
        'gallvex' => 'GALLVEX HERBAL DROPS',
        'primeman' => 'PRIME MAN HERBAL TEA',
        'cystora' => 'CYSTORA',
        'rosesoap' => 'ROSE SOAP',
        'medallion' => 'MEDALLION',
    ];

    /** Figures counted from the day's PC Report entries and orders (the rest are worked out from them). */
    private const COUNTED = ['leads', 'engagements', 'orders', 'gross', 'cancelled', 'product_cost'];

    public function __construct(private PageOrders $pageOrders, private MonthlyMni $mni, private ProductReport $productReport) {}

    /**
     * @param  Collection<int, User>  $cras
     * @return array{products: list<array{slug: string, name: string}>, returnsPct: ?float, cras: array<int, array{total: array<string, int|float|null>, products: array<string, array<string, int|float|null>>}>, department: array{total: array<string, int|float|null>, products: array<string, array<string, int|float|null>>}, missingPage: bool}
     */
    public function day(Collection $cras, CarbonImmutable $day): array
    {
        // A deactivated product (Settings → Daily I.S.) shows only on a day it has sales.
        $groups = self::inProductOrder(ProductGroups::on($day));
        $deactivated = $this->deactivatedSales($groups, $day);
        $groups = array_values(array_filter($groups, fn (array $group) => $deactivated[$group['slug']] ?? true));
        $groupOf = collect($groups)->flatMap(fn (array $group) => array_fill_keys($group['pages'], $group['slug']));
        $empty = fn () => array_fill_keys(self::COUNTED, 0);

        $figures = [];
        foreach ($cras as $cra) {
            $figures[$cra->id] = [];
            foreach ($groups as $group) {
                $figures[$cra->id][$group['slug']] = $empty();
            }
        }

        $this->entries($cras, $groupOf->keys()->all(), $day, $day)
            ->each(function (PcReportEntry $entry) use (&$figures, $groupOf) {
                $slug = $groupOf[$entry->page];
                $figures[$entry->user_id][$slug]['leads'] += (int) $entry->inquiries;
                $figures[$entry->user_id][$slug]['engagements'] += (int) $entry->engagements;
            });

        ['orders' => $orders, 'missingPage' => $missingPage] = $this->pageOrders->forDay($cras, $groupOf->keys()->all(), $day);
        $sellers = PancakeAccounts::craBySeller($cras);
        $craOf = $sellers->map(fn (User $cra) => $cra->id);
        $costs = $orders->isEmpty() ? collect() : $this->productReport->lines($sellers->map(fn (User $cra) => $cra->displayName())->all(), $day, $day)
            ->where('listed', true)->groupBy('order')->map(fn (Collection $lines) => (float) $lines->sum(fn (array $line) => $line['cost'] ?? 0));
        foreach ($orders as $order) {
            $product = &$figures[$craOf[$order['seller']]][$groupOf[$order['page']]];
            $product['orders'] += $order['counted'] ? 1 : 0;
            $product['gross'] += $order['sales'];
            $product['cancelled'] += in_array($order['status'], PancakeOrder::CANCELLED_OR_WAITING_STATUSES, true) ? $order['sales'] : 0;
            $product['product_cost'] += $costs[$order['id']] ?? 0;
            unset($product);
        }

        $returnsPct = self::returnsPct($day);
        $shares = $this->shares($cras, $day, max(count($groups), 1));
        $statements = collect($figures)->map(function (array $products, int $craId) use ($returnsPct, $shares) {
            $products = array_map(fn (array $counted) => self::statement($counted, $shares[$craId], $returnsPct), $products);

            return ['total' => self::sum($products), 'products' => $products];
        });
        $departmentProducts = collect($groups)->mapWithKeys(fn (array $group) => [
            $group['slug'] => self::sum($statements->pluck('products')->pluck($group['slug'])->all()),
        ])->all();

        return [
            'products' => array_map(fn (array $group) => [
                'slug' => $group['slug'], 'name' => self::cardName($group['name']), 'deactivated' => isset($deactivated[$group['slug']]),
            ], $groups),
            'returnsPct' => $returnsPct,
            'cras' => $statements->all(),
            'department' => ['total' => self::sum($departmentProducts), 'products' => $departmentProducts],
            'missingPage' => $missingPage,
        ];
    }

    /**
     * The product groups in PRODUCT_ORDER (a group goes where the start of its name, ignoring case, spaces and symbols,
     * matches: "Audi" is AudiCure); groups not in it come after, in the order saved in Settings.
     *
     * @param  list<array{slug: string, name: string, pages: list<string>, added_on: ?string}>  $groups
     * @return list<array{slug: string, name: string, pages: list<string>, added_on: ?string}>
     */
    public static function inProductOrder(array $groups): array
    {
        $keys = array_keys(self::PRODUCT_ORDER);
        $position = fn (string $name) => ($key = self::productKey($name)) === null ? count($keys) : array_search($key, $keys, true);

        return collect($groups)->values()
            ->sortBy(fn (array $group, int $saved) => [$position($group['name']), $saved])
            ->values()->all();
    }

    /**
     * The product groups deactivated in Settings → Daily I.S., by name in lower case.
     *
     * @return list<string>
     */
    public static function deactivated(): array
    {
        return array_values(json_decode((string) Setting::value(self::DEACTIVATED_KEY), true) ?? []);
    }

    /**
     * @param  list<string>  $groups  product group names
     */
    public static function saveDeactivated(array $groups, User $by): void
    {
        Setting::put(self::DEACTIVATED_KEY, json_encode(array_values(array_unique(array_map(fn (string $name) => Str::lower($name), $groups)))), $by);
    }

    /**
     * The deactivated groups among $groups => whether they have sales on $day: any CRA's order on their pages (deleted
     * left out), whichever CRAs are on screen.
     *
     * @param  list<array{slug: string, name: string, pages: list<string>, added_on: ?string}>  $groups
     * @return array<string, bool>
     */
    private function deactivatedSales(array $groups, CarbonImmutable $day): array
    {
        $names = self::deactivated();
        $off = array_values(array_filter($groups, fn (array $group) => in_array(Str::lower($group['name']), $names, true)));
        if ($off === []) {
            return [];
        }

        $groupOf = collect($off)->flatMap(fn (array $group) => array_fill_keys($group['pages'], $group['slug']));
        $sold = $this->pageOrders->forDay(LeadGenerator::cras(), $groupOf->keys()->all(), $day)['orders']
            ->filter(fn (array $order) => $order['sales'] > 0)
            ->map(fn (array $order) => $groupOf[$order['page']])->unique()->all();

        return collect($off)->mapWithKeys(fn (array $group) => [$group['slug'] => in_array($group['slug'], $sold, true)])->all();
    }

    /**
     * The full name on a group's card (PRODUCT_ORDER), or the group's own name when it isn't listed there.
     */
    public static function cardName(string $group): string
    {
        $key = self::productKey($group);

        return $key === null ? $group : self::PRODUCT_ORDER[$key];
    }

    private static function productKey(string $group): ?string
    {
        $squashed = preg_replace('/[^a-z0-9]/', '', strtolower($group));

        return collect(array_keys(self::PRODUCT_ORDER))->first(fn (string $key) => str_starts_with($squashed, $key));
    }

    /**
     * The month's % Projected Returns (5 = 5%), or null when nobody set it (counted as 0).
     */
    public static function returnsPct(CarbonImmutable $day): ?float
    {
        $value = (json_decode((string) Setting::value(self::RETURNS_KEY), true) ?? [])[$day->format('Y-m')] ?? null;

        return $value === null ? null : (float) $value;
    }

    /**
     * Set (or, with null, clear) a month's % Projected Returns.
     */
    public static function saveReturnsPct(CarbonImmutable $month, ?float $percent, User $by): void
    {
        $all = json_decode((string) Setting::value(self::RETURNS_KEY), true) ?? [];
        $all[$month->format('Y-m')] = $percent;

        Setting::put(self::RETURNS_KEY, json_encode(array_filter($all, fn ($value) => $value !== null)), $by);
    }

    /**
     * Each CRA's Tax Allocation and operating costs for one product card: their 1M Monthly MNI figure for the day's month
     * ÷ 24 working days ÷ $products. The MNI is worked out for every active CRA, as on its own page.
     *
     * @param  Collection<int, User>  $cras
     * @return array<int, array<string, float>> cra id => key => amount
     */
    private function shares(Collection $cras, CarbonImmutable $day, int $products): array
    {
        $mni = $this->mni->for(LeadGenerator::cras(), $day->startOfMonth(), '1m')['cras'];

        return $cras->mapWithKeys(fn (User $cra) => [$cra->id => collect(['tax', ...array_keys(self::OPERATING)])
            ->mapWithKeys(fn (string $key) => [$key => (float) ($mni[$cra->id][$key] ?? 0) / self::WORKING_DAYS / $products])
            ->all()])->all();
    }

    /**
     * One product card from its counted figures, the CRA's per-product shares and the month's % Projected Returns.
     *
     * @param  array<string, int|float>  $counted
     * @param  array<string, float>  $shares
     * @return array<string, int|float|null>
     */
    private static function statement(array $counted, array $shares, ?float $returnsPct): array
    {
        $returns = $counted['gross'] * ($returnsPct ?? 0) / 100;
        $delivered = $counted['gross'] - $counted['cancelled'] - $returns;
        $grossProfit = $delivered - $shares['tax'] - $counted['product_cost'];

        $selling = [
            'advertising' => 0.0,
            'ads_vat' => 0.0,
            'shipping' => $counted['orders'] * self::SHIPPING_PER_ORDER,
            'cod' => $delivered * self::COD_RATE,
            'vat_on_cod' => 0.0,
            'fulfillment' => $counted['orders'] * self::FULFILLMENT_PER_ORDER,
        ];
        $operating = collect(self::OPERATING)->map(fn (string $label, string $key) => $shares[$key])->all();

        return self::derive([
            ...$counted,
            'returns' => $returns,
            'delivered' => $delivered,
            'tax' => $shares['tax'],
            'gross_profit' => $grossProfit,
            ...$selling,
            'selling' => array_sum($selling),
            ...$operating,
            'operating' => array_sum($operating),
            'net_income' => $grossProfit - array_sum($selling) - array_sum($operating),
        ]);
    }

    /**
     * Each CRA's total leads/inquiries and engagements over $from–$to, every product added up (Report Summary).
     *
     * @param  Collection<int, User>  $cras
     * @return array<int, array{leads: int, engagements: int}> cra id => totals
     */
    public function reach(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pages = collect(ProductGroups::all())->pluck('pages')->flatten()->all();
        $totals = $cras->mapWithKeys(fn (User $cra) => [$cra->id => ['leads' => 0, 'engagements' => 0]])->all();

        foreach ($this->entries($cras, $pages, $from, $to) as $entry) {
            $totals[$entry->user_id]['leads'] += (int) $entry->inquiries;
            $totals[$entry->user_id]['engagements'] += (int) $entry->engagements;
        }

        return $totals;
    }

    /**
     * The CRAs' PC Report Total Inquiries / Total Engagement on $pages from $from to $to.
     *
     * @param  Collection<int, User>  $cras
     * @param  list<string>  $pages
     * @return Collection<int, PcReportEntry>
     */
    private function entries(Collection $cras, array $pages, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return PcReportEntry::whereIn('user_id', $cras->pluck('id'))->whereIn('page', $pages)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get(['user_id', 'page', 'inquiries', 'engagements']);
    }

    /**
     * Add up statements (a CRA's products, or the CRAs) and work the rates out again from the totals.
     *
     * @param  iterable<array<string, int|float|null>>  $statements
     * @return array<string, int|float|null>
     */
    private static function sum(iterable $statements): array
    {
        $keys = [...self::COUNTED, ...array_values(array_filter(array_column(self::LINES, 2)))];
        $total = array_fill_keys(array_unique($keys), 0);
        foreach ($statements as $statement) {
            foreach (array_keys($total) as $key) {
                $total[$key] += $statement[$key];
            }
        }

        return self::derive($total);
    }

    /**
     * Add the conversion rate (orders ÷ engagements) and AOV (Gross Sales ÷ orders); null when there is nothing to divide by.
     *
     * @param  array<string, int|float>  $figures
     * @return array<string, int|float|null>
     */
    private static function derive(array $figures): array
    {
        return [
            ...$figures,
            'conversion' => $figures['engagements'] ? $figures['orders'] / $figures['engagements'] : null,
            'aov' => $figures['orders'] ? $figures['gross'] / $figures['orders'] : null,
        ];
    }
}
