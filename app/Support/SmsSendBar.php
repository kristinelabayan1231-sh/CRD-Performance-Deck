<?php

namespace App\Support;

use App\Models\Role;
use App\Models\SmsReply;
use App\Models\User;
use App\Services\RestockReminders;
use App\Services\SmsSender;

/**
 * The tracker's sending bar (Segmentation and Exclusive Lead Tracker): one CRA's texts today, the same figures and
 * controls as Today's texts (sent, replied YES, unticked, failed; windows, automatic sending, daily limit, Send now).
 */
class SmsSendBar
{
    /**
     * The bar for whose list is shown: the CRA themselves, or the CRA a supervisor picked. Null when nobody's texts apply
     * (no SMS Reminders, or a supervisor looking at every CRA).
     *
     * @return array{cra: User, counts: array<string, int>, wantsToReorder: int, sentToday: int, enabled: bool, phoneReady: bool, linked: bool}|null
     */
    public static function for(User $viewer, ?User $cra): ?array
    {
        if (! $viewer->can('sms_reminders.view')) {
            return null;
        }
        $cra ??= $viewer->role?->slug === Role::CRA ? $viewer : null;
        if (! $cra || (! $cra->is($viewer) && ! $viewer->can('sms_reminders.view_all'))) {
            return null;
        }

        $today = WorkingDate::realToday();
        $linked = SmsSender::linkFor($cra) !== null;

        return [
            'cra' => $cra,
            'counts' => app(RestockReminders::class)->dayCounts($today, $cra->id),
            'wantsToReorder' => SmsReply::wantsToReorder()->where('user_id', $cra->id)->distinct()->count('phone_key'),
            'sentToday' => SmsSettings::sentToday($cra->id),
            'enabled' => SmsSettings::enabled(),
            'phoneReady' => $linked && $cra->sms_confirmed_at !== null,
            'linked' => $linked,
        ];
    }
}
