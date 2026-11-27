<?php

namespace App\Services;

use App\Models\DoNotText;
use App\Models\RestockReminder;
use App\Models\SmsMessage;
use App\Models\SmsReply;
use App\Models\SmsTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * SMS Reminders → Conversations: every customer texted or who replied in the last DAYS days, as chat threads. A
 * thread is one customer (main number): the restock texts and follow-ups sent, texts typed by the CRA and their
 * replies, in time order, with what happened in between (follow-up added, no response, reordered).
 */
class SmsConversations
{
    /** How far back the threads list goes (unanswered replies are listed however old). */
    public const DAYS = 30;

    public const PER_PAGE = 50;

    /**
     * What the Conversations page shows whichever list is open: the chat of $phone (when $user may open it) and the counts.
     *
     * @return array<string, mixed>
     */
    public function page(User $user, ?int $craId, ?string $phone, bool $all = false): array
    {
        $thread = $phone ? $this->thread($phone, $all) : null;
        if ($thread && $thread['latest']->user_id !== $user->id && ! $user->can('sms_reminders.view_all')) {
            $thread = null;
        }
        $forCra = fn (Builder $query) => $query->when($craId, fn (Builder $q) => $q->where('user_id', $craId));

        return [
            'thread' => $thread,
            'phone' => $thread ? $phone : null,
            'craId' => $craId,
            'cras' => LeadGenerator::cras(),
            'canDial' => (bool) $user->call_webhook_url,
            'optedOut' => $thread && DoNotText::has($phone),
            'templates' => $thread ? SmsTemplate::where('user_id', $thread['latest']->user_id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'body']) : collect(),
            'counts' => [
                'reply' => $forCra(SmsReply::needsAnswer())->distinct()->count('phone_key'),
                'reorder' => $forCra(SmsReply::wantsToReorder())->distinct()->count('phone_key'),
                'follow_ups' => $forCra(RestockReminder::followUpsToSend())->count(),
                'no_response' => $forCra(RestockReminder::noResponse())->count(),
            ],
        ];
    }

    /**
     * The threads for $craId (null = every CRA), latest activity first. $show: reply (replies not answered yet),
     * reorder (said YES, no call yet) or all.
     *
     * @return LengthAwarePaginator<int, array{phone_key: string, name: ?string, number: string, products: string, last_at: CarbonImmutable, last_text: string, kind: ?SmsReply, open: int}>
     */
    public function threads(?int $craId, string $show, ?string $search, int $page): LengthAwarePaginator
    {
        $since = now()->subDays(self::DAYS);
        $forCra = fn (Builder $query, string $column = 'user_id') => $query->when($craId, fn (Builder $q) => $q->where($column, $craId));

        $replies = $forCra(SmsReply::whereNotNull('restock_reminder_id')->where('received_at', '>=', $since));
        // Unanswered replies stay listed however old, so the list always matches its count.
        $open = $forCra(SmsReply::needsAnswer());
        $last = match ($show) {
            'reply' => (clone $open)->selectRaw('phone_key, max(received_at) as last_at')->groupBy('phone_key')->pluck('last_at', 'phone_key'),
            'reorder' => $forCra(SmsReply::wantsToReorder())->selectRaw('phone_key, max(received_at) as last_at')->groupBy('phone_key')->pluck('last_at', 'phone_key'),
            default => $this->lastActivity($forCra, $replies),
        };

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $digits = preg_replace('/\D+/', '', $term);
            $matching = RestockReminder::whereIn('phone_key', $last->keys())
                ->where(fn (Builder $q) => $q->whereLike('customer_name', '%'.$term.'%')
                    ->when(strlen($digits) >= 4, fn (Builder $q) => $q->orWhere('phone_key', 'like', '%'.substr($digits, -10).'%')))
                ->distinct()->pluck('phone_key')->flip();
            $last = $last->filter(fn ($at, $key) => isset($matching[$key]));
        }

        $keys = $last->sortDesc()->keys()->map(fn ($key) => (string) $key);
        $pageKeys = $keys->forPage($page, self::PER_PAGE)->values()->all();
        $openCounts = (clone $open)->whereIn('phone_key', $pageKeys)->selectRaw('phone_key, count(*) as n')->groupBy('phone_key')->pluck('n', 'phone_key');
        $latestReminder = RestockReminder::whereIn('phone_key', $pageKeys)->orderByDesc('remind_on')->get(['phone_key', 'customer_name', 'phone_number', 'products'])->unique('phone_key')->keyBy('phone_key');
        $latestReply = SmsReply::whereIn('phone_key', $pageKeys)->latest('received_at')->get(['phone_key', 'body', 'kind', 'received_at'])->unique('phone_key')->keyBy('phone_key');
        $latestSent = RestockReminder::whereIn('phone_key', $pageKeys)->where('status', RestockReminder::SENT)->latest('sent_at')->get(['phone_key', 'message', 'sent_at'])->unique('phone_key')->keyBy('phone_key');

        $rows = collect($pageKeys)->map(function (string $key) use ($last, $openCounts, $latestReminder, $latestReply, $latestSent) {
            $reply = $latestReply[$key] ?? null;
            $sent = $latestSent[$key] ?? null;
            $lastText = $reply && (! $sent || $reply->received_at->gte($sent->sent_at)) ? $reply->body : (string) $sent?->message;

            return [
                'phone_key' => $key,
                'name' => $latestReminder[$key]?->customer_name,
                'number' => (string) $latestReminder[$key]?->phone_number,
                'products' => (string) $latestReminder[$key]?->products,
                'last_at' => CarbonImmutable::parse($last[$key]),
                'last_text' => $lastText,
                'kind' => $reply,
                'open' => (int) ($openCounts[$key] ?? 0),
            ];
        });

        return new LengthAwarePaginator($rows, $keys->count(), self::PER_PAGE, $page, ['path' => request()->url(), 'query' => request()->query()]);
    }

    /**
     * Each customer's latest text sent, typed or received.
     *
     * @param  callable(Builder, string=): Builder  $forCra
     * @return Collection<string, string> phone key => latest time
     */
    private function lastActivity(callable $forCra, Builder $replies): Collection
    {
        $since = now()->subDays(self::DAYS);
        $sent = $forCra(RestockReminder::where('status', RestockReminder::SENT)->where('sent_at', '>=', $since))
            ->selectRaw('phone_key, max(sent_at) as last_at')->groupBy('phone_key')->pluck('last_at', 'phone_key');
        $typed = $forCra(SmsMessage::join('restock_reminders', 'restock_reminders.id', '=', 'sms_messages.restock_reminder_id')
            ->where('sms_messages.sent_at', '>=', $since), 'sms_messages.phone_user_id')
            ->selectRaw('restock_reminders.phone_key, max(sms_messages.sent_at) as last_at')->groupBy('restock_reminders.phone_key')->pluck('last_at', 'phone_key');
        $received = (clone $replies)->selectRaw('phone_key, max(received_at) as last_at')->groupBy('phone_key')->pluck('last_at', 'phone_key');

        $last = collect();
        foreach ([$sent, $typed, $received] as $times) {
            foreach ($times as $key => $at) {
                $last[(string) $key] = max($last[(string) $key] ?? '', (string) $at);
            }
        }

        return $last;
    }

    /**
     * One customer's thread, oldest first: texts sent (restock and follow-ups), typed by the CRA, their replies, and
     * events. Only its latest day unless $all (earlier counts what's hidden). Null when the customer has no texts.
     *
     * @return array{latest: RestockReminder, items: list<array{type: string, body: string, at: CarbonImmutable, meta: string}>, earlier: int, next: ?RestockReminder, steps: list<array{id: ?int, label: string, day: string, state: string, reason: ?string, reason_from: ?string}>}|null
     */
    public function thread(string $phoneKey, bool $all = false): ?array
    {
        $texts = RestockReminder::with(['scheduler', 'cra'])->where('phone_key', $phoneKey)->orderBy('remind_on')->orderBy('id')->get();

        if ($texts->isEmpty()) {
            return null;
        }

        $tz = config('segmentation.timezone');
        $items = collect();
        foreach ($texts as $text) {
            if ($text->status === RestockReminder::SENT && $text->sent_at) {
                [$label] = $text->why();
                $items->push(['type' => 'out', 'body' => (string) $text->message, 'at' => CarbonImmutable::parse($text->sent_at),
                    'meta' => $label.($text->covers && count($text->covers) > 1 ? ' for '.count($text->covers).' products' : '').' · '.$text->sent_at->timezone($tz)->format('g:i A').' · Sent']);
            }
            if ($text->isFollowUp() && $text->scheduled_by) {
                $items->push(['type' => 'event', 'body' => ($text->scheduler?->displayName() ?? 'A CRA').' scheduled a follow-up for '.$text->remind_on->format('M j').($text->note ? ' · note: '.$text->note : ''),
                    'at' => CarbonImmutable::parse($text->created_at), 'meta' => '']);
            } elseif ($text->isFollowUp()) {
                $items->push(['type' => 'event', 'body' => 'No reorder by 9 PM · follow-up '.$text->follow_up_number.' of '.RestockReminder::MAX_FOLLOW_UPS.' added for '.$text->remind_on->format('M j'),
                    'at' => CarbonImmutable::parse($text->created_at), 'meta' => '']);
            }
            if ($text->no_response_at) {
                $items->push(['type' => 'event', 'body' => 'No reorder after '.RestockReminder::MAX_FOLLOW_UPS.' follow-ups · moved to No response', 'at' => CarbonImmutable::parse($text->no_response_at), 'meta' => '']);
            }
            if ($text->reordered_at) {
                $items->push(['type' => 'event', 'body' => 'Marked reordered · follow-ups stopped', 'at' => CarbonImmutable::parse($text->reordered_at), 'meta' => '']);
            }
        }

        SmsMessage::with('sender')->whereIn('restock_reminder_id', $texts->modelKeys())->get()
            ->each(fn (SmsMessage $message) => $items->push(['type' => 'out', 'body' => $message->body, 'at' => CarbonImmutable::parse($message->sent_at),
                'meta' => 'Typed by '.($message->sender?->displayName() ?? 'a CRA').' · '.$message->sent_at->timezone($tz)->format('g:i A')]));
        $replies = SmsReply::where('phone_key', $phoneKey)->orderBy('received_at')->get();
        $replies
            ->each(fn (SmsReply $reply) => $items->push(['type' => 'in', 'body' => $reply->body, 'at' => CarbonImmutable::parse($reply->received_at),
                'meta' => $reply->received_at->timezone($tz)->format('g:i A')]));

        $items = $items->sortBy(fn (array $item) => $item['at']->getTimestamp())->values();
        // The latest day only, unless asked: earlier days are a click away.
        $from = $items->isEmpty() ? null : $items->last()['at']->timezone($tz)->startOfDay();
        $shown = $all || ! $from ? $items : $items->filter(fn (array $item) => $item['at']->gte($from))->values();

        return [
            'latest' => $texts->sortByDesc(fn (RestockReminder $text) => [$text->remind_on->toDateString(), $text->id])->first(),
            'items' => $shown->all(),
            'earlier' => $items->count() - $shown->count(),
            'steps' => $this->steps($texts, $replies),
            'next' => $texts->first(fn (RestockReminder $text) => $text->isFollowUp() && in_array($text->status, [RestockReminder::PENDING, RestockReminder::QUEUED], true)),
        ];
    }

    /**
     * The customer's latest reminder and its follow-ups: "Reminder: Nov 24 · Sent", "1st follow-up: Dec 1 · Will be
     * texted", and the next automatic one's day while they haven't reordered ("2nd follow-up: Dec 8 · If no reorder").
     * Each sent one has its no-reorder reason: the CRA's (no_reorder_reason), else the customer's first reply to it.
     *
     * @param  Collection<int, RestockReminder>  $texts  oldest first
     * @param  Collection<int, SmsReply>  $replies  oldest first
     * @return list<array{id: ?int, label: string, day: string, state: string, reason: ?string, reason_from: ?string}>
     */
    private function steps(Collection $texts, Collection $replies): array
    {
        $reminder = $texts->filter(fn (RestockReminder $text) => ! $text->isFollowUp() && in_array($text->status, RestockReminder::COVERING, true))->last();
        if (! $reminder) {
            return [];
        }

        $ordinal = fn (int $n) => $n.match ($n) {
            1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th'
        };
        $dayOf = fn (RestockReminder $text) => ($text->sent_at ? CarbonImmutable::parse($text->sent_at)->timezone(config('segmentation.timezone')) : $text->remind_on)->format('M j');
        $stateOf = fn (RestockReminder $text) => $text->status === RestockReminder::SKIPPED ? ($text->error ?: 'Skipped') : $text->statusLabel();

        $followUps = $texts->filter(fn (RestockReminder $text) => $text->isFollowUp() && $text->remind_on->gt($reminder->remind_on))->values();
        $chain = collect([$reminder])->concat($followUps)->values();
        $sentTimes = $chain->filter(fn (RestockReminder $text) => $text->sent_at)->map(fn (RestockReminder $text) => $text->sent_at)->values();
        $step = function (RestockReminder $text, string $label) use ($dayOf, $stateOf, $replies, $sentTimes): array {
            $reply = null;
            if ($text->sent_at && ! $text->no_reorder_reason) {
                $next = $sentTimes->first(fn ($at) => $at->gt($text->sent_at));
                $reply = $replies->first(fn (SmsReply $reply) => $reply->received_at->gte($text->sent_at) && (! $next || $reply->received_at->lt($next)));
            }

            return ['id' => $text->id, 'label' => $label, 'day' => $dayOf($text), 'state' => $stateOf($text),
                'reason' => $text->no_reorder_reason ?? $reply?->body, 'reason_from' => $text->no_reorder_reason ? 'CRA' : ($reply ? 'Customer' : null)];
        };

        $steps = [$step($reminder, 'Reminder')];
        foreach ($followUps as $followUp) {
            $steps[] = $step($followUp, $followUp->scheduled_by ? 'Follow-up (scheduled)' : $ordinal($followUp->follow_up_number).' follow-up');
        }

        // The next automatic follow-up, not added yet: 7 days after the last text sent, if they still haven't reordered.
        $lastSent = $texts->filter(fn (RestockReminder $text) => $text->status === RestockReminder::SENT && $text->remind_on->gte($reminder->remind_on))->last();
        $waiting = $followUps->contains(fn (RestockReminder $text) => in_array($text->status, [RestockReminder::PENDING, RestockReminder::QUEUED], true));
        $stopped = $texts->contains(fn (RestockReminder $text) => $text->reordered_at || $text->no_response_at);
        if ($lastSent && ! $waiting && ! $stopped && $lastSent->follow_up_number < RestockReminder::MAX_FOLLOW_UPS) {
            $steps[] = ['id' => null, 'label' => $ordinal($lastSent->follow_up_number + 1).' follow-up', 'day' => CarbonImmutable::parse($lastSent->sent_at)->timezone(config('segmentation.timezone'))->addDays(RestockReminder::FOLLOW_UP_DAYS)->format('M j'), 'state' => 'If no reorder', 'reason' => null, 'reason_from' => null];
        }

        return $steps;
    }
}
