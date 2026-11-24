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

    public static function dailyCap(): int
    {
        return (int) Setting::value(self::DAILY_CAP, config('sms_reminders.daily_cap'));
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
     * hasn't set one (custom false).
     *
     * @return array{custom: bool, send_time: string, windows: list<array{from: string, until: string}>, days: list<int>}
     */
    public static function scheduleFor(?User $cra): array
    {
        $own = $cra?->sms_schedule;

        if (is_array($own) && filled($own['send_time'] ?? null) && ! empty($own['windows']) && ! empty($own['days'])) {
            return ['custom' => true, 'send_time' => $own['send_time'], 'windows' => array_values($own['windows']), 'days' => array_map('intval', $own['days'])];
        }

        return ['custom' => false, 'send_time' => self::sendTime(), 'windows' => self::windows(), 'days' => self::EVERY_DAY];
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
