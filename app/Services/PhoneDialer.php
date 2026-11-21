<?php

namespace App\Services;

use App\Models\CallRecording;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dials a number on the signed-in CRA's own phone: sends it to their MacroDroid Call webhook (Settings → Call
 * Recordings), which dials it on the phone's SIM. The lead goes along so the call's recording comes back to it.
 * Used by the trackers' Call button and the SMS Reminders replies.
 */
class PhoneDialer
{
    public function dial(User $caller, string $phone, ?int $leadId, string $customerName): JsonResponse
    {
        if (! $caller->call_webhook_url) {
            return response()->json(['message' => 'Your phone isn\'t linked yet. Ask a super admin to add it in Settings → Call Recordings.'], 422);
        }

        try {
            $response = Http::connectTimeout(5)->timeout(10)
                ->get($caller->call_webhook_url, array_filter(['number' => CallRecording::dialNumber($phone), 'lead' => $leadId]));
        } catch (Throwable $e) {
            report($e);
            $response = null;
        }

        // MacroDroid answers 404 for a link it doesn't know, e.g. one typed out with a character missing.
        if ($response?->notFound()) {
            return response()->json(['message' => 'MacroDroid doesn\'t know your phone link. Copy it again from the Webhook trigger on your phone and save it in Settings → Call Recordings.'], 502);
        }

        if (! $response?->successful()) {
            return response()->json(['message' => 'Your phone didn\'t answer. Check that it is on, online and MacroDroid is running.'], 502);
        }

        return response()->json(['message' => "Calling {$customerName} on your phone…"]);
    }
}
