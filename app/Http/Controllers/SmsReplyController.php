<?php

namespace App\Http\Controllers;

use App\Models\SmsReply;
use App\Services\LeadGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * SMS Reminders → Replies: texts customers sent back to the CRAs' phones. YES = wants to reorder, STOP = opted out
 * (already handled), anything else needs the CRA's answer.
 */
class SmsReplyController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'cra' => ['nullable', 'integer'],
            'show' => ['nullable', Rule::in(['open', 'all'])],
        ]);
        $craId = $user->can('sms_reminders.view_all') ? ($filters['cra'] ?? null) : $user->id;

        $replies = SmsReply::with(['cra', 'reminder', 'handler'])
            ->when($craId, fn (Builder $query) => $query->where('user_id', $craId))
            ->when(($filters['show'] ?? 'open') === 'open', fn (Builder $query) => $query->whereNull('handled_at')->where('kind', '!=', SmsReply::STOP))
            ->latest('received_at')->latest('id')
            ->paginate(50)->withQueryString();

        return view('sms-reminders.replies', [
            'replies' => $replies,
            'filters' => $filters,
            'craId' => $craId,
            'cras' => LeadGenerator::cras(),
        ]);
    }

    public function handle(Request $request, SmsReply $smsReply): RedirectResponse
    {
        abort_unless($smsReply->user_id === $request->user()->id || $request->user()->can('sms_reminders.view_all'), 403);

        $smsReply->update($smsReply->handled_at
            ? ['handled_at' => null, 'handled_by' => null]
            : ['handled_at' => now(), 'handled_by' => $request->user()->id]);

        return back()->with('status', $smsReply->handled_at ? 'Marked as handled.' : 'Moved back to open replies.');
    }
}
