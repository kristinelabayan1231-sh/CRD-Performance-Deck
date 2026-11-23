<?php

namespace App\Http\Controllers;

use App\Models\RestockReminder;
use App\Models\SmsReply;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * SMS Reminders → Follow-ups (texts still to go: automatic ones 7 days after the last text while the customer hasn't
 * reordered, and ones the CRA scheduled) and No response (no reorder after every automatic follow-up: left for a call).
 * A CRA sees and changes their own customers; sms_reminders.view_all every CRA's.
 */
class SmsFollowUpController extends Controller
{
    public function index(Request $request): View
    {
        $craId = $this->craId($request);
        $today = WorkingDate::realToday();

        $followUps = RestockReminder::with('scheduler')->followUpsToSend()->when($craId, fn (Builder $q) => $q->where('user_id', $craId))
            ->orderBy('remind_on')->orderBy('customer_name')->limit(500)->get();
        $groups = $followUps->groupBy(fn (RestockReminder $followUp) => match (true) {
            $followUp->remind_on->lte($today) => 'Today · '.$today->format('D, M j'),
            $followUp->remind_on->isSameDay($today->addDay()) => 'Tomorrow · '.$today->addDay()->format('D, M j'),
            $followUp->remind_on->lte($today->addDays(7)) => 'Next 7 days',
            default => 'Later',
        });

        return view('sms-reminders.follow-ups', [...$this->shared($craId), 'groups' => $groups, 'today' => $today]);
    }

    public function noResponse(Request $request): View
    {
        $craId = $this->craId($request);
        $rows = RestockReminder::noResponse()->when($craId, fn (Builder $q) => $q->where('user_id', $craId))
            ->orderByDesc('no_response_at')->paginate(50)->withQueryString();
        $keys = $rows->getCollection()->pluck('phone_key');
        $sent = RestockReminder::whereIn('phone_key', $keys)->where('status', RestockReminder::SENT)->selectRaw('phone_key, count(*) as n')->groupBy('phone_key')->pluck('n', 'phone_key');
        $lastReply = SmsReply::whereIn('phone_key', $keys)->latest('received_at')->get(['phone_key', 'body'])->unique('phone_key')->pluck('body', 'phone_key');

        return view('sms-reminders.no-response', [...$this->shared($craId), 'rows' => $rows, 'sent' => $sent, 'lastReply' => $lastReply,
            'canDial' => (bool) $request->user()->call_webhook_url]);
    }

    /**
     * The CRA schedules a follow-up text for a customer on a day, with an optional note and one of their templates. It
     * doesn't use up one of the automatic follow-ups.
     */
    public function store(Request $request, string $phoneKey): RedirectResponse
    {
        $latest = RestockReminder::where('phone_key', $phoneKey)->orderByDesc('remind_on')->orderByDesc('id')->firstOrFail();
        $this->authorizeCra($request->user(), $latest->user_id);
        $data = $this->validated($request, $latest->user_id);

        if (RestockReminder::where('phone_key', $phoneKey)->whereDate('remind_on', $data['day'])->exists()) {
            return back()->withErrors(['day' => 'There\'s already a text for this customer on that day: pick another day.']);
        }

        RestockReminder::create([
            ...$latest->only(['phone_key', 'phone_number', 'customer_name', 'product_id', 'products', 'covers', 'customer_type', 'run_out_on', 'user_id']),
            'remind_on' => $data['day'],
            'kind' => RestockReminder::FOLLOW_UP,
            'follow_up_number' => $latest->follow_up_number,
            'scheduled_by' => $request->user()->id,
            'note' => $data['note'] ?? null,
            'sms_template_id' => $data['template'] ?? null,
            'status' => RestockReminder::PENDING,
        ]);
        RestockReminder::where('phone_key', $phoneKey)->noResponse()->update(['resolved_at' => now()]);

        return back()->with('status', 'Follow-up scheduled for '.CarbonImmutable::parse($data['day'])->format('M j').'.');
    }

    /**
     * Move a follow-up still to send to another day.
     */
    public function update(Request $request, RestockReminder $restockReminder): RedirectResponse
    {
        $this->authorizeFollowUp($request->user(), $restockReminder);
        $data = $request->validate(['day' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.WorkingDate::realToday()->toDateString()]]);

        if (RestockReminder::where('phone_key', $restockReminder->phone_key)->whereKeyNot($restockReminder->id)->whereDate('remind_on', $data['day'])->exists()) {
            return back()->withErrors(['day' => 'There\'s already a text for this customer on that day: pick another day.']);
        }

        $restockReminder->update(['remind_on' => $data['day'], 'status' => RestockReminder::PENDING, 'queued_at' => null]);

        return back()->with('status', 'Follow-up moved to '.$restockReminder->remind_on->format('M j').'.');
    }

    /**
     * Cancel a follow-up still to send.
     */
    public function destroy(Request $request, RestockReminder $restockReminder): RedirectResponse
    {
        $this->authorizeFollowUp($request->user(), $restockReminder);
        $restockReminder->update(['status' => RestockReminder::SKIPPED, 'error' => 'Cancelled by '.$request->user()->displayName(), 'status_updated_by' => $request->user()->id]);

        return back()->with('status', 'Follow-up cancelled.');
    }

    /**
     * @return array{day: string, note?: ?string, template?: ?int}
     */
    private function validated(Request $request, ?int $craId): array
    {
        return $request->validate([
            'day' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.WorkingDate::realToday()->toDateString()],
            'note' => ['nullable', 'string', 'max:200'],
            'template' => ['nullable', 'integer', Rule::exists(SmsTemplate::class, 'id')->where('user_id', $craId)],
        ]);
    }

    /**
     * @return array{cras: Collection<int, User>, craId: ?int, counts: array{follow_ups: int, no_response: int}}
     */
    private function shared(?int $craId): array
    {
        return [
            'cras' => LeadGenerator::cras(),
            'craId' => $craId,
            'counts' => [
                'follow_ups' => RestockReminder::followUpsToSend()->when($craId, fn (Builder $q) => $q->where('user_id', $craId))->count(),
                'no_response' => RestockReminder::noResponse()->when($craId, fn (Builder $q) => $q->where('user_id', $craId))->count(),
            ],
        ];
    }

    private function craId(Request $request): ?int
    {
        $picked = $request->validate(['cra' => ['nullable', 'integer']])['cra'] ?? null;

        return $request->user()->can('sms_reminders.view_all') ? $picked : $request->user()->id;
    }

    private function authorizeFollowUp(User $user, RestockReminder $followUp): void
    {
        abort_unless($followUp->isFollowUp() && in_array($followUp->status, [RestockReminder::PENDING, RestockReminder::QUEUED], true), 404);
        $this->authorizeCra($user, $followUp->user_id);
    }

    private function authorizeCra(User $user, ?int $craId): void
    {
        abort_unless($craId === $user->id || $user->can('sms_reminders.view_all'), 403);
    }
}
