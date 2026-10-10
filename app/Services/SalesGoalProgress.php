<?php

namespace App\Services;

use App\Models\User;
use App\Support\DashboardRange;
use App\Support\SalesGoals;
use Illuminate\Support\Collection;

/**
 * Dashboard results for the picked month (to date) or From–To range.
 *
 * Sales = Conversion Breakdown gross sales (CRD - BROADCAST + CRD - SEGMENTATION orders).
 * Monthly goal: every CRA's sales ÷ the CRD monthly goal (for a custom range that isn't a whole
 * month, the goal is prorated to its days); pace = days gone ÷ days in the month.
 * Per CRA: sales ÷ their daily goal (own, else the general CRA daily goal) × the days in the range,
 * and Total conv % = (BC + SC orders) ÷ (engagements + leads).
 * Team: confirmed orders, conversion rate and AOV (gross ÷ orders) of the CRAs shown.
 */
class SalesGoalProgress
{
    public function __construct(private ConversionBreakdown $breakdown) {}

    /**
     * @param  Collection<int, User>  $shown  CRAs listed per CRA (a CRA sees only themselves)
     * @return array{month: array<string, mixed>, range: DashboardRange, team: array<string, int|float|null>, cras: Collection<int, array<string, mixed>>, general_daily: float}
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

        $cras = $shown->map(function (User $cra) use ($total, $range, $generalDaily) {
            $totals = $total($cra);
            $goal = SalesGoals::dailyFor($cra, $generalDaily) * $range->days();

            return [
                'cra' => $cra,
                'sales' => (float) $totals['gross'],
                'goal' => $goal,
                'own_goal' => $cra->daily_sales_goal !== null,
                'progress' => SalesGoals::progress((float) $totals['gross'], $goal),
                'totals' => $totals,
            ];
        })->sortByDesc('progress')->values();

        return [
            'range' => $range,
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
