<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Settings → SMS Reminders: whether texts go out at all, the daily automatic send time, and the most texts a CRA's
 * phone sends a day.
 */
class SmsSettings
{
    public const ENABLED = 'sms_reminders.enabled';

    public const SEND_TIME = 'sms_reminders.send_time';

    public const DAILY_CAP = 'sms_reminders.daily_cap';

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

    public static function withinSendingHours(?CarbonImmutable $at = null): bool
    {
        $time = ($at ?? self::now())->format('H:i');
        $hours = config('sms_reminders.sending_hours');

        return $time >= $hours['from'] && $time < $hours['until'];
    }
}
