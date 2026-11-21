<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\PhoneDialer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tracker's Call button: sends the lead's number to the signed-in CRA's own phone (their MacroDroid webhook link in
 * Settings → Call Recordings), which dials it on its SIM. The lead goes along so the recording comes back to it.
 */
class LeadCallController extends Controller
{
    /** MacroDroid's webhook links all start with this. */
    public const WEBHOOK_HOST = 'https://trigger.macrodroid.com/';

    public function __invoke(Request $request, Lead $lead, PhoneDialer $dialer): JsonResponse
    {
        $user = $request->user();

        abort_unless($lead->assigned_to === $user->id || $user->can('segmentation.manage'), 403);

        return $dialer->dial($user, $lead->phone_number, $lead->id, $lead->customer_name);
    }
}
