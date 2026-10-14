<?php

namespace App\Support;

use App\Models\DutyCount;
use App\Models\DutyLogin;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Once;

/**
 * CRAs on duty: the CRAs who logged in from the Segmentation Tracker on a real day (not the working date).
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
     * Whether $user is a CRA who hasn't logged in today (the Segmentation Tracker asks them to).
     */
    public static function needsLogin(User $user): bool
    {
        return $user->role?->slug === Role::CRA && ! self::today()->contains('user_id', $user->id);
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
