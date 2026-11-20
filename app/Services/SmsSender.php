<?php

namespace App\Services;

use App\Http\Controllers\LeadCallController;
use App\Models\CallRecording;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends a text from a CRA's own phone: calls the "Send reminder" macro's MacroDroid webhook with the number and the
 * message, and MacroDroid sends it from the phone's SIM. Its link is the CRA's phone link (Settings → Call Recordings)
 * with the last part swapped for the sms identifier, so nothing new is typed in the app.
 */
class SmsSender
{
    public const SENT = 'sent';

    /** The CRA has no phone link in Settings → Call Recordings. */
    public const NO_LINK = 'no_link';

    /** MacroDroid doesn't know the link: the "Send reminder" macro isn't on the phone. */
    public const UNKNOWN_LINK = 'unknown_link';

    /** The phone didn't answer: off, offline or MacroDroid stopped. */
    public const NO_ANSWER = 'no_answer';

    public static function linkFor(User $cra): ?string
    {
        $link = $cra->call_webhook_url;

        if (! $link || ! str_starts_with($link, LeadCallController::WEBHOOK_HOST)) {
            return null;
        }

        $path = trim(substr(strtok($link, '?'), strlen(LeadCallController::WEBHOOK_HOST)), '/');
        $device = explode('/', $path)[0];

        return $device === '' ? null : LeadCallController::WEBHOOK_HOST.$device.'/'.config('sms_reminders.webhook_identifier');
    }

    /**
     * @return self::SENT|self::NO_LINK|self::UNKNOWN_LINK|self::NO_ANSWER
     */
    public function send(User $cra, string $number, string $message): string
    {
        $link = self::linkFor($cra);

        if (! $link) {
            return self::NO_LINK;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(10)
                ->get($link, ['number' => CallRecording::dialNumber($number), 'message' => $message]);
        } catch (Throwable $e) {
            report($e);

            return self::NO_ANSWER;
        }

        return match (true) {
            $response->notFound() => self::UNKNOWN_LINK,
            $response->successful() => self::SENT,
            default => self::NO_ANSWER,
        };
    }

    /**
     * What a send result means, for the CRA.
     */
    public static function explain(string $result): string
    {
        return match ($result) {
            self::NO_LINK => 'No phone link: add it in Settings → Call Recordings.',
            self::UNKNOWN_LINK => 'The phone has no "Send reminder" macro (MacroDroid webhook "'.config('sms_reminders.webhook_identifier').'").',
            self::NO_ANSWER => 'The phone didn\'t answer: check it is on, online and MacroDroid is running.',
            default => 'Sent.',
        };
    }
}
