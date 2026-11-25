<?php

namespace App\Console\Commands;

use App\Models\DoNotText;
use App\Models\RestockReminder;
use App\Models\User;
use App\Services\RestockReminders;
use App\Services\SmsSender;
use App\Support\SmsSettings;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendRestockSms extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'The restock text queue: on each CRA\'s schedule queue the customers of CRAs with automatic sending on, then send each CRA\'s next queued text from their phone (one a minute per phone)';

    public function handle(RestockReminders $reminders, SmsSender $sender): int
    {
        if (! SmsSettings::enabled()) {
            return self::SUCCESS;
        }

        $now = SmsSettings::now();

        // Automatic sending: on each CRA's days, from her send time (her own schedule or the default), the ticked customers
        // still to send today (earlier days' carried over) of CRAs who turned it on. Only phones that have confirmed a text
        // went out (SmsSender::NOT_READY).
        $autoCras = User::where('sms_auto_send', true)->where('is_active', true)->whereNotNull('sms_confirmed_at')->get(['id', 'sms_schedule'])
            ->filter(fn (User $cra) => SmsSettings::autoSendsNow($cra, $now))->pluck('id');

        if ($autoCras->isNotEmpty()) {
            RestockReminder::toSendOn(WorkingDate::realToday())->where('status', RestockReminder::PENDING)->whereIn('user_id', $autoCras)
                ->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        }

        // Handed to the phone but never confirmed as sent: the macro isn't set up to confirm, or the SMS failed on the phone.
        RestockReminder::where('status', RestockReminder::QUEUED)->whereNotNull('handed_at')
            ->where('handed_at', '<', now()->subMinutes(config('sms_reminders.confirm_minutes')))
            ->update(['status' => RestockReminder::FAILED, 'error' => 'The phone didn\'t confirm the text went out: check the "Send reminder" macro ends with the HTTP request in Settings → SMS Reminders.']);

        $craIds = RestockReminder::where('status', RestockReminder::QUEUED)->whereNull('handed_at')->whereNotNull('user_id')->distinct()->pluck('user_id');
        $sent = 0;

        // A disabled CRA's phone never sends; their texts go to the customer's new CRA (DisabledCraHandover).
        foreach (User::whereIn('id', $craIds)->where('is_active', true)->get() as $cra) {
            // Each phone only in its CRA's sending windows (her own or the default ones), up to her daily limit.
            $window = SmsSettings::currentWindow($now, $cra);
            if (! $window || SmsSettings::sentToday($cra->id) >= SmsSettings::dailyLimitFor($cra)) {
                continue;
            }

            $ready = fn () => RestockReminder::where('user_id', $cra->id)->where('status', RestockReminder::QUEUED)->whereNull('handed_at')
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
            $kind = $this->nextKind($cra, $window, $now, $ready()->distinct()->pluck('kind')->all());

            // Earlier days' texts carried over go first.
            $reminder = $kind === null ? null : $ready()->where('kind', $kind)->orderBy('remind_on')->orderBy('queued_at')->orderBy('id')->first();

            if (! $reminder) {
                continue;
            }

            if (DoNotText::has($reminder->phone_key)) {
                $reminder->update(['status' => RestockReminder::SKIPPED, 'error' => 'Opted out']);

                continue;
            }

            $message = $reminders->messageFor($reminder, $cra);
            $result = $sender->send($cra, $reminder->phone_number, $message, (string) $reminder->id);
            $attempts = $reminder->attempts + 1;

            $reminder->update(match (true) {
                // A phone marked ready by hand doesn't confirm: sent once it takes the text.
                $result === SmsSender::SENT && $cra->sms_marked_ready_by !== null => ['status' => RestockReminder::SENT, 'handed_at' => now(), 'sent_at' => now(),
                    'message' => $message, 'sms_template_id' => $reminders->templateFor($reminder)?->id, 'attempts' => $attempts, 'error' => null],
                // Sent once the phone confirms it (POST /api/sms-sent); until then it stays Sending….
                $result === SmsSender::SENT => ['handed_at' => now(), 'message' => $message,
                    'sms_template_id' => $reminders->templateFor($reminder)?->id, 'attempts' => $attempts, 'error' => null],
                // Not set up for texts: back to "to send", kept until the phone is ready.
                $result === SmsSender::NOT_READY => ['status' => RestockReminder::PENDING, 'queued_at' => null, 'error' => SmsSender::explain($result)],
                $result === SmsSender::NO_ANSWER && $attempts < config('sms_reminders.max_attempts') => [
                    'attempts' => $attempts, 'next_attempt_at' => now()->addMinutes(config('sms_reminders.retry_minutes')),
                    'error' => SmsSender::explain($result)],
                default => ['status' => RestockReminder::FAILED, 'attempts' => $attempts, 'error' => SmsSender::explain($result)],
            });
            $sent += $result === SmsSender::SENT ? 1 : 0;
        }

        if ($sent > 0) {
            $this->info("Handed {$sent} restock texts to the phones.");
        }

        return self::SUCCESS;
    }

    /**
     * Which kind the phone sends next in this window: list texts and follow-ups each get half the window
     * (SmsSettings::sharePerWindow); a kind with nothing waiting leaves its share to the other. The kind further behind
     * its share goes first, so the two take turns. Null when the window's texts are all sent.
     *
     * @param  array{from: string, until: string}  $window
     * @param  list<string>  $waiting  kinds with a text ready to go
     */
    private function nextKind(User $cra, array $window, CarbonImmutable $now, array $waiting): ?string
    {
        $windowStart = $now->setTimeFromTimeString($window['from'])->utc();
        $sent = RestockReminder::where('user_id', $cra->id)->where('handed_at', '>=', $windowStart)
            ->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');
        $share = SmsSettings::sharePerWindow($cra);

        if ($sent->sum() >= array_sum($share)) {
            return null;
        }

        return collect($share)
            ->filter(fn (int $slots, string $kind) => in_array($kind, $waiting, true)
                && (($sent[$kind] ?? 0) < $slots || array_diff($waiting, [$kind]) === []))
            ->sortBy(fn (int $slots, string $kind) => ($sent[$kind] ?? 0) / max(1, $slots))
            ->keys()->first();
    }
}
