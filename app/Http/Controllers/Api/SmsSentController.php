<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RestockReminder;
use App\Models\User;
use App\Services\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/sms-sent from a CRA's phone (the "Send reminder" macro, after its Send SMS step): the ref the app sent with the
 * text, with the phone's upload token (Settings → Call Recordings) as the Bearer token. Marks that restock text Sent, and
 * the phone ready for texts (a test text's ref is "test"; any text confirms the phone); a phone marked ready by hand then
 * waits for each text's confirmation too.
 */
class SmsSentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $phoneOwner = User::findByRecordingToken($request->bearerToken());

        if (! $phoneOwner) {
            return response()->json(['message' => 'Unknown or missing upload token.'], 401);
        }

        $data = $request->validate(['ref' => ['nullable', 'string', 'max:40']]);
        $ref = $data['ref'] ?? null;

        // The phone confirms its texts itself from now on (no longer counted as sent on hand-over).
        $phoneOwner->forceFill(['sms_confirmed_at' => $phoneOwner->sms_confirmed_at ?? now(), 'sms_marked_ready_by' => null])->save();

        if ($ref === null || $ref === '' || $ref === SmsSender::TEST_REF || ! ctype_digit($ref)) {
            return response()->json(['message' => 'Phone confirmed.'], 200);
        }

        $confirmed = RestockReminder::whereKey((int) $ref)->where('user_id', $phoneOwner->id)
            ->whereIn('status', [RestockReminder::QUEUED, RestockReminder::FAILED])->whereNotNull('handed_at')
            ->update(['status' => RestockReminder::SENT, 'sent_at' => now(), 'error' => null]);

        return $confirmed
            ? response()->json(['message' => 'Text marked sent.'], 200)
            : response()->json(['message' => 'No text of this phone is waiting with that ref; ignored.'], 202);
    }
}
