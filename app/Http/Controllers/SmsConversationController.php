<?php

namespace App\Http\Controllers;

use App\Models\CallRecording;
use App\Models\DoNotText;
use App\Models\RestockReminder;
use App\Models\SmsMessage;
use App\Models\SmsReply;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\PhoneDialer;
use App\Services\SmsConversations;
use App\Services\SmsSender;
use App\Support\SmsSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * SMS Reminders → Conversations: each customer's texts and replies as a chat. The CRA replies from their phone (not
 * counted in the daily limit), calls, marks the customer reordered (follow-ups stop) or schedules a follow-up. A CRA
 * sees their own customers; sms_reminders.view_all sees every CRA's.
 */
class SmsConversationController extends Controller
{
    public function index(Request $request, SmsConversations $conversations): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'cra' => ['nullable', 'integer'],
            'show' => ['nullable', Rule::in(['reply', 'reorder', 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'all' => ['nullable', 'boolean'],
        ]);
        $craId = $user->can('sms_reminders.view_all') ? ($filters['cra'] ?? null) : $user->id;
        $show = $filters['show'] ?? 'reply';

        $threads = $conversations->threads($craId, $show, $filters['q'] ?? null, max(1, $request->integer('page', 1)));
        $phone = $filters['phone'] ?? $threads->first()['phone_key'] ?? null;
        $thread = $phone ? $conversations->thread($phone, (bool) ($filters['all'] ?? false)) : null;

        if ($thread && ! $this->mayOpen($user, $thread['latest'])) {
            $thread = null;
        }

        return view('sms-reminders.conversations', [
            'threads' => $threads,
            'thread' => $thread,
            'phone' => $thread ? $phone : null,
            'show' => $show,
            'craId' => $craId,
            'cras' => LeadGenerator::cras(),
            'canDial' => (bool) $user->call_webhook_url,
            'optedOut' => $thread && DoNotText::has($phone),
            'templates' => $thread ? SmsTemplate::where('user_id', $thread['latest']->user_id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'body']) : collect(),
            'counts' => [
                'reply' => SmsReply::whereNotNull('restock_reminder_id')->whereNull('handled_at')->where('kind', '!=', SmsReply::STOP)
                    ->when($craId, fn ($q) => $q->where('user_id', $craId))->distinct()->count('phone_key'),
                'reorder' => SmsReply::wantsToReorder()->when($craId, fn ($q) => $q->where('user_id', $craId))->distinct()->count('phone_key'),
            ],
        ]);
    }

    /**
     * A text typed in the chat, sent right away from the customer's CRA's phone. Not counted in the daily limit; only in
     * manual_hours, never to a number on the do-not-text list. Their open replies count as answered.
     */
    public function text(Request $request, string $phoneKey, SmsSender $sender): RedirectResponse
    {
        $latest = $this->latestFor($request->user(), $phoneKey);
        $data = $request->validate(['message' => ['required', 'string', 'max:480']], [], ['message' => 'text']);
        $cra = $latest->cra;
        $hours = config('sms_reminders.manual_hours');

        $problem = match (true) {
            ! SmsSettings::enabled() => 'SMS Reminders is turned off in Settings → SMS Reminders, so nothing is sent.',
            ! $cra => 'This customer has no CRA, so there\'s no phone to text from.',
            DoNotText::has($phoneKey) => 'This customer asked not to be texted.',
            ! SmsSettings::withinManualHours() => 'Texts can be sent between '.CarbonImmutable::createFromFormat('H:i', $hours['from'])->format('g:i A')
                .' and '.CarbonImmutable::createFromFormat('H:i', $hours['until'])->format('g:i A').'.',
            default => null,
        };

        if ($problem) {
            return back()->withErrors(['message' => $problem])->withInput();
        }

        $result = $sender->send($cra, $latest->phone_number, trim($data['message']));

        if ($result !== SmsSender::SENT) {
            return back()->withErrors(['message' => SmsSender::explain($result)])->withInput();
        }

        SmsMessage::create([
            'restock_reminder_id' => $latest->id,
            'phone_user_id' => $cra->id,
            'sent_by' => $request->user()->id,
            'phone_number' => $latest->phone_number,
            'body' => trim($data['message']),
            'sent_at' => now(),
        ]);
        $this->answerReplies($phoneKey, $request->user());

        return back();
    }

    /**
     * The customer reordered: follow-ups still waiting are cancelled, they leave No response, their replies are answered.
     */
    public function reordered(Request $request, string $phoneKey): RedirectResponse
    {
        $latest = $this->latestFor($request->user(), $phoneKey);

        $latest->update(['reordered_at' => now()]);
        RestockReminder::where('phone_key', $phoneKey)->where('kind', RestockReminder::FOLLOW_UP)->whereIn('status', [RestockReminder::PENDING, RestockReminder::QUEUED])
            ->update(['status' => RestockReminder::SKIPPED, 'error' => 'Reordered', 'status_updated_by' => $request->user()->id]);
        RestockReminder::where('phone_key', $phoneKey)->noResponse()->update(['resolved_at' => now()]);
        $this->answerReplies($phoneKey, $request->user());

        return back()->with('status', ($latest->customer_name ?: 'The customer').' is marked reordered. No more follow-ups.');
    }

    /**
     * Why a text didn't bring a reorder, typed by the CRA (e.g. "may stocks pa"); empty clears it, so the customer's
     * reply shows again.
     */
    public function reason(Request $request, RestockReminder $restockReminder): RedirectResponse
    {
        abort_unless($this->mayOpen($request->user(), $restockReminder), 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        $restockReminder->update(['no_reorder_reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null]);

        return back()->with('status', 'No-reorder reason saved.');
    }

    /**
     * Mark the customer's open replies answered without texting (e.g. after a call).
     */
    public function answered(Request $request, string $phoneKey): RedirectResponse
    {
        $this->latestFor($request->user(), $phoneKey);
        $this->answerReplies($phoneKey, $request->user());

        return back()->with('status', 'Replies marked answered.');
    }

    /**
     * The Call button: dials the customer on the signed-in user's phone, with their lead so the recording comes back to it.
     */
    public function call(Request $request, string $phoneKey, PhoneDialer $dialer): JsonResponse
    {
        $latest = $this->latestFor($request->user(), $phoneKey);
        $lead = $latest->cra ? CallRecording::matchLead($phoneKey, $latest->cra) : null;

        return $dialer->dial($request->user(), $latest->phone_number, $lead?->id, $latest->customer_name ?: 'the customer');
    }

    private function answerReplies(string $phoneKey, User $by): void
    {
        SmsReply::where('phone_key', $phoneKey)->whereNull('handled_at')->where('kind', '!=', SmsReply::STOP)
            ->update(['handled_at' => now(), 'handled_by' => $by->id]);
    }

    /**
     * The customer's latest text, when $user may act on them (their own customer, or sms_reminders.view_all).
     */
    private function latestFor(User $user, string $phoneKey): RestockReminder
    {
        $latest = RestockReminder::with('cra')->where('phone_key', $phoneKey)->orderByDesc('remind_on')->orderByDesc('id')->firstOrFail();
        abort_unless($this->mayOpen($user, $latest), 403);

        return $latest;
    }

    private function mayOpen(User $user, RestockReminder $latest): bool
    {
        return $latest->user_id === $user->id || $user->can('sms_reminders.view_all');
    }
}
