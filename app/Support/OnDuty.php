<?php

namespace App\Support;

use App\Models\DutyCount;
use App\Models\DutyLogin;
use App\Models\DutySchedule;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Once;

/**
 * CRAs on duty: the CRAs who logged in from the Segmentation Tracker on a real day (not the working date).
 * The Report Summary divides by this count for Average sales and Average IBSC per CRA.
 */
class OnDuty
{
    /**
     * Today's log-ins, earliest first, with the CRA.
     *
     * @return Collection<int, DutyLogin>
     */
    public static function today(): Collection
    {
        return once(fn () => DutyLogin::whereDate('date', WorkingDate::realToday())
            ->with('user')->orderBy('logged_in_at')->get());
    }

    /**
     * Whether $user is a CRA who hasn't logged in today (the Segmentation Tracker asks them to), unless today is a
     * day off they set in My schedule.
     */
    public static function needsLogin(User $user): bool
    {
        return $user->role?->slug === Role::CRA && ! self::today()->contains('user_id', $user->id)
            && ! DutyGoals::isDayOff($user, WorkingDate::realToday());
    }

    /**
     * The last day a CRA can plan in My schedule: the end of the month after next.
     */
    public static function scheduleUntil(): CarbonImmutable
    {
        return WorkingDate::realToday()->startOfMonth()->addMonths(2)->endOfMonth()->startOfDay();
    }

    /**
     * What My schedule's calendar shows for $cra, from the start of this month to scheduleUntil(): their days off by
     * date (leave / rest), the days they logged in on duty, and today's log-in time.
     *
     * @return array{today: string, from: string, until: string, days_off: array<string, string>, logged_in: list<string>, logged_in_at: ?string}
     */
    public static function calendar(User $cra): array
    {
        $today = WorkingDate::realToday();
        $from = $today->startOfMonth();
        $until = self::scheduleUntil();
        $logins = DutyLogin::where('user_id', $cra->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $today)->get();
        $todayLogin = $logins->first(fn (DutyLogin $login) => $login->date->isSameDay($today));

        return [
            'today' => $today->toDateString(),
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'days_off' => DutySchedule::where('user_id', $cra->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $until)->get()
                ->mapWithKeys(fn (DutySchedule $day) => [$day->date->toDateString() => $day->type])->all(),
            'logged_in' => $logins->map(fn (DutyLogin $login) => $login->date->toDateString())->values()->all(),
            'logged_in_at' => $todayLogin?->logged_in_at->timezone(config('segmentation.timezone'))->format('g:i A'),
        ];
    }

    /**
     * Put $user on today's on-duty list; logging in again keeps the first time.
     */
    public static function logIn(User $user): DutyLogin
    {
        $login = DutyLogin::firstOrCreate(
            ['user_id' => $user->id, 'date' => WorkingDate::realToday()->toDateString()],
            ['logged_in_at' => now()],
        );
        Once::flush();

        return $login;
    }

    /**
     * CRAs on duty for $from–$to: the count for one day; for longer, the average of the days with anyone on duty
     * (days with no count, and days nobody worked, are left out). A day's count entered by hand (DutyCount)
     * is used instead of its log-ins. Null when nobody was on duty.
     */
    public static function countFor(CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $day = fn (string $date) => CarbonImmutable::parse($date)->toDateString();

        $perDay = DutyLogin::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->selectRaw('date, count(*) as n')->groupBy('date')->get()
            ->mapWithKeys(fn ($row) => [$day($row->date) => (int) $row->n]);

        DutyCount::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()
            ->each(function (DutyCount $count) use (&$perDay) {
                $perDay[$count->date->toDateString()] = $count->count;
            });

        $perDay = $perDay->filter();

        return $perDay->isEmpty() ? null : round($perDay->avg(), 2);
    }
}
