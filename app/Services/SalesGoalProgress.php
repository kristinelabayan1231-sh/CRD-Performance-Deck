<?php

namespace App\Services;

use App\Models\User;
use App\Support\DashboardRange;
use App\Support\DutyGoals;
use App\Support\SalesGoals;
use Illuminate\Support\Collection;

/**
 * Dashboard results for the picked month (to date) or From–To range.
 *
 * Sales = Conversion Breakdown gross sales (CRD - BROADCAST + CRD - SEGMENTATION orders).
 * Monthly goal: every CRA's sales ÷ the CRD monthly goal (for a custom range that isn't a whole
 * month, the goal is prorated to its days); pace = days gone ÷ days in the month.
 * Per CRA: sales ÷ their goal for the range (DutyGoals: their daily goal, own else the general one, on each day; none on
 * a day off (Rest Day, Absent / Leave), plus a share of the goals of CRAs off on the days they were on duty),
 * Total conv % = (BC + SC orders) ÷ (engagements + leads), and their best-selling product (CraProductSales).
 * Team: confirmed orders, conversion rate and AOV (gross ÷ orders) of the CRAs shown; daily: the same per day, for the graphs.
 */
class SalesGoalProgress
{
    public function __construct(private ConversionBreakdown $breakdown, private CraProductSales $productSales) {}

    /**
     * @param  Collection<int, User>  $shown  CRAs listed per CRA (a CRA sees only themselves)
     * @return array{month: array<string, mixed>, range: DashboardRange, daily: array<string, array<string, int|float|null>>, team: array<string, int|float|null>, cras: Collection<int, array<string, mixed>>, general_daily: float}
     */
    public function for(Collection $shown, DashboardRange $range): array
    {
        $allCras = LeadGenerator::cras();
        $days = $this->breakdown->days($allCras->merge($shown)->unique('id'), $range->from, $range->to);
        $total = fn (User $cra) => ConversionBreakdown::sum($days[$cra->id] ?? []);

        $sales = (float) $allCras->sum(fn (User $cra) => $total($cra)['gross']);
        $month = $range->to->startOfMonth();
        $isMonth = $range->isMonth();
        $goal = SalesGoals::crdMonthly() * ($isMonth ? 1 : $range->days() / $month->daysInMonth);
        $generalDaily = SalesGoals::craDaily();

        $products = $this->productSales->for($shown, $range->from, $range->to);

        // Split among every active CRA; a CRA shown who isn't one (deactivated) keeps their daily goal on every day.
        $dutyGoals = DutyGoals::for($allCras, $range->from, $range->to);

        $cras = $shown->map(function (User $cra) use ($total, $range, $generalDaily, $products, $dutyGoals) {
            $totals = $total($cra);
            $duty = $dutyGoals[$cra->id] ?? ['goal' => SalesGoals::dailyFor($cra, $generalDaily) * $range->days(), 'shared' => 0.0, 'absent_days' => 0, 'rest_days' => 0, 'leave_days' => 0];
            $goal = $duty['goal'];
            $sold = $products[$cra->id] ?? collect();

            return [
                'cra' => $cra,
                'sales' => (float) $totals['gross'],
                'goal' => $goal,
                'own_goal' => $cra->daily_sales_goal !== null,
                // Taken over from CRAs off, and this CRA's days off (Rest Day, Absent / Leave).
                'shared' => $duty['shared'],
                'absent_days' => $duty['absent_days'],
                'rest_days' => $duty['rest_days'],
                'leave_days' => $duty['leave_days'],
                'progress' => SalesGoals::progress((float) $totals['gross'], $goal),
                'totals' => $totals,
                // Best-selling product in these sales: ['name' => …, 'amount' => …] or null.
                'top_product' => $sold->isEmpty() ? null : ['name' => $sold->keys()->first(), 'amount' => $sold->first()],
            ];
        })->sortByDesc('progress')->values();

        // Per day, for the Results graphs: every CRA's gross sales (the CRD goal) and the totals of the CRAs shown.
        $daily = [];
        for ($day = $range->from; $day->lessThanOrEqualTo($range->to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $daily[$key] = [
                'all_gross' => (float) $allCras->sum(fn (User $cra) => $days[$cra->id][$key]['gross'] ?? 0),
                ...ConversionBreakdown::sum($shown->map(fn (User $cra) => $days[$cra->id][$key] ?? null)->filter()),
            ];
        }

        return [
            'range' => $range,
            'daily' => $daily,
            'general_daily' => $generalDaily,
            'team' => ConversionBreakdown::sum($cras->pluck('totals')),
            'month' => [
                'label' => $isMonth ? $month->format('F Y') : $range->label(),
                'prorated' => ! $isMonth,
                'sales' => $sales,
                'goal' => $goal,
                'progress' => SalesGoals::progress($sales, $goal),
                // Where sales should be by the range's last day if spread evenly over the month.
                'pace' => $isMonth ? $range->to->day / $month->daysInMonth : null,
                'day' => $range->to->day,
                'days' => $month->daysInMonth,
                'remaining' => max(0, $goal - $sales),
            ],
            'cras' => $cras,
        ];
    }
}
