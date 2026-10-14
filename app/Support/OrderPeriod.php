<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The dates Order Database and Product Performance show: a day, a week (Monday–Sunday), a month, the last
 * 3 months up to today, or a From–To range. Days after today are left out.
 */
class OrderPeriod
{
    /**
     * @param  array{date?: ?string, month?: ?string, from?: ?string, to?: ?string}  $filters  validated query
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function resolve(string $period, array $filters, CarbonImmutable $today): array
    {
        $anchor = CarbonImmutable::parse($filters['date'] ?? $filters['from'] ?? $today->toDateString())->min($today);

        return match ($period) {
            'day' => [$anchor, $anchor],
            'week' => [$anchor->startOfWeek(CarbonImmutable::MONDAY), $anchor->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()->min($today)],
            'month' => [$month = CarbonImmutable::parse(($filters['month'] ?? $anchor->format('Y-m')).'-01'), $month->endOfMonth()->startOfDay()->min($today)],
            'quarter' => [$today->subMonths(3)->addDay(), $today],
            default => [($range = DashboardRange::fromFilters($filters, $today))->from, $range->to],
        };
    }

    public static function label(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return (new DashboardRange($from, $to, $from->startOfMonth(), true))->label();
    }
}
