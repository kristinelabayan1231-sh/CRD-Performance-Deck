<?php

namespace App\Http\Controllers;

use App\Models\CallRecording;
use App\Models\SmsReply;
use App\Services\LeadGenerator;
use App\Services\PhoneDialer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * SMS Reminders → Replies: texts customers sent back to the CRAs' phones. YES = wants to reorder (the CRA calls them
 * from here), STOP = opted out (already handled), anything else needs the CRA's answer.
 */
class SmsReplyController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'cra' => ['nullable', 'integer'],
            'show' => ['nullable', Rule::in(['reorder', 'open', 'all'])],
        ]);
        $craId = $user->can('sms_reminders.view_all') ? ($filters['cra'] ?? null) : $user->id;
        $show = $filters['show'] ?? 'open';

        $replies = SmsReply::with(['cra', 'reminder', 'handler'])
            ->when($craId, fn (Builder $query) => $query->where('user_id', $craId))
            ->when($show === 'reorder', fn (Builder $query) => $query->wantsToReorder())
            ->when($show === 'open', fn (Builder $query) => $query->whereNull('handled_at')->where('kind', '!=', SmsReply::STOP))
            ->latest('received_at')->latest('id')
            ->paginate(50)->withQueryString();

        return view('sms-reminders.replies', [
            'replies' => $replies,
            'show' => $show,
            'craId' => $craId,
            'cras' => LeadGenerator::cras(),
            'reorderCount' => SmsReply::wantsToReorder()->when($craId, fn (Builder $query) => $query->where('user_id', $craId))->count(),
            'canDial' => (bool) $user->call_webhook_url,
        ]);
    }

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
