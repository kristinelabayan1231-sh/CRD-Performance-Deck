<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Weekly Segmentation weeks: always counted from the 1st of the month in
 * 7-day blocks (1–7, 8–14, 15–21, 22–28), with the rest of the month
 * (29–30/31) as the last week.
 */
class MonthWeeks
{
    /**
     * @return list<array{number: int, start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public static function for(CarbonImmutable $month): array
    {
        $first = $month->startOfMonth()->startOfDay();
        $last = $first->endOfMonth()->startOfDay();
        $weeks = [];

        for ($start = $first, $n = 1; $start->lessThanOrEqualTo($last); $start = $start->addDays(7), $n++) {
            $end = $start->addDays(6)->min($last);
            $weeks[] = [
                'number' => $n,
                'start' => $start,
                'end' => $end,
                'label' => $start->equalTo($end) ? $start->format('M j') : $start->format('M j').'–'.$end->format('j'),
            ];
        }

        return $weeks;
    }

    /**
     * The week of $month that contains $day, or the first week.
     */
    public static function containing(CarbonImmutable $month, CarbonImmutable $day): int
    {
        foreach (self::for($month) as $week) {
            if ($day->betweenIncluded($week['start'], $week['end'])) {
                return $week['number'];
            }
        }

        return 1;
    }
}
