<?php

namespace App\Console\Commands;

use App\Models\DoNotText;
use App\Models\RestockReminder;
use App\Models\User;
use App\Services\RestockReminders;
use App\Services\SmsSender;
use App\Support\SmsSettings;
use App\Support\WorkingDate;
use Illuminate\Console\Command;

class SendRestockSms extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'The restock text queue: at the send time queue the customers of CRAs with automatic sending on, then send each CRA\'s next queued text from their phone (one a minute per phone)';

    public function handle(RestockReminders $reminders, SmsSender $sender): int
    {
        if (! SmsSettings::enabled()) {
            return self::SUCCESS;
        }

        $now = SmsSettings::now();
        $today = WorkingDate::realToday()->toDateString();

        // Automatic sending: from the send time, today's ticked customers of CRAs who turned it on.
        if ($now->format('H:i') >= SmsSettings::sendTime()) {
            $autoCras = User::where('sms_auto_send', true)->where('is_active', true)->pluck('id');
            RestockReminder::whereDate('remind_on', $today)->where('status', RestockReminder::PENDING)->whereIn('user_id', $autoCras)
                ->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        }

        // Texts a phone couldn't send in time are dropped rather than sent late.
        RestockReminder::where('status', RestockReminder::QUEUED)
            ->whereDate('remind_on', '<', WorkingDate::realToday()->subDays(config('sms_reminders.stale_after_days'))->toDateString())
            ->update(['status' => RestockReminder::FAILED, 'error' => 'Not sent in time']);

        if (! SmsSettings::withinSendingHours($now)) {
            return self::SUCCESS;
        }

        $craIds = RestockReminder::where('status', RestockReminder::QUEUED)->whereNotNull('user_id')->distinct()->pluck('user_id');
        $sent = 0;

        foreach (User::whereIn('id', $craIds)->get() as $cra) {
            if (SmsSettings::sentToday($cra->id) >= SmsSettings::dailyCap()) {
                continue;
            }

            $reminder = RestockReminder::where('user_id', $cra->id)->where('status', RestockReminder::QUEUED)
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('queued_at')->orderBy('id')
                ->first();

            if (! $reminder) {
                continue;
            }

            if (DoNotText::has($reminder->phone_key)) {
                $reminder->update(['status' => RestockReminder::SKIPPED, 'error' => 'Opted out']);

                continue;
            }

            $message = $reminders->messageFor($reminder, $cra);
            $result = $sender->send($cra, $reminder->phone_number, $message);
            $attempts = $reminder->attempts + 1;

            $reminder->update(match (true) {
                $result === SmsSender::SENT => ['status' => RestockReminder::SENT, 'sent_at' => now(), 'message' => $message,
                    'sms_template_id' => $reminders->templateFor($reminder)?->id, 'attempts' => $attempts, 'error' => null],
                $result === SmsSender::NO_ANSWER && $attempts < config('sms_reminders.max_attempts') => [
                    'attempts' => $attempts, 'next_attempt_at' => now()->addMinutes(config('sms_reminders.retry_minutes')),
                    'error' => SmsSender::explain($result)],
                default => ['status' => RestockReminder::FAILED, 'attempts' => $attempts, 'error' => SmsSender::explain($result)],
            });
            $sent += $result === SmsSender::SENT ? 1 : 0;
        }

        if ($sent > 0) {
            $this->info("Sent {$sent} restock texts.");
        }

        return self::SUCCESS;
    }
}
