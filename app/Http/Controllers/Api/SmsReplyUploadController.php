<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerLink;
use App\Models\DoNotText;
use App\Models\RestockReminder;
use App\Models\SmsReply;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\RestockReminders;
use App\Services\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/sms-replies from a CRA's phone (MacroDroid "Forward replies" macro, on every text received): the sender's
 * number and the text, with the phone's upload token (Settings → Call Recordings) as the Bearer token. Texts from
 * numbers with no restock text are ignored, so personal texts never reach the app. STOP puts the number on the
 * do-not-text list, takes it off any text still to go, and gets one confirmation.
 */
class SmsReplyUploadController extends Controller
{
    public function __invoke(Request $request, SmsSender $sender, RestockReminders $reminders): JsonResponse
    {
        $phoneOwner = User::findByRecordingToken($request->bearerToken());

        if (! $phoneOwner) {
            return response()->json(['message' => 'Unknown or missing upload token.'], 401);
        }

        $data = $request->validate([
            'number' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        $phoneKey = CustomerLink::primaryFor(LeadGenerator::normalizePhone($data['number']));
        $reminder = RestockReminder::where('phone_key', $phoneKey)->where('status', RestockReminder::SENT)->latest('sent_at')->first();

        $kind = SmsReply::kindOf($data['message']);

        if (! $reminder) {
            // A reply to a test text (Settings → SMS Reminders) within a day is saved too, so the setup can be checked;
            // a test STOP doesn't opt the tester's number out.
            $testCra = SmsReply::testCraFor($phoneKey);

            if (! $testCra) {
                return response()->json(['message' => 'Not a reply to a restock text or a test text; ignored.'], 202);
            }

            SmsReply::create(['user_id' => $testCra, 'phone_key' => $phoneKey, 'phone_number' => $data['number'],
                'body' => $data['message'], 'kind' => $kind, 'received_at' => now()]);

            return response()->json(['message' => 'Test reply saved.', 'kind' => $kind], 201);
        }

        $reply = SmsReply::create([
            'user_id' => $reminder->user_id ?? $phoneOwner->id,
            'restock_reminder_id' => $reminder->id,
            'phone_key' => $phoneKey,
            'phone_number' => $data['number'],
            'body' => $data['message'],
            'kind' => $kind,
            'received_at' => now(),
        ]);

        if ($kind === SmsReply::STOP && ! DoNotText::has($phoneKey)) {
            DoNotText::create(['phone_key' => $phoneKey, 'reason' => 'Replied '.strtoupper(trim($data['message']))]);
            RestockReminder::where('phone_key', $phoneKey)->changeable()
                ->update(['status' => RestockReminder::SKIPPED, 'error' => 'Opted out']);
            $sender->send($phoneOwner, $data['number'], $reminders->fill(config('sms_reminders.stop_confirmation'), $reminder, $phoneOwner, false));
            $reply->update(['handled_at' => now()]);
        }

        return response()->json(['message' => 'Reply saved.', 'kind' => $kind], 201);
    }
}
