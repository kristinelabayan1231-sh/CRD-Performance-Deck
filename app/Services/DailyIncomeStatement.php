<?php

namespace App\Services;

use App\Models\PancakeEngagement;
use App\Models\PcReportEntry;
use App\Models\User;
use App\Support\ProductGroups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Daily I.S.: each CRA's income statement per product (Settings → Pancake Pages → Product groups) for a day, laid
 * out like the CRAs' sheet.
 *
 * Leads/inquiries and engagements = the CRA's PC Report Total Inquiries and Total Engagement on the product's pages.
 * Orders = the CRA's Pancake orders on those pages that day (the PC Report's # of Orders: canceled and deleted left
 * out). Gross Sales = those orders' sales (Shecom's amount once synced). Conversion rate = orders ÷ engagements;
 * AOV = Gross Sales ÷ orders. Every other line is 0 until the MNI is built. The department adds up every CRA.
 */
class DailyIncomeStatement
{
    /** Statement lines below the KPIs, as in the sheet: [label, kind]; kind 'line' (0 for now), 'total', 'section' or 'net'. */
    public const LINES = [
        ['Gross Sales', 'gross'],
        ['Actual Cancelled', 'line'], ['Projected Returns', 'line'], ['Projected Delivered', 'line'], ['Tax Allocation', 'line'],
        ['Product Cost', 'line'], ['Gross Profit', 'total'],
        ['Selling And Marketing', 'section'],
        ['Advertising Cost', 'line'], ['Ads VAT', 'line'], ['Shipping Fee', 'line'], ['COD Fee', 'line'], ['VAT on COD Fee', 'line'],
        ['Fulfillment Fee', 'line'], ['Total Selling Costs', 'total'],
        ['Operating Costs', 'section'],
        ['Salaries', 'line'], ['Communication Allowance', 'line'], ['13th Month Allowance', 'line'], ['SIL', 'line'],
        ['Government Benefits', 'line'], ['Miscellaneous expenses', 'line'], ['Product Research', 'line'], ['Magic Fund', 'line'],
        ['Company Assets', 'line'], ['Executive Benefits', 'line'], ['Office Miscellaneous', 'line'], ['Maintenance Expenses', 'line'],
        ['Consultants', 'line'], ["Manager's Allowance", 'line'], ['Birthday Cake Allowance', 'line'], ['Water Bill', 'line'],
        ['Internet', 'line'], ['Rent', 'line'], ['Electricity', 'line'], ['Geniusmakers Management Fee', 'line'],
        ['Business Development Fund', 'line'], ['HMO Expense', 'line'], ['Total Operating Costs', 'total'],
        ['NET INCOME', 'net'],
    ];

    public function __construct(private PageOrders $pageOrders) {}

    /**
     * @param  Collection<int, User>  $cras
     * @return array{products: list<array{slug: string, name: string}>, cras: array<int, array{total: array<string, int|float>, products: array<string, array<string, int|float>>}>, department: array{total: array<string, int|float>, products: array<string, array<string, int|float>>}, missingPage: bool}
     */
    public function day(Collection $cras, CarbonImmutable $day): array
    {
        $groups = ProductGroups::all();
        $groupOf = collect($groups)->flatMap(fn (array $group) => array_fill_keys($group['pages'], $group['slug']));
        $empty = fn () => ['leads' => 0, 'engagements' => 0, 'orders' => 0, 'gross' => 0.0];

        $figures = [];
        foreach ($cras as $cra) {
            foreach ($groups as $group) {
                $figures[$cra->id][$group['slug']] = $empty();
            }
        }

        PcReportEntry::whereIn('user_id', $cras->pluck('id'))->whereIn('page', $groupOf->keys())->whereDate('date', $day)
            ->get(['user_id', 'page', 'inquiries', 'engagements'])
            ->each(function (PcReportEntry $entry) use (&$figures, $groupOf) {
                $slug = $groupOf[$entry->page];
                $figures[$entry->user_id][$slug]['leads'] += (int) $entry->inquiries;
                $figures[$entry->user_id][$slug]['engagements'] += (int) $entry->engagements;
            });

        ['orders' => $orders, 'missingPage' => $missingPage] = $this->pageOrders->forDay($cras, $groupOf->keys()->all(), $day);
        $craOf = $cras->filter(fn (User $cra) => $cra->pancake_name)->mapWithKeys(fn (User $cra) => [PancakeEngagement::staffKey($cra->pancake_name) => $cra->id]);
        foreach ($orders as $order) {
            $slug = $groupOf[$order['page']];
            $figures[$craOf[$order['seller']]][$slug]['orders']++;
            $figures[$craOf[$order['seller']]][$slug]['gross'] += $order['sales'];
        }

        $statements = collect($figures)->map(fn (array $products) => [
            'total' => self::derive(self::sum($products)),
            'products' => array_map(self::derive(...), $products),
        ]);
        $departmentProducts = collect($groups)->mapWithKeys(fn (array $group) => [
            $group['slug'] => self::sum($statements->pluck('products')->pluck($group['slug'])->all()),
        ])->all();

        return [
            'products' => array_map(fn (array $group) => ['slug' => $group['slug'], 'name' => $group['name']], $groups),
            'cras' => $statements->all(),
            'department' => [
                'total' => self::derive(self::sum($departmentProducts)),
                'products' => array_map(self::derive(...), $departmentProducts),
            ],
            'missingPage' => $missingPage,
        ];
    }

    /**
     * @param  iterable<array<string, int|float>>  $rows
     * @return array<string, int|float>
     */
    private static function sum(iterable $rows): array
    {
        $total = ['leads' => 0, 'engagements' => 0, 'orders' => 0, 'gross' => 0.0];
        foreach ($rows as $row) {
            foreach (array_keys($total) as $key) {
                $total[$key] += $row[$key];
            }
        }

        return $total;
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
