<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\MonthWeeks;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Conversion Breakdown numbers per CRA per day.
 *
 * Orders BC / SC = the CRA's own Pancake POS orders that day tagged "CRD - BROADCAST" / "CRD - SEGMENTATION"
 * (canceled and deleted orders don't count). Gross BC / SC = those orders' totals.
 * Engagements = the CRA's Pancake customer engagements (Chat → Analytics → Engagements).
 * Leads = Segmentation Tracker leads assigned to the CRA for the lead day worked that day (base 70; actual
 * count shown). With a working date set, that's the lead day the gap points to (Oct 7 → Sept 7).
 * BC conv % = Orders BC ÷ Engagements. SC conv % = Orders SC ÷ Leads.
 * Total conv % = (Orders BC + Orders SC) ÷ (Engagements + Leads). Gross sales = Gross BC + Gross SC.
 */
class ConversionBreakdown
{
    public const COUNTS = ['bc_orders', 'sc_orders', 'engagements', 'leads'];

    /**
     * @param  Collection<int, User>  $cras
     * @return array<int, array<string, array<string, int|float|null>>> cra id => Y-m-d => metrics
     */
    public function days(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $cras->pluck('id');
        $accounts = self::accounts($cras);

        // Leads worked on a real day are from its paired lead day (Settings → Working Date).
        $lag = WorkingDate::lagDays();
        $leads = [];
        Lead::whereIn('assigned_to', $ids)
            ->whereDate('est_out_of_stock_date', '>=', $from->subDays($lag))->whereDate('est_out_of_stock_date', '<=', $to->subDays($lag))
            ->selectRaw('assigned_to as cra, date(est_out_of_stock_date) as day, count(*) as n')->groupBy('cra', 'day')->get()
            ->each(function ($row) use (&$leads, $lag) {
                $leads[(int) $row->cra][CarbonImmutable::parse($row->day)->addDays($lag)->toDateString()] = (int) $row->n;
            });

        $engagements = [];
        PancakeEngagement::whereIn('staff_name', $accounts->keys())
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get()
            ->each(function (PancakeEngagement $row) use (&$engagements, $accounts) {
                $cra = $accounts[$row->staff_name];
                $day = $row->date->toDateString();
                $engagements[$cra][$day] = ($engagements[$cra][$day] ?? 0) + $row->engagements;
            });

        $orders = $this->orders($accounts, $from, $to);

        $result = [];
        foreach ($cras as $cra) {
            for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                $key = $day->toDateString();
                $result[$cra->id][$key] = self::derive([
                    'bc_orders' => $orders[$cra->id][$key]['bc_orders'] ?? 0,
                    'sc_orders' => $orders[$cra->id][$key]['sc_orders'] ?? 0,
                    'bc_gross' => $orders[$cra->id][$key]['bc_gross'] ?? 0.0,
                    'sc_gross' => $orders[$cra->id][$key]['sc_gross'] ?? 0.0,
                    'engagements' => $engagements[$cra->id][$key] ?? 0,
                    'leads' => $leads[$cra->id][$key] ?? 0,
                ]);
            }
        }

        return $result;
    }

    /**
     * Total conv % per CRA for the day, its week (1–7, 8–14… from the 1st) and its month, for the dashboard.
     *
     * @param  Collection<int, User>  $cras
     * @return array<string, array{name: string, label: string, leads_from: ?string, team: array<string, int|float|null>, rows: Collection<int, array{cra: User, totals: array<string, int|float|null>}>}>
     */
    public function periods(Collection $cras, CarbonImmutable $today): array
    {
        $month = $today->startOfMonth();
        $week = MonthWeeks::for($month)[MonthWeeks::containing($month, $today) - 1];
        $monthEnd = $month->endOfMonth()->startOfDay();
        $days = $this->days($cras, $month, $monthEnd);

        // Weeks are fixed 7-day buckets from the 1st (1–7, 8–14 … 29–31); months are whole months.
        $ranges = [
            'today' => ['Today', $today, $today, $today->format('D, M j')],
            'week' => ['Week', $week['start'], $week['end'], 'Week '.$week['number'].' · '.$week['label']],
            'month' => ['Month', $month, $monthEnd, $month->format('F Y')],
        ];

        return collect($ranges)->map(function (array $range) use ($cras, $days) {
            [$name, $from, $to, $label] = $range;
            $rows = $cras->map(fn (User $cra) => [
                'cra' => $cra,
                'totals' => self::sum(array_filter($days[$cra->id] ?? [], fn (string $day) => $day >= $from->toDateString() && $day <= $to->toDateString(), ARRAY_FILTER_USE_KEY)),
            ])->sortByDesc(fn (array $row) => $row['totals']['total_rate'] ?? -1)->values();

            return ['name' => $name, 'label' => $label, 'leads_from' => WorkingDate::leadDaysLabel($from, $to), 'team' => self::sum($rows->pluck('totals')), 'rows' => $rows];
        })->all();
    }

    /**
     * Tagged orders per CRA per day: counts and gross sales for broadcast and segmentation.
     *
     * @param  Collection<string, int>  $accounts  Pancake account name => CRA id
     * @return array<int, array<string, array{bc_orders: int, sc_orders: int, bc_gross: float, sc_gross: float}>>
     */
    public function orders(Collection $accounts, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $out = [];

        PancakeOrder::counted()
            ->whereIn('seller_name', $accounts->keys())
            ->whereIn('conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION])
            ->whereDate('ordered_on', '>=', $from)->whereDate('ordered_on', '<=', $to)
            ->get(['ordered_on', 'seller_name', 'conversion_type', 'total_price'])
            ->each(function (PancakeOrder $order) use (&$out, $accounts) {
                $cra = $accounts[$order->seller_name];
                $day = $order->ordered_on->toDateString();
                $prefix = $order->conversion_type === PancakeOrder::BROADCAST ? 'bc' : 'sc';
                $out[$cra][$day] ??= ['bc_orders' => 0, 'sc_orders' => 0, 'bc_gross' => 0.0, 'sc_gross' => 0.0];
                $out[$cra][$day]["{$prefix}_orders"]++;
                $out[$cra][$day]["{$prefix}_gross"] += (float) $order->total_price;
            });

        return $out;
    }

    /**
     * Normalised Pancake account name => CRA id, for CRAs with an account set.
     *
     * @param  Collection<int, User>  $cras
     * @return Collection<string, int>
     */
    public static function accounts(Collection $cras): Collection
    {
        return $cras->filter(fn (User $cra) => $cra->pancake_name)
            ->mapWithKeys(fn (User $cra) => [PancakeEngagement::staffKey($cra->pancake_name) => $cra->id]);
    }

    /**
     * Add up several days (or CRAs) and work the rates out again from the totals.
     *
     * @param  iterable<array<string, int|float|null>>  $rows
     * @return array<string, int|float|null>
     */
    public static function sum(iterable $rows): array
    {
        $totals = [...array_fill_keys(self::COUNTS, 0), 'bc_gross' => 0.0, 'sc_gross' => 0.0];

        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $row[$key];
            }
        }

        return self::derive($totals);
    }

    /**
     * @param  array{bc_orders: int, sc_orders: int, bc_gross: float, sc_gross: float, engagements: int, leads: int}  $base
     * @return array<string, int|float|null>
     */
    private static function derive(array $base): array
    {
        $orders = $base['bc_orders'] + $base['sc_orders'];
        $reach = $base['engagements'] + $base['leads'];

        return [
            ...$base,
            'orders' => $orders,
            'reach' => $reach,
            'gross' => $base['bc_gross'] + $base['sc_gross'],
            'bc_rate' => $base['engagements'] ? $base['bc_orders'] / $base['engagements'] : null,
            'sc_rate' => $base['leads'] ? $base['sc_orders'] / $base['leads'] : null,
            'total_rate' => $reach ? $orders / $reach : null,
        ];
    }
}
