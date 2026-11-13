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
     * Each change of the gap: real date it applies from => days behind. Earlier real days keep the gap they had, so a
     * change never moves which lead day a past day was paired with.
     */
    public const HISTORY = 'working_date.history';

    /**
     * Start the working date at $start tomorrow (today shows the day before),
     * then follow the real date day by day. Null = follow the real date again.
     */
    public static function startTomorrow(?CarbonImmutable $start, ?User $by = null): void
    {
        $today = self::realToday();
        $history = self::history();
        $before = self::lagDays();

        Setting::put(self::KEY, $start?->subDay()->toDateString(), $by);
        Setting::put(self::ANCHOR, $start ? $today->toDateString() : null, $by);
        Once::flush();

        // The first gap ever set covers every earlier day too, as it always has; a later change starts from today.
        if ($history === [] && $before === 0) {
            return;
        }
        $history = $history ?: ['1970-01-01' => $before];
        $history[$today->toDateString()] = self::lagDays();
        ksort($history);
        Setting::put(self::HISTORY, json_encode($history), $by);
        Once::flush();
    }

    /**
     * How many days the working date is behind the real date today (0 when not set).
     * Results (sales, conversion) happen on real days; the leads worked that day
     * are from the lead day this many days earlier.
     */
    public static function lagDays(): int
    {
        $working = self::get();

        return $working ? (int) $working->diffInDays(self::realToday(), false) : 0;
    }

    /**
     * How many days behind the working date was on real day $day: today's gap, or the gap in force that day.
     */
    public static function lagOn(CarbonImmutable $day): int
    {
        $history = self::history();
        if ($history === []) {
            return self::lagDays();
        }

        $date = $day->toDateString();
        $lag = reset($history);
        foreach ($history as $from => $days) {
            if ($from > $date) {
                break;
            }
            $lag = $days;
        }

        return $lag;
    }

    /**
     * The lead day whose leads the CRAs work on real day $day.
     */
    public static function leadDayFor(CarbonImmutable $day): CarbonImmutable
    {
        return $day->subDays(self::lagOn($day));
    }

    /**
     * The real day the CRAs work lead day $leadDay. When the gap grew and two real days share the lead day, the
     * earliest one, or the earliest within $from–$to when given (so a one-day report still finds its leads).
     */
    public static function realDayFor(CarbonImmutable $leadDay, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): CarbonImmutable
    {
        $candidates = collect([...array_values(self::history()), self::lagDays()])->unique()
            ->map(fn (int $lag) => $leadDay->addDays($lag))
            ->filter(fn (CarbonImmutable $day) => self::leadDayFor($day)->isSameDay($leadDay))
            ->sortBy(fn (CarbonImmutable $day) => $day->getTimestamp())->values();

        $inRange = $candidates->filter(fn (CarbonImmutable $day) => ($from === null || $day->greaterThanOrEqualTo($from->startOfDay()))
            && ($to === null || $day->lessThanOrEqualTo($to->endOfDay())));

        return $inRange->first() ?? $candidates->first() ?? $leadDay->addDays(self::lagDays());
    }

    /**
     * @return array<string, int>
     */
    private static function history(): array
    {
        return once(function () {
            $history = json_decode((string) Setting::value(self::HISTORY), true);

            return is_array($history) ? array_map('intval', $history) : [];
        });
    }

    /**
     * "Sep 8" / "Sep 1–7" / "Sep 1 – Oct 1": the lead days paired with real days
     * $from–$to, or null when no working date is set.
     */
    public static function leadDaysLabel(CarbonImmutable $from, ?CarbonImmutable $to = null): ?string
    {
        if (self::lagDays() === 0) {
            return null;
        }

        $to = self::leadDayFor($to ?? $from);
        $from = self::leadDayFor($from);

        return match (true) {
            $from->isSameDay($to) => $from->format('M j'),
            $from->isSameMonth($to) => $from->format('M j').'–'.$to->format('j'),
            default => $from->format('M j').' – '.$to->format('M j'),
        };
    }

    /**
     * Today's date in the segmentation timezone, ignoring the working date.
     */
    public static function realToday(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(config('segmentation.timezone'))->toDateString());
    }
}
