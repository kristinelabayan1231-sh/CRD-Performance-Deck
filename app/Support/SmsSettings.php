<?php

namespace App\Support;

use App\Models\RestockReminder;
use App\Models\Setting;
use App\Models\SmsMessage;
use Carbon\CarbonImmutable;

/**
 * Settings → SMS Reminders: whether texts go out at all, the daily automatic send time, the windows texts go out in,
 * and the most texts a CRA's phone sends a day.
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

    public static function withinSendingHours(?CarbonImmutable $at = null): bool
    {
        $time = ($at ?? self::now())->format('H:i');

        return collect(self::windows())->contains(fn (array $window) => $time >= $window['from'] && $time < $window['until']);
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
     * Texts a CRA's phone has sent today (Manila): reminders and follow-ups typed by hand, against the daily cap.
     */
    public static function sentToday(int $craId): int
    {
        $dayStart = self::now()->startOfDay()->utc();

        // Handed to the phone today (sent or waiting for its confirmation).
        return RestockReminder::where('user_id', $craId)->where(fn ($query) => $query->where('handed_at', '>=', $dayStart)->orWhere('sent_at', '>=', $dayStart))->count()
            + SmsMessage::where('phone_user_id', $craId)->where('sent_at', '>=', $dayStart)->count();
    }

    /**
     * "9:00–11:00 AM and 2:00–4:00 PM".
     */
    public static function windowsLabel(): string
    {
        $format = fn (string $time) => CarbonImmutable::createFromFormat('H:i', $time)->format('g:i A');

        return collect(self::windows())->map(fn (array $window) => $format($window['from']).'–'.$format($window['until']))->join(', ', ' and ');
    }
}
