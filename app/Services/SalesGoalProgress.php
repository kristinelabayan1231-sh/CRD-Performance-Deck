<?php

namespace App\Services;

use App\Models\User;
use App\Support\SalesGoals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Dashboard progress towards the sales goals in Settings → Sales Goals.
 *
 * Sales = Conversion Breakdown gross sales (CRD - BROADCAST + CRD - SEGMENTATION orders).
 * Monthly: every CRA's sales this month so far ÷ the CRD monthly goal; pace = days gone ÷ days in the month.
 * Daily: each CRA's sales today ÷ their daily goal (their own goal, else the general CRA daily goal).
 */
class SalesGoalProgress
{
    public function __construct(private ConversionBreakdown $breakdown) {}

    /**
     * @param  Collection<int, User>  $shown  CRAs listed for the daily goal (a CRA sees only themselves)
     * @return array{month: array<string, mixed>, today: CarbonImmutable, cras: Collection<int, array<string, mixed>>, general_daily: float}
     */
    public function for(Collection $shown, CarbonImmutable $today): array
    {
        $month = $today->startOfMonth();
        $allCras = LeadGenerator::cras();
        $days = $this->breakdown->days($allCras->merge($shown)->unique('id'), $month, $today);
        $todayKey = $today->toDateString();

        $monthSales = $allCras->sum(fn (User $cra) => ConversionBreakdown::sum($days[$cra->id] ?? [])['gross']);
        $monthlyGoal = SalesGoals::crdMonthly();
        $generalDaily = SalesGoals::craDaily();

        return [
            'today' => $today,
            'general_daily' => $generalDaily,
            'month' => [
                'label' => $month->format('F Y'),
                'sales' => $monthSales,
                'goal' => $monthlyGoal,
                'progress' => SalesGoals::progress($monthSales, $monthlyGoal),
                'pace' => $today->day / $month->daysInMonth,
                'day' => $today->day,
                'days' => $month->daysInMonth,
                'remaining' => max(0, $monthlyGoal - $monthSales),
            ],
            'cras' => $shown->map(function (User $cra) use ($days, $todayKey, $generalDaily) {
                $sales = (float) ($days[$cra->id][$todayKey]['gross'] ?? 0);
                $goal = SalesGoals::dailyFor($cra, $generalDaily);

                return [
                    'cra' => $cra,
                    'sales' => $sales,
                    'goal' => $goal,
                    'own_goal' => $cra->daily_sales_goal !== null,
                    'progress' => SalesGoals::progress($sales, $goal),
                ];
            })->sortByDesc('progress')->values(),
        ];
    }
}
