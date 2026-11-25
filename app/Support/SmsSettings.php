<?php

namespace App\Support;

use App\Models\RestockReminder;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Settings → SMS Reminders: whether texts go out at all, the default automatic send time and windows texts go out in
 * (a CRA can set her own in SMS Reminders → Schedule), and the most texts a CRA's phone sends a day.
 */
class SmsSettings
{
    public const ENABLED = 'sms_reminders.enabled';

    public const SEND_TIME = 'sms_reminders.send_time';

    public const DAILY_CAP = 'sms_reminders.daily_cap';

    /** The general text for customers whose product already ran out (RestockReminders::isOverdue). */
    public const OVERDUE_BODY = 'sms_reminders.overdue_body';

    /** JSON list of {from, until} (HH:MM, Manila). */
    public const WINDOWS = 'sms_reminders.windows';

    /** Off until a supervisor turns it on. */
    public static function enabled(): bool
    {
        return Setting::value(self::ENABLED) === '1';
    }

    /** HH:MM, Manila. */
    public static function sendTime(): string
    {
        return (string) Setting::value(self::SEND_TIME, config('sms_reminders.send_time'));
    }

    public static function overdueBody(): string
    {
        return (string) (Setting::value(self::OVERDUE_BODY) ?: config('sms_reminders.overdue_body'));
    }

    /** The most texts any CRA's phone sends a day (Settings → SMS Reminders). */
    public static function dailyCap(): int
    {
        return (int) Setting::value(self::DAILY_CAP, config('sms_reminders.daily_cap'));
    }

    /**
     * The daily limit the CRA set for her own phone (Today's texts, Schedule), or null when she follows the default.
     */
    public static function ownDailyLimit(?User $cra): ?int
    {
        $own = $cra?->sms_schedule['daily_limit'] ?? null;

        return $own === null ? null : (int) $own;
    }

    /**
     * The most texts the CRA's phone sends a day: her own limit, never above the default.
     */
    public static function dailyLimitFor(?User $cra): int
    {
        return min(self::ownDailyLimit($cra) ?? self::dailyCap(), self::dailyCap());
    }

    /**
     * The CRA's saved schedule with $limit as her daily limit (null = the default), to save on users.sms_schedule.
     *
     * @param  array<string, mixed>|null  $schedule
     * @return array<string, mixed>|null
     */
    public static function withDailyLimit(?array $schedule, ?int $limit): ?array
    {
        unset($schedule['daily_limit']);
        $schedule = $limit === null ? ($schedule ?? []) : [...($schedule ?? []), 'daily_limit' => $limit];

        return $schedule === [] ? null : $schedule;
    }

    /**
     * Now in Manila.
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('segmentation.timezone'));
    }

    /**
     * The windows texts go out in (Manila), earliest first.
     *
     * @return list<array{from: string, until: string}>
     */
    public static function windows(): array
    {
        $saved = json_decode((string) Setting::value(self::WINDOWS), true);

        return is_array($saved) && $saved !== [] ? $saved : config('sms_reminders.sending_windows');
    }

    /** Days of the week (ISO, Monday = 1) the default schedule sends on: every day. */
    public const EVERY_DAY = [1, 2, 3, 4, 5, 6, 7];

    /**
     * A CRA's schedule for automatic texts: her own (SMS Reminders → Schedule), or the default from Settings when she
     * hasn't set one (custom false). windows are the ones texts go out in; all_windows has those she turned off too.
     *
     * @return array{custom: bool, send_time: string, windows: list<array{from: string, until: string}>, all_windows: list<array{from: string, until: string}>, windows_off: list<int>, days: list<int>}
     */
    public static function scheduleFor(?User $cra): array
    {
        $own = $cra?->sms_schedule;
        $custom = is_array($own) && filled($own['send_time'] ?? null) && ! empty($own['windows']) && ! empty($own['days']);
        $all = $custom ? array_values($own['windows']) : self::windows();
        $off = self::windowsOff($cra, count($all));

        return [
            'custom' => $custom,
            'send_time' => $custom ? $own['send_time'] : self::sendTime(),
            'windows' => array_values(array_diff_key($all, array_flip($off))),
            'all_windows' => $all,
            'windows_off' => $off,
            'days' => $custom ? array_map('intval', $own['days']) : self::EVERY_DAY,
        ];
    }

    /**
     * Which of the CRA's $count windows she turned off (0 = first). Never all of them: with none left on (e.g. the default
     * went down to one window) they all count.
     *
     * @return list<int>
     */
    private static function windowsOff(?User $cra, int $count): array
    {
        $off = array_values(array_intersect(array_map('intval', (array) ($cra?->sms_schedule['windows_off'] ?? [])), range(0, max(0, $count - 1))));

        return count($off) < $count ? $off : [];
    }

    /**
     * The CRA's saved schedule with these windows turned off, to save on users.sms_schedule.
     *
     * @param  array<string, mixed>|null  $schedule
     * @param  list<int>  $off
     * @return array<string, mixed>|null
     */
    public static function withWindowsOff(?array $schedule, array $off): ?array
    {
        unset($schedule['windows_off']);
        $schedule = $off === [] ? ($schedule ?? []) : [...($schedule ?? []), 'windows_off' => array_values($off)];

        return $schedule === [] ? null : $schedule;
    }

    /**
     * Texts go out only in the sending windows: the CRA's own when given, else the default ones.
     */
    public static function withinSendingHours(?CarbonImmutable $at = null, ?User $cra = null): bool
    {
        $time = ($at ?? self::now())->format('H:i');
        $windows = $cra ? self::scheduleFor($cra)['windows'] : self::windows();

        return collect($windows)->contains(fn (array $window) => $time >= $window['from'] && $time < $window['until']);
    }

    /**
     * The sending window (the CRA's own or the default) $at is in, or null outside them.
     *
     * @return array{from: string, until: string}|null
     */
    public static function currentWindow(CarbonImmutable $at, ?User $cra = null): ?array
    {
        $time = $at->format('H:i');
        $windows = $cra ? self::scheduleFor($cra)['windows'] : self::windows();

        return collect($windows)->first(fn (array $window) => $time >= $window['from'] && $time < $window['until']);
    }

    /**
     * Texts a phone sends in one window: the CRA's daily limit shared between her windows (300 over two = 150 each, 25 =
     * 13; the day's total still stops at the limit; a window turned off leaves the whole limit to the other), half for list texts and half for follow-ups (sharePerWindow).
     */
    public static function perWindow(?User $cra = null): int
    {
        $windows = $cra ? self::scheduleFor($cra)['windows'] : self::windows();

        return (int) ceil(self::dailyLimitFor($cra) / max(1, count($windows)));
    }

    /**
     * Each kind's share of a window: list texts (reminder) get the odd one over.
     *
     * @return array{reminder: int, follow_up: int}
     */
    public static function sharePerWindow(?User $cra = null): array
    {
        $perWindow = self::perWindow($cra);

        return [RestockReminder::REMINDER => $perWindow - intdiv($perWindow, 2), RestockReminder::FOLLOW_UP => intdiv($perWindow, 2)];
    }

    /**
     * Whether a CRA's ticked customers are queued automatically now: on one of her days, from her send time.
     */
    public static function autoSendsNow(User $cra, ?CarbonImmutable $at = null): bool
    {
        $at ??= self::now();
        $schedule = self::scheduleFor($cra);

        return in_array($at->dayOfWeekIso, $schedule['days'], true) && $at->format('H:i') >= $schedule['send_time'];
    }

    /**
     * "Mon–Sat · from 9:30 AM · 9:00–11:00 AM and 2:00–4:00 PM".
     */
    public static function scheduleLabel(?User $cra): string
    {
        $schedule = self::scheduleFor($cra);

        return self::daysLabel($schedule['days']).' · from '.self::timeLabel($schedule['send_time']).' · '.self::windowsLabel($schedule['windows']);
    }

    /**
     * "Every day", "Mon–Sat", "Mon, Wed, Fri".
     *
     * @param  list<int>  $days
     */
    public static function daysLabel(array $days): string
    {
        sort($days);
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        if ($days === self::EVERY_DAY) {
            return 'Every day';
        }

        $isRun = count($days) > 2 && end($days) - reset($days) === count($days) - 1;

        return $isRun ? $names[reset($days)].'–'.$names[end($days)] : collect($days)->map(fn (int $day) => $names[$day])->join(', ');
    }

    /**
     * "9:30 AM".
     */
    public static function timeLabel(string $time): string
    {
        return CarbonImmutable::createFromFormat('H:i', $time)->format('g:i A');
    }

    /**
     * Whether a follow-up typed by hand may go out now (config sms_reminders.manual_hours).
     */
    public static function withinManualHours(?CarbonImmutable $at = null): bool
    {
        $time = ($at ?? self::now())->format('H:i');
        $hours = config('sms_reminders.manual_hours');

        return $time >= $hours['from'] && $time < $hours['until'];
    }

    /**
     * Restock texts and follow-ups a CRA's phone has sent today (Manila), against the daily cap. Texts typed by hand
     * (Conversations, the Text button) are replies like a chat and don't count.
     */
    public static function sentToday(int $craId): int
    {
        $dayStart = self::now()->startOfDay()->utc();

        // Handed to the phone today (sent or waiting for its confirmation).
        return RestockReminder::where('user_id', $craId)->where(fn ($query) => $query->where('handed_at', '>=', $dayStart)->orWhere('sent_at', '>=', $dayStart))->count();
    }

    /**
     * "9:00 AM–11:00 AM and 2:00 PM–4:00 PM": the given windows, else the default ones.
     *
     * @param  list<array{from: string, until: string}>|null  $windows
     */
    public static function windowsLabel(?array $windows = null): string
    {
        return collect($windows ?? self::windows())->map(fn (array $window) => self::timeLabel($window['from']).'–'.self::timeLabel($window['until']))->join(', ', ' and ');
    }
}
