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

    /** The phone has never confirmed a text went out (no test text came back yet), so nothing is handed to it. */
    public const NOT_READY = 'not_ready';

    /** The ref a test text carries, so its confirmation marks the phone ready. */
    public const TEST_REF = 'test';

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
     * Hand a text to the CRA's phone. SENT means MacroDroid took it, not that it went out: the "Send reminder" macro
     * confirms that by posting $ref back (POST /api/sms-sent). A phone that never confirmed a text gets only the test
     * text ($ref TEST_REF) until one comes back.
     *
     * @return self::SENT|self::NO_LINK|self::UNKNOWN_LINK|self::NO_ANSWER|self::NOT_READY
     */
    public function send(User $cra, string $number, string $message, ?string $ref = null): string
    {
        $link = self::linkFor($cra);

        if (! $link) {
            return self::NO_LINK;
        }

        if ($cra->sms_confirmed_at === null && $ref !== self::TEST_REF) {
            return self::NOT_READY;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(10)
                ->get($link, array_filter(['number' => CallRecording::dialNumber($number), 'message' => $message, 'ref' => $ref]));
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
            self::NOT_READY => 'This phone isn\'t set up for texts yet: send a test text from Settings → SMS Reminders and check it arrives.',
            default => 'Sent.',
        };
    }
}
