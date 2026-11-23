<?php

namespace App\Http\Controllers;

use App\Models\CallRecording;
use App\Models\SmsReply;
use App\Services\PhoneDialer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SMS Reminders → Replies: texts customers sent back to the CRAs' phones. YES = wants to reorder (the CRA calls them
 * from here), STOP = opted out (already handled), anything else needs the CRA's answer.
 */
class SmsReplyController extends Controller
{
    public function handle(Request $request, SmsReply $smsReply): RedirectResponse
    {
        $this->authorizeReply($request, $smsReply);

        $smsReply->update($smsReply->handled_at
            ? ['handled_at' => null, 'handled_by' => null]
            : ['handled_at' => now(), 'handled_by' => $request->user()->id]);

        return back()->with('status', $smsReply->handled_at ? 'Marked as handled.' : 'Moved back to open replies.');
    }

    /**
     * The Call button: dials the customer on the signed-in CRA's phone, with their lead so the recording comes back to it.
     */
    public function call(Request $request, SmsReply $smsReply, PhoneDialer $dialer): JsonResponse
    {
        $this->authorizeReply($request, $smsReply);
        $customer = $smsReply->reminder?->customer_name ?: 'the customer';
        $lead = $smsReply->cra ? CallRecording::matchLead($smsReply->phone_key, $smsReply->cra) : null;

        return $dialer->dial($request->user(), $smsReply->phone_number, $lead?->id, $customer);
    }

    /**
     * The signed-in CRA's customers who replied YES and are still waiting for a call, for the top bar's alert.
     */
    public function alerts(Request $request): JsonResponse
    {
        return response()->json([
            'count' => SmsReply::wantsToReorder()->where('user_id', $request->user()->id)->count(),
            'url' => route('sms-reminders.replies.index', ['show' => 'reorder']),
        ]);
    }

    private function authorizeReply(Request $request, SmsReply $smsReply): void
    {
        abort_unless($smsReply->user_id === $request->user()->id || $request->user()->can('sms_reminders.view_all'), 403);
    }
}
