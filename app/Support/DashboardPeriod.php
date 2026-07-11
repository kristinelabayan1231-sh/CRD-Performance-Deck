<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Boundaries/keys/labels for the three Customers-dashboard periods. "week"
 * reuses the same fixed 7-day block convention as CRA cohorts (WeekBlocks)
 * so "weekly" means the same thing everywhere in the app.
 */
class DashboardPeriod
{
    /** @return array{0: Carbon, 1: Carbon} */
    public static function bounds(string $period, Carbon $anchor): array
    {
        return match ($period) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->startOfDay()],
            'month' => [$anchor->copy()->startOfMonth()->startOfDay(), $anchor->copy()->endOfMonth()->startOfDay()],
            'year' => [$anchor->copy()->startOfYear()->startOfDay(), $anchor->copy()->endOfYear()->startOfDay()],
            default => [WeekBlocks::startOf($anchor), WeekBlocks::endOf($anchor)],
        };
    }

    public static function key(string $period, Carbon $anchor): string
    {
        return match ($period) {
            'day' => $anchor->toDateString(),
            'month' => $anchor->format('Y-m'),
            'year' => $anchor->format('Y'),
            default => WeekBlocks::startOf($anchor)->toDateString(),
        };
    }

    public static function label(string $period, Carbon $anchor): string
    {
        return match ($period) {
            'day' => $anchor->format('M j, Y'),
            'month' => $anchor->format('F Y'),
            'year' => $anchor->format('Y'),
            default => WeekBlocks::label($anchor),
        };
    }
}
