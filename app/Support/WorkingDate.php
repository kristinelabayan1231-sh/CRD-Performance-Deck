<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Once;

/**
 * The day the team is working on, from Settings → Working Date: a fixed gap
 * behind the real date (e.g. one month, to work through a month's backlog).
 * While set, the whole app treats it as "today" (leads, backlog, Pancake,
 * dashboard) and it moves forward one day with each real day. Blank = the
 * real date.
 */
class WorkingDate
{
    /** The working date on the anchor day. */
    public const KEY = 'working_date';

    /** The real date the working date was anchored on. */
    public const ANCHOR = 'working_date.anchor';

    public static function get(): ?CarbonImmutable
    {
        return once(function () {
            $setting = Setting::firstWhere('key', self::KEY);

            if (! $setting?->value) {
                return null;
            }

            // Older saves have no anchor: they were anchored on the day they were saved.
            $anchor = Setting::value(self::ANCHOR)
                ?? $setting->updated_at->timezone(config('segmentation.timezone'))->toDateString();

            return CarbonImmutable::parse($setting->value)->startOfDay()
                ->addDays((int) CarbonImmutable::parse($anchor)->diffInDays(self::realToday(), false));
        });
    }

    /**
     * Start the working date at $start tomorrow (today shows the day before),
     * then follow the real date day by day. Null = follow the real date again.
     */
    public static function startTomorrow(?CarbonImmutable $start, ?User $by = null): void
    {
        Setting::put(self::KEY, $start?->subDay()->toDateString(), $by);
        Setting::put(self::ANCHOR, $start ? self::realToday()->toDateString() : null, $by);
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
