<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Once;

/**
 * The day the team is working on, from Settings → Working Date. While set,
 * the whole app treats it as "today" (leads, backlog, Pancake, dashboard), so
 * the team can work through an earlier month's leads. Blank = the real date.
 */
class WorkingDate
{
    public const KEY = 'working_date';

    public static function get(): ?CarbonImmutable
    {
        return once(function () {
            $value = Setting::value(self::KEY);

            return $value ? CarbonImmutable::parse($value)->startOfDay() : null;
        });
    }

    /**
     * Save the working date (null = follow the real date again).
     */
    public static function set(?CarbonImmutable $date, ?User $by = null): void
    {
        Setting::put(self::KEY, $date?->toDateString(), $by);
        Once::flush();
    }

    /**
     * Today's date in the segmentation timezone, ignoring the working date.
     */
    public static function realToday(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(config('segmentation.timezone'))->toDateString());
    }
}
