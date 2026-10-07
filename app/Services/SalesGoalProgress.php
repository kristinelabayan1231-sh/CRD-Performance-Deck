<?php

namespace App\Services;

use App\Models\User;
use App\Support\MonthWeeks;
use App\Support\SalesGoals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Dashboard progress towards the sales goals in Settings → Sales Goals.
 *
 * Sales = Conversion Breakdown gross sales (CRD - BROADCAST + CRD - SEGMENTATION orders).
 * Monthly: every CRA's sales this month so far ÷ the CRD monthly goal; pace = days gone ÷ days in the month.
 * Per CRA: each CRA's sales for the day, its week (1–7, 8–14 … 29–31) or its month ÷ their daily goal (their
 * own goal, else the general CRA daily goal) × the days in that range.
 */
class SalesGoalProgress
{
    public function __construct(private ConversionBreakdown $breakdown) {}

    /**
     * @param  Collection<int, User>  $shown  CRAs listed for the daily goal (a CRA sees only themselves)
     * @param  string  $period  today | week | month: the range the per-CRA goals cover (the day, its week or its month)
     * @return array{month: array<string, mixed>, today: CarbonImmutable, range: array{label: string, from: CarbonImmutable, to: CarbonImmutable, days: int}, cras: Collection<int, array<string, mixed>>, general_daily: float}
     */
    public function for(Collection $shown, CarbonImmutable $today, string $period = 'today'): array
    {
        $month = $today->startOfMonth();
        $allCras = LeadGenerator::cras();
        $monthEnd = $month->endOfMonth()->startOfDay();
        $days = $this->breakdown->days($allCras->merge($shown)->unique('id'), $month, $monthEnd);
        $monthToDate = fn (array $cra) => array_filter($cra, fn (string $day) => $day <= $today->toDateString(), ARRAY_FILTER_USE_KEY);

        // Weeks are fixed 7-day buckets from the 1st (1–7, 8–14 … 29–31); months are whole months.
        $week = MonthWeeks::for($month)[MonthWeeks::containing($month, $today) - 1];
        [$from, $to, $label] = match ($period) {
            'week' => [$week['start'], $week['end'], 'Week '.$week['number'].' · '.$week['label']],
            'month' => [$month, $monthEnd, $month->format('F Y')],
            default => [$today, $today, $today->format('D, M j')],
        };
        $rangeDays = (int) $from->diffInDays($to) + 1;
        $inRange = fn (array $cra) => array_filter($cra, fn (string $day) => $day >= $from->toDateString() && $day <= $to->toDateString(), ARRAY_FILTER_USE_KEY);

        $monthSales = $allCras->sum(fn (User $cra) => ConversionBreakdown::sum($monthToDate($days[$cra->id] ?? []))['gross']);
        $monthlyGoal = SalesGoals::crdMonthly();
        $generalDaily = SalesGoals::craDaily();

        return [
            'today' => $today,
            'range' => ['label' => $label, 'from' => $from, 'to' => $to, 'days' => $rangeDays],
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
            'cras' => $shown->map(function (User $cra) use ($days, $inRange, $rangeDays, $generalDaily) {
                $sales = (float) ConversionBreakdown::sum($inRange($days[$cra->id] ?? []))['gross'];
                $goal = SalesGoals::dailyFor($cra, $generalDaily) * $rangeDays;

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
