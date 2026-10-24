<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The tracker's Call button: sends the lead's number to the signed-in CRA's own phone (their MacroDroid webhook link in
 * Settings → Call Recordings), which dials it on its SIM. The lead goes along so the recording comes back to it.
 */
class LeadCallController extends Controller
{
    /** MacroDroid's webhook links all start with this. */
    public const WEBHOOK_HOST = 'https://trigger.macrodroid.com/';

    public function __invoke(Request $request, Lead $lead): JsonResponse
    {
        $user = $request->user();

        abort_unless($lead->assigned_to === $user->id || $user->can('segmentation.manage'), 403);

        if (! $user->call_webhook_url) {
            return response()->json(['message' => 'Your phone isn\'t linked yet. Ask a super admin to add it in Settings → Call Recordings.'], 422);
        }

        try {
            $response = Http::connectTimeout(5)->timeout(10)
                ->get($user->call_webhook_url, ['number' => $lead->phone_number, 'lead' => $lead->id]);
        } catch (Throwable $e) {
            report($e);
            $response = null;
        }

        if (! $response?->successful()) {
            return response()->json(['message' => 'Your phone didn\'t answer. Check that it is on, online and MacroDroid is running.'], 502);
        }

        return response()->json(['message' => "Calling {$lead->customer_name} on your phone…"]);
    }
}
