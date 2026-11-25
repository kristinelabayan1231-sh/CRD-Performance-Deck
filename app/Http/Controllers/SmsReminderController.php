<?php

namespace App\Http\Controllers;

use App\Models\DoNotText;
use App\Models\RestockReminder;
use App\Models\SmsMessage;
use App\Models\SmsReply;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\RestockReminders;
use App\Services\SmsSender;
use App\Support\SmsSettings;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * SMS Reminders: a real day's texts to the customers on the CRAs' tracker lists for its lead day (RestockReminders),
 * for one CRA or all. The CRA unticks customers to leave them out, presses Send now or lets automatic sending
 * queue them at the send time; reminders:send then sends one a minute from the CRA's phone.
 */
class SmsReminderController extends Controller
{
    /** How many customers a page can show; the first is the default. */
    private const PER_PAGE = [20, 50, 100];

    public function index(Request $request, RestockReminders $reminders): View
    {
        $user = $request->user();
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cra' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['send', 'follow', 'sent', 'out', 'failed'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
        ]);
        $perPage = (int) ($filters['per_page'] ?? self::PER_PAGE[0]);
        $day = CarbonImmutable::parse($filters['date'] ?? $today->toDateString());
        $cras = LeadGenerator::cras();
        $craId = $this->craScope($user, $filters['cra'] ?? null);

        // The day's customers, and those from earlier days still to send (carried over until sent).
        $base = RestockReminder::shownOn($day)
            ->when($craId, fn (Builder $query) => $query->where('user_id', $craId));
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $rows = (clone $base)
            ->when(($filters['status'] ?? null) === 'send', fn (Builder $query) => $query->whereIn('status', [RestockReminder::PENDING, RestockReminder::QUEUED]))
            ->when(($filters['status'] ?? null) === 'follow', fn (Builder $query) => $query->where('kind', RestockReminder::FOLLOW_UP))
            ->when(($filters['status'] ?? null) === 'sent', fn (Builder $query) => $query->where('status', RestockReminder::SENT))
            ->when(($filters['status'] ?? null) === 'out', fn (Builder $query) => $query->whereIn('status', [RestockReminder::EXCLUDED, RestockReminder::SKIPPED]))
            ->when(($filters['status'] ?? null) === 'failed', fn (Builder $query) => $query->where('status', RestockReminder::FAILED))
            ->with(['cra', 'scheduler'])->withCount('manualMessages')->withMax('manualMessages', 'sent_at')
            ->orderByRaw("case status when 'queued' then 0 when 'pending' then 1 when 'failed' then 2 when 'sent' then 3 when 'excluded' then 4 else 5 end")
            // The same order Send now takes them in when it sends only some: earlier days' first.
            ->orderBy('remind_on')->orderBy('customer_name')->orderBy('id')
            ->paginate($perPage)->withQueryString();

        // Each customer's last text before this day.
        $lastTexted = RestockReminder::whereIn('phone_key', $rows->getCollection()->pluck('phone_key'))->where('status', RestockReminder::SENT)
            ->whereDate('remind_on', '<', $day->toDateString())->selectRaw('phone_key, max(sent_at) as last')->groupBy('phone_key')->pluck('last', 'phone_key');

        $rows->getCollection()->each(function (RestockReminder $reminder) use ($reminders) {
            $reminder->preview = $reminder->message ?? $reminders->messageFor($reminder);
            // The Text button's dropdown starts on the template the customer's reminder uses.
            $reminder->template_choice = $reminders->templateFor($reminder)?->id;
        });

        $craForActions = $craId ? $cras->firstWhere('id', $craId) : null;

        return view('sms-reminders.index', [
            'day' => $day,
            'today' => $today,
            'isToday' => $day->isSameDay($today),
            'filters' => $filters,
            'cras' => $cras,
            'craId' => $craId,
            'cra' => $craForActions,
            // The CRA's phone today, against her daily limit.
            'sentToday' => $craForActions ? SmsSettings::sentToday($craForActions->id) : 0,
            'rows' => $rows,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE,
            'lastTexted' => $lastTexted,
            'counts' => [
                'toSend' => (int) (($counts[RestockReminder::PENDING] ?? 0) + ($counts[RestockReminder::QUEUED] ?? 0)),
                'queued' => (int) ($counts[RestockReminder::QUEUED] ?? 0),
                'sent' => (int) ($counts[RestockReminder::SENT] ?? 0),
                'excluded' => (int) ($counts[RestockReminder::EXCLUDED] ?? 0),
                'skipped' => (int) ($counts[RestockReminder::SKIPPED] ?? 0),
                'failed' => (int) ($counts[RestockReminder::FAILED] ?? 0),
                'all' => (int) $counts->sum(),
                'followUps' => (clone $base)->where('kind', RestockReminder::FOLLOW_UP)->whereIn('status', [RestockReminder::PENDING, RestockReminder::QUEUED])->count(),
            ],
            'replies' => SmsReply::whereNull('handled_at')->where('kind', '!=', SmsReply::STOP)
                ->when($craId, fn (Builder $query) => $query->where('user_id', $craId))->count(),
            'enabled' => SmsSettings::enabled(),
            // Ready once the phone link is set and the phone has confirmed a text went out.
            'phoneReady' => $craForActions ? SmsSender::linkFor($craForActions) !== null && $craForActions->sms_confirmed_at !== null : null,
            'leadDay' => WorkingDate::leadDayFor($day),
            // The Text button's template choices, each row's CRA's own: follow-ups first, then reminders.
            'textTemplates' => SmsTemplate::where('is_active', true)->whereIn('user_id', $rows->getCollection()->pluck('user_id')->filter()->unique())
                ->orderByRaw('case when purpose = ? then 0 else 1 end', [SmsTemplate::FOLLOW_UP])->orderBy('name')
                ->get(['id', 'user_id', 'name', 'purpose', 'body']),
            // Customers who asked not to be texted get no Text button.
            'doNotText' => DoNotText::whereIn('phone_key', $rows->getCollection()->pluck('phone_key'))->pluck('phone_key')->all(),
        ]);
    }

    /**
     * Tick (include) or untick (leave out, with a reason) one customer. "Asked not to be texted" also puts the number
     * on the do-not-text list.
     */
    public function update(Request $request, RestockReminder $restockReminder): RedirectResponse
    {
        $this->authorizeCra($request->user(), $restockReminder->user_id);
        $data = $request->validate([
            'include' => ['required', 'boolean'],
            'reason' => ['nullable', Rule::in(RestockReminder::EXCLUDE_REASONS)],
        ]);

        if (! $restockReminder->isChangeable()) {
            return back()->withErrors(['reminder' => 'This text was already sent or skipped, so it can\'t be changed.']);
        }

        $include = (bool) $data['include'];
        $reason = $include ? null : ($data['reason'] ?? RestockReminder::UNTICKED);
        $restockReminder->update([
            ...($include ? RestockReminder::ticked() : ['status' => RestockReminder::EXCLUDED, 'excluded_reason' => $reason, 'queued_at' => null]),
            'status_updated_by' => $request->user()->id,
        ]);

        if ($reason === 'Asked not to be texted') {
            DoNotText::firstOrCreate(['phone_key' => $restockReminder->phone_key], ['reason' => 'Asked their CRA', 'added_by' => $request->user()->id]);
        }

        return back()->with('status', ($restockReminder->customer_name ?: 'Customer').($include ? ' will get the text.' : ' left out.'));
    }

    /**
     * Tick or untick every customer of the day (and CRA) not yet sent, or only those of the page shown (ids). Failed ones are
     * ticked only with their page, where the CRA sees them.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'cra' => ['nullable', 'integer'],
            'include' => ['required', 'boolean'],
            'ids' => ['nullable', 'array', 'max:'.max(self::PER_PAGE)],
            'ids.*' => ['integer'],
        ]);
        $craId = $this->craScope($request->user(), $data['cra'] ?? null);
        $include = (bool) $data['include'];

        if (! $craId && empty($data['ids'])) {
            return back()->withErrors(['send' => 'Pick a CRA first: Tick all and Untick all are for one CRA.']);
        }

        $changed = RestockReminder::shownOn(CarbonImmutable::parse($data['date']))
            ->when($craId, fn (Builder $query) => $query->where('user_id', $craId))
            ->when($data['ids'] ?? null, fn (Builder $query, array $ids) => $query->whereIn('id', $ids))
            ->whereIn('status', $include ? [RestockReminder::EXCLUDED, ...(empty($data['ids']) ? [] : [RestockReminder::FAILED])] : [RestockReminder::PENDING, RestockReminder::QUEUED])
            ->update([
                ...($include ? RestockReminder::ticked() : ['status' => RestockReminder::EXCLUDED, 'excluded_reason' => RestockReminder::UNTICKED, 'queued_at' => null]),
                'status_updated_by' => $request->user()->id,
            ]);

        return back()->with('status', $include ? "{$changed} customers ticked." : "{$changed} customers unticked. They stay on the list until they're ticked again.");
    }

    /**
     * Queue a CRA's ticked customers still to send today (earlier days' included) now instead of waiting for the automatic
     * send time: all of them, or the first few on the list (limit). The others stay ticked.
     */
    public function sendNow(Request $request): RedirectResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1']], [], ['limit' => 'number of customers']);
        $craId = $this->craScope($request->user(), $request->integer('cra') ?: null);

        if (! $craId) {
            return back()->withErrors(['send' => 'Pick a CRA first: texts go out from each CRA\'s own phone.']);
        }

        if (! SmsSettings::enabled()) {
            return back()->withErrors(['send' => 'SMS Reminders is turned off in Settings → SMS Reminders, so nothing is sent.']);
        }

        if (User::whereKey($craId)->value('sms_confirmed_at') === null) {
            return back()->withErrors(['send' => SmsSender::explain(SmsSender::NOT_READY)]);
        }

        $toSend = RestockReminder::toSendOn(WorkingDate::realToday())->where('user_id', $craId)
            ->where('status', RestockReminder::PENDING);
        $ids = isset($data['limit'])
            ? (clone $toSend)->orderBy('remind_on')->orderBy('customer_name')->orderBy('id')->limit((int) $data['limit'])->pluck('id')
            : null;

        $queued = $toSend->when($ids !== null, fn (Builder $query) => $query->whereIn('id', $ids))
            ->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);

        $left = max(0, SmsSettings::dailyLimitFor(User::find($craId)) - SmsSettings::sentToday($craId));

        return back()->with('status', $queued
            ? "{$queued} texts queued: one goes out every minute from the phone (about {$queued} min).".($queued > $left ? " Only {$left} more can go out today (daily limit); the others wait for the next sending day." : '')
            : 'No ticked customers left to send today.');
    }

    /**
     * Turn a CRA's automatic daily sending on or off.
     */
    public function autoSend(Request $request): RedirectResponse
    {
        $data = $request->validate(['cra' => ['nullable', 'integer'], 'on' => ['required', 'boolean']]);
        $craId = $this->craScope($request->user(), $data['cra'] ?? null);
        $cra = User::find($craId);

        if (! $cra) {
            return back()->withErrors(['send' => 'Pick a CRA first.']);
        }

        $cra->forceFill(['sms_auto_send' => (bool) $data['on']])->save();
        $stopped = $data['on'] ? 0 : RestockReminder::stopQueueOf($cra->id);

        return back()->with('status', $data['on']
            ? "Automatic sending on: {$cra->displayName()}'s ticked customers are texted ".SmsSettings::scheduleLabel($cra).'.'
            : "Automatic sending off for {$cra->displayName()}: texts go out only with Send now.".($stopped ? " {$stopped} queued texts stopped; they stay ticked." : ''));
    }

    /**
     * Stop a CRA's queued texts (automatic or Send now): they stay ticked and go out only when queued again.
     */
    public function stop(Request $request): RedirectResponse
    {
        $cra = LeadGenerator::cras()->firstWhere('id', $this->craScope($request->user(), $request->integer('cra') ?: null));

        if (! $cra) {
            return back()->withErrors(['send' => 'Pick a CRA first.']);
        }

        $cra->forceFill(['sms_auto_send' => false])->save();
        $stopped = RestockReminder::stopQueueOf($cra->id);

        return back()->with('status', "{$stopped} queued texts stopped for {$cra->displayName()}; they stay ticked. Automatic sending is off.");
    }

    /**
     * The most texts a CRA's phone sends a day, set by the CRA (or someone who sees every CRA's); blank = the default.
     */
    public function dailyLimit(Request $request): RedirectResponse
    {
        $cap = SmsSettings::dailyCap();
        $data = $request->validate(['cra' => ['nullable', 'integer'], 'daily_limit' => ['nullable', 'integer', 'min:1', 'max:'.$cap]], [
            'daily_limit.max' => "The most texts a phone can send a day is {$cap} (Settings → SMS Reminders).",
        ], ['daily_limit' => 'daily limit']);
        $cra = LeadGenerator::cras()->firstWhere('id', $this->craScope($request->user(), $data['cra'] ?? null));

        if (! $cra) {
            return back()->withErrors(['send' => 'Pick a CRA first.']);
        }

        $limit = isset($data['daily_limit']) ? (int) $data['daily_limit'] : null;
        $cra->forceFill(['sms_schedule' => SmsSettings::withDailyLimit($cra->sms_schedule, $limit)])->save();

        return back()->with('status', "{$cra->displayName()}'s daily limit: ".SmsSettings::dailyLimitFor($cra).' texts'.($limit === null ? ' (the default).' : '.'));
    }

    /**
     * Turn one of a CRA's sending windows on or off; the whole daily limit goes out in the ones left on.
     */
    public function window(Request $request): RedirectResponse
    {
        $data = $request->validate(['cra' => ['nullable', 'integer'], 'window' => ['required', 'integer', 'between:0,1'], 'on' => ['required', 'boolean']]);
        $cra = LeadGenerator::cras()->firstWhere('id', $this->craScope($request->user(), $data['cra'] ?? null));

        if (! $cra) {
            return back()->withErrors(['send' => 'Pick a CRA first.']);
        }

        $schedule = SmsSettings::scheduleFor($cra);
        $off = $data['on']
            ? array_diff($schedule['windows_off'], [(int) $data['window']])
            : array_unique([...$schedule['windows_off'], (int) $data['window']]);

        if (count(array_intersect($off, array_keys($schedule['all_windows']))) >= count($schedule['all_windows'])) {
            return back()->withErrors(['send' => 'Keep at least one window on, or turn off automatic sending.']);
        }

        $cra->forceFill(['sms_schedule' => SmsSettings::withWindowsOff($cra->sms_schedule, array_values($off))])->save();

        return back()->with('status', "{$cra->displayName()}'s texts go out ".SmsSettings::windowsLabel(SmsSettings::scheduleFor($cra)['windows']).'.');
    }

    /**
     * A follow-up text typed by hand, sent right away from the customer's CRA's phone (so replies come back to them).
     * Allowed outside the sending windows (manual_hours), not counted against the phone's daily cap, never to a number on
     * the do-not-text list.
     */
    public function text(Request $request, RestockReminder $restockReminder, SmsSender $sender): RedirectResponse
    {
        $this->authorizeCra($request->user(), $restockReminder->user_id);
        $data = $request->validate(['message' => ['required', 'string', 'max:480']], [], ['message' => 'text']);
        $cra = $restockReminder->cra;
        $hours = config('sms_reminders.manual_hours');

        $problem = match (true) {
            ! SmsSettings::enabled() => 'SMS Reminders is turned off in Settings → SMS Reminders, so nothing is sent.',
            ! $cra => 'This customer has no CRA, so there\'s no phone to text from.',
            DoNotText::has($restockReminder->phone_key) => 'This customer asked not to be texted.',
            ! SmsSettings::withinManualHours() => 'Texts can be sent between '.CarbonImmutable::createFromFormat('H:i', $hours['from'])->format('g:i A')
                .' and '.CarbonImmutable::createFromFormat('H:i', $hours['until'])->format('g:i A').'.',
            default => null,
        };

        if ($problem) {
            return back()->withErrors(['text' => $problem])->withInput();
        }

        $result = $sender->send($cra, $restockReminder->phone_number, trim($data['message']));

        if ($result !== SmsSender::SENT) {
            return back()->withErrors(['text' => SmsSender::explain($result)])->withInput();
        }

        SmsMessage::create([
            'restock_reminder_id' => $restockReminder->id,
            'phone_user_id' => $cra->id,
            'sent_by' => $request->user()->id,
            'phone_number' => $restockReminder->phone_number,
            'body' => trim($data['message']),
            'sent_at' => now(),
        ]);

        return back()->with('status', 'Text sent to '.($restockReminder->customer_name ?: 'the customer')." from {$cra->displayName()}'s phone.");
    }

    /**
     * The CRA the page is for: the user's own unless they may see every CRA (then the one picked, or all).
     */
    private function craScope(User $user, ?int $picked): ?int
    {
        return $user->can('sms_reminders.view_all') ? $picked : $user->id;
    }

    private function authorizeCra(User $user, ?int $craId): void
    {
        abort_unless($craId === $user->id || $user->can('sms_reminders.view_all'), 403);
    }
}
