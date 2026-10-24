<?php

namespace App\Support;

use App\Models\DutyLogin;
use App\Models\DutySchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Daily sales goals that follow who is on duty. A CRA is off on a day when:
 *
 * - they set it ahead as Absent / Leave or Rest Day (My schedule, DutySchedule), from the start of the day; or
 * - other CRAs logged in on duty that day (OnDuty) and they didn't (Absent / Leave).
 *
 * Logging in on duty always counts as on duty, a planned day off included. An off CRA has no goal that day and their
 * daily goal is split equally among the CRAs on duty. With no log-ins and no days off, every CRA keeps their own daily
 * goal (before log-ins were kept, today before the first log-in, days ahead).
 *
 * Used by the Dashboard, Running Sales, Segmentation Productivity and the CRA Schedule; the Report Summary and Daily
 * I.S. keep theirs.
 */
class DutyGoals
{
    /**
     * Each CRA's goal for $from–$to: the sum of their goal on every day, the part of it taken over from off CRAs, and
     * their days off (all, Rest Day, Absent / Leave). $cras is every CRA the goals are split among (the active CRAs).
     *
     * @param  Collection<int, User>  $cras
     * @return array<int, array{goal: float, shared: float, absent_days: int, rest_days: int, leave_days: int}>
     */
    public static function for(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $general = SalesGoals::craDaily();
        $daily = $cras->mapWithKeys(fn (User $cra) => [$cra->id => SalesGoals::dailyFor($cra, $general)]);
        $goals = $daily->map(fn () => ['goal' => 0.0, 'shared' => 0.0, 'absent_days' => 0, 'rest_days' => 0, 'leave_days' => 0])->all();

        foreach (self::offDays($cras, $from, $to) as $off) {
            $onDuty = $daily->except(array_keys($off));
            $share = $onDuty->isEmpty() ? 0.0 : $daily->only(array_keys($off))->sum() / $onDuty->count();

            foreach ($daily as $id => $goal) {
                if (isset($off[$id])) {
                    $goals[$id]['absent_days']++;
                    $goals[$id][$off[$id] === DutySchedule::REST ? 'rest_days' : 'leave_days']++;
                } else {
                    $goals[$id]['goal'] += $goal + $share;
                    $goals[$id]['shared'] += $share;
                }
            }
        }

        return $goals;
    }

    /**
     * The CRAs off on each day of $from–$to, by date: CRA id => leave / rest (empty when everyone is on duty).
     *
     * @param  Collection<int, User>  $cras
     * @return array<string, array<int, string>>
     */
    public static function offDays(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $cras->pluck('id')->map(fn ($id) => (int) $id);
        $byDay = fn (string $model) => $model::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereIn('user_id', $ids)
            ->get()
            ->groupBy(fn ($row) => $row->date->toDateString());

        $loggedIn = $byDay(DutyLogin::class)->map(fn (Collection $logins) => $logins->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all());
        $scheduled = $byDay(DutySchedule::class)->map(fn (Collection $days) => $days->mapWithKeys(fn (DutySchedule $day) => [(int) $day->user_id => $day->type]));

        $days = [];
        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $present = $loggedIn[$key] ?? [];
            $planned = collect($scheduled[$key] ?? [])->except($present);

            // Everyone not logged in once anyone has; else only the planned days off.
            $days[$key] = $present === []
                ? $planned->all()
                : $ids->diff($present)->mapWithKeys(fn (int $id) => [$id => $planned[$id] ?? DutySchedule::LEAVE])->all();
        }

        return $days;
    }

    /**
     * Whether $cra set $day as a day off (Absent / Leave or Rest Day).
     */
    public static function isDayOff(User $cra, CarbonImmutable $day): bool
    {
        return DutySchedule::where('user_id', $cra->id)->whereDate('date', $day)->exists();
    }
}
