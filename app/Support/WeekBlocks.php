<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Months are sliced into fixed 7-day blocks starting on day 1
 * (1–7, 8–14, 15–21, 22–28, 29–end) — not calendar weeks.
 */
class WeekBlocks
{
    public static function startOf(Carbon $date): Carbon
    {
        $blockIndex = intdiv($date->day - 1, 7);

        return $date->copy()->startOfMonth()->addDays($blockIndex * 7)->startOfDay();
    }

    public static function endOf(Carbon $date): Carbon
    {
        $start = self::startOf($date);
        $monthEnd = $date->copy()->endOfMonth()->startOfDay();
        $candidateEnd = $start->copy()->addDays(6);

        return $candidateEnd->greaterThan($monthEnd) ? $monthEnd : $candidateEnd;
    }

    public static function label(Carbon $date): string
    {
        $start = self::startOf($date);
        $end = self::endOf($date);

        return $start->format('M j') . '–' . $end->format($start->month === $end->month ? 'j, Y' : 'M j, Y');
    }
}
