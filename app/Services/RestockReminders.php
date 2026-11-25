<?php

namespace App\Services;

use App\Models\CustomerLink;
use App\Models\DeliveredOrder;
use App\Models\DoNotText;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\RestockReminder;
use App\Models\SmsReply;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Support\NicheRules;
use App\Support\SmsSettings;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Who gets a restock text on a reminder day, and what it says. The texts are the CRAs' own tracker lists: on a real day,
 * every lead on each CRA's Segmentation Tracker or Exclusive Lead Tracker list for its lead day (WorkingDate), one text a
 * customer, from the CRA whose list it is, so a text can take the place of a call. A lead is texted once; customers who
 * reordered the product since or opted out are skipped. Texts not sent on their day (the phone's daily cap, no phone
 * yet, sending off) carry over to the next days until they go out.
 */
class RestockReminders
{
    /** How far back a reorder of the product is looked for. */
    private const LOOKBACK_DAYS = 400;

    /** @var Collection<int, SmsTemplate>|null */
    private ?Collection $templates = null;

    public function __construct(private ProductCatalog $catalog) {}

    /**
     * Saves $remindOn's reminders: one per customer (merged numbers once) on a CRA's tracker list for $remindOn's lead day,
     * for that CRA, covering their leads on it. A lead already in an earlier text (or left out by the CRA) isn't texted
     * again, nor a product reordered (same product delivered or ordered after the lead's delivery); earlier days' texts
     * still to send are combined into today's, so a customer gets one text (unticked when the CRA unticked the earlier one or it failed:
     * they say when it goes). Ones already left out, queued or sent stay as
     * they are; a pending one whose customer has since reordered, opted out or was already texted is skipped.
     *
     * With $craIds, only those CRAs' lists: after the backlog fill topped them up (BacklogFill), so the customers it added
     * are on the text list too.
     *
     * @param  list<int>|null  $craIds
     * @return array{due: int, skipped: int}
     */
    public function build(CarbonImmutable $remindOn, ?array $craIds = null): array
    {
        $leads = $this->listedLeads(WorkingDate::leadDayFor($remindOn), $craIds)
            ->filter(fn (Lead $lead) => self::isMobile(LeadGenerator::normalizePhone((string) $lead->phone_number)));

        // One customer per main number (Customer Database merge).
        $phoneOf = $leads->mapWithKeys(fn (Lead $lead) => [$lead->id => LeadGenerator::normalizePhone((string) $lead->phone_number)]);
        $primaryOf = $this->primaries($phoneOf->unique()->values()->all());
        $customers = $leads->groupBy(fn (Lead $lead) => $primaryOf[$phoneOf[$lead->id]])->all();
        $members = $this->members(array_map('strval', array_keys($customers)));

        $allNumbers = array_values(array_unique(array_merge(...array_values($members ?: [[]]))));
        $since = $remindOn->subDays(self::LOOKBACK_DAYS);
        $orderedSince = $this->lastPancakeOrder($allNumbers, $since);
        $deliveredSince = $this->latestDeliveries($allNumbers, $since);
        $optedOut = DoNotText::whereIn('phone_key', $allNumbers)->pluck('phone_key')->flip();
        $types = $this->customerTypes(array_map('strval', array_keys($customers)), $members, $this->deliveryCounts($allNumbers));
        [$textedOn, $carried] = $this->earlierTexts(array_map('strval', array_keys($customers)), $remindOn);

        $saved = [];
        $skipped = 0;
        foreach ($customers as $primary => $customerLeads) {
            $primary = (string) $primary;
            $numbers = $members[$primary] ?? [$primary];
            $productOf = fn (Lead $lead) => $this->catalog->match((string) $lead->product_name, $lead->delivered_date?->toDateString());
            // A lead's product counts as restocked when the same product was delivered or ordered (Pancake POS, not
            // canceled) after the lead's delivery; another product doesn't count.
            $stillDue = $customerLeads->reject(function (Lead $lead) use ($numbers, $productOf, $orderedSince, $deliveredSince) {
                $product = $productOf($lead);
                $delivered = $lead->delivered_date?->toDateString() ?? '';

                return $product && collect($numbers)->contains(fn (string $number) => ($deliveredSince[$number][$product->id] ?? '') > $delivered
                    || ($orderedSince[$number][$product->id] ?? '') > $delivered);
            });
            $toText = $stillDue->reject(fn (Lead $lead) => isset($textedOn[$lead->id]));
            $opted = collect($numbers)->contains(fn (string $number) => isset($optedOut[$number]));
            $first = $customerLeads->sortByDesc('delivered_date')->first();

            $reason = match (true) {
                $opted => 'Opted out',
                $stillDue->isEmpty() => 'Already reordered',
                $toText->isEmpty() => 'Already texted on '.CarbonImmutable::parse($textedOn[$stillDue->first()->id])->format('M j'),
                default => null,
            };

            $forLeads = $reason ? $customerLeads : $toText;
            $covers = $forLeads->map(fn (Lead $lead) => ['product_id' => $productOf($lead)?->id, 'name' => $productOf($lead)?->name ?? (string) $lead->product_name,
                'run_out_on' => $lead->est_out_of_stock_date->toDateString()])->sortBy('run_out_on')->unique('name')->values();
            $leadIds = $forLeads->pluck('id')->all();
            if (! $reason) {
                foreach ($carried[$primary] ?? [] as $earlier) {
                    $covers = $covers->concat($earlier->coveredItems());
                    $leadIds = [...$leadIds, ...($earlier->lead_ids ?? [])];
                }
                $covers = $covers->sortBy('run_out_on')->unique('name')->values();
            }

            $attributes = [
                'phone_number' => $first->phone_number,
                'customer_name' => $first->customer_name,
                'product_id' => $covers->first()['product_id'],
                'products' => $covers->pluck('name')->implode(' + '),
                'covers' => $covers->all(),
                'lead_ids' => array_values(array_unique($leadIds)),
                'customer_type' => $types[$primary],
                'run_out_on' => $covers->min('run_out_on'),
                // The CRA whose list the customer is on.
                'user_id' => $first->assigned_to,
            ];
            $reminder = RestockReminder::where('phone_key', $primary)->whereDate('remind_on', $remindOn->toDateString())->first();
            $skipped += $reason ? 1 : 0;
            $saved[] = $primary;

            $unticked = ! $reason && collect($carried[$primary] ?? [])->contains(fn (RestockReminder $earlier) => $earlier->isHeld());
            // A customer also on the list of a CRA not built now keeps that CRA's text.
            if ($craIds !== null && $reminder && ! in_array($reminder->user_id, $craIds, true)) {
                continue;
            }

            $status = match (true) {
                $reason !== null => ['status' => RestockReminder::SKIPPED, 'error' => $reason],
                $unticked => ['status' => RestockReminder::EXCLUDED, 'excluded_reason' => RestockReminder::UNTICKED, 'error' => null],
                default => ['status' => RestockReminder::PENDING, 'error' => null],
            };

            if (! $reminder) {
                RestockReminder::create([...$attributes, ...$status, 'phone_key' => $primary, 'remind_on' => $remindOn->toDateString()]);
            } elseif (in_array($reminder->status, [RestockReminder::PENDING, RestockReminder::SKIPPED], true)) {
                // A follow-up due the same day becomes this text, keeping its products and its place in the follow-ups.
                if ($reminder->isFollowUp() && $reminder->status === RestockReminder::PENDING) {
                    $attributes['covers'] = collect($attributes['covers'])->concat($reminder->coveredItems())->sortBy('run_out_on')->unique('name')->values()->all();
                    $attributes['products'] = collect($attributes['covers'])->pluck('name')->implode(' + ');
                    $attributes['lead_ids'] = array_values(array_unique([...$attributes['lead_ids'], ...($reminder->lead_ids ?? [])]));
                }
                $reminder->update([...$attributes, ...$status, 'kind' => RestockReminder::REMINDER]);
            }

            if (! $reason) {
                RestockReminder::whereIn('id', collect($carried[$primary] ?? [])->pluck('id'))
                    ->update(['status' => RestockReminder::SKIPPED, 'error' => 'Combined into the '.$remindOn->format('M j').' text']);
            }
        }

        // Pending reminders no longer on a list (e.g. the lead moved to another day or was taken off) are skipped.
        RestockReminder::whereDate('remind_on', $remindOn->toDateString())->where('status', RestockReminder::PENDING)
            ->where('kind', RestockReminder::REMINDER)->whereNotIn('phone_key', $saved ?: [''])
            ->when($craIds !== null, fn (Builder $query) => $query->whereIn('user_id', $craIds))
            ->update(['status' => RestockReminder::SKIPPED, 'error' => 'Not on a list any more']);

        if ($craIds === null) {
            $this->refreshCarriedOver($remindOn, $saved);
        }

        return ['due' => count($customers) - $skipped, 'skipped' => $skipped];
    }

    /**
     * The SMS badge of each tracker row: its text's latest state (to be texted, sending, texted on a day, failed) or the
     * customer's reply to it (YES, STOP). Rows with no text are left out.
     *
     * @param  iterable<Lead>  $leads
     * @return array<int, array{label: string, classes: string, title: string}> lead id => badge
     */
    public function badgesFor(iterable $leads): array
    {
        $leads = collect($leads);
        if ($leads->isEmpty()) {
            return [];
        }

        $primaries = array_values(array_unique($this->primaries($leads->map(fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number))->unique()->values()->all())));
        $wanted = $leads->pluck('id')->flip();
        $texts = RestockReminder::whereIn('phone_key', $primaries)->whereNotNull('lead_ids')
            ->whereIn('status', [RestockReminder::PENDING, RestockReminder::QUEUED, RestockReminder::SENT, RestockReminder::FAILED])
            ->orderBy('remind_on')->orderBy('id')->get(['id', 'lead_ids', 'status', 'sent_at']);
        $replies = SmsReply::whereIn('restock_reminder_id', $texts->modelKeys())->whereIn('kind', [SmsReply::YES, SmsReply::STOP])
            ->orderBy('id')->pluck('kind', 'restock_reminder_id');
        $tz = config('segmentation.timezone');

        $badges = [];
        foreach ($texts as $text) {
            $badge = match (true) {
                ($replies[$text->id] ?? null) === SmsReply::YES => ['Replied YES', 'bg-[#d4edbc] text-[#11734b]', 'Replied YES to the text: wants to reorder'],
                ($replies[$text->id] ?? null) === SmsReply::STOP => ['Replied STOP', 'bg-[#fde0e0] text-[#a3271f]', 'Asked not to be texted again'],
                $text->status === RestockReminder::SENT => ['Texted '.$text->sent_at?->timezone($tz)->format('M j'), 'bg-[#dde9f7] text-[#1a4f8c]', 'Texted '.$text->sent_at?->timezone($tz)->format('M j, g:i A').', no reply yet'],
                $text->status === RestockReminder::FAILED => ['Text failed', 'bg-[#fde0e0] text-[#a3271f]', 'The text didn\'t go out'],
                $text->status === RestockReminder::QUEUED => ['Sending text', 'bg-[#fbeccd] text-[#7a4d00]', 'Queued on the CRA\'s phone'],
                default => ['To be texted', 'bg-[#fbeccd] text-[#7a4d00]', 'On SMS Reminders, not sent yet'],
            };
            foreach ($text->lead_ids ?? [] as $leadId) {
                if (isset($wanted[$leadId])) {
                    $badges[$leadId] = ['label' => $badge[0], 'classes' => $badge[1], 'title' => $badge[2]];
                }
            }
        }

        return $badges;
    }

    /**
     * Every lead on an active CRA's tracker list for $leadDay: the Segmentation Tracker's (assigned, listed that day) and
     * each exclusive CRA's Exclusive Lead Tracker list for the day. With $craIds, only those CRAs'.
     *
     * @param  list<int>|null  $craIds
     * @return Collection<int, Lead>
     */
    public function listedLeads(CarbonImmutable $leadDay, ?array $craIds = null): Collection
    {
        $columns = ['id', 'order_id', 'assigned_to', 'customer_name', 'phone_number', 'product_name', 'delivered_date', 'est_out_of_stock_date', 'listed_on'];
        $active = LeadGenerator::cras()->when($craIds !== null, fn (Collection $cras) => $cras->whereIn('id', $craIds))->keyBy('id');
        $exclusive = app(ExclusiveLeads::class);

        return Lead::inSegmentationTracker()->withoutCarriedIn()->whereDate('listed_on', $leadDay->toDateString())->whereIn('assigned_to', $active->keys())
            ->orderBy('id')->get($columns)
            ->concat($active->filter(fn (User $cra) => $cra->isExclusiveOn($leadDay))
                ->flatMap(fn (User $cra) => $exclusive->leadsFor($cra, $leadDay)->orderBy('id')->get($columns)))
            ->unique('id')->values();
    }

    /**
     * These customers' texts from before $remindOn: the day each lead was texted or left out (lead id => remind day),
     * and their reminders still to send on $remindOn, unticked or failed (combined into it).
     *
     * @param  list<string>  $primaries
     * @return array{0: array<int, string>, 1: array<string, list<RestockReminder>>}
     */
    private function earlierTexts(array $primaries, CarbonImmutable $remindOn): array
    {
        $textedOn = [];
        $carried = [];

        foreach (array_chunk($primaries, 1000) as $chunk) {
            RestockReminder::whereIn('phone_key', $chunk)->whereDate('remind_on', '<', $remindOn->toDateString())
                ->whereIn('status', [...RestockReminder::COVERING, RestockReminder::FAILED])
                ->get(['id', 'phone_key', 'product_id', 'products', 'covers', 'lead_ids', 'run_out_on', 'remind_on', 'status', 'excluded_reason', 'kind'])
                ->each(function (RestockReminder $reminder) use (&$textedOn, &$carried) {
                    if (($reminder->status === RestockReminder::PENDING || $reminder->isHeld()) && ! $reminder->isFollowUp()) {
                        // Still to send: combined into today's text rather than counted as texted.
                        $carried[$reminder->phone_key][] = $reminder;

                        return;
                    }
                    if ($reminder->status === RestockReminder::FAILED) {
                        return;
                    }
                    foreach ($reminder->lead_ids ?? [] as $leadId) {
                        $textedOn[$leadId] = min($textedOn[$leadId] ?? '9999', $reminder->remind_on->toDateString());
                    }
                });
        }

        return [$textedOn, $carried];
    }

    /**
     * The 9 PM check (reminders:follow-up): every customer texted on $day (their latest text that day) who hasn't
     * reordered since gets a follow-up FOLLOW_UP_DAYS later, for the same products, with their CRA now, unless one is
     * already waiting. After the last automatic follow-up (MAX_FOLLOW_UPS) they go on No response instead. A follow-up
     * the CRA scheduled doesn't use up one of the automatic ones.
     *
     * @return array{follow_ups: int, no_response: int}
     */
    public function scheduleFollowUps(CarbonImmutable $day): array
    {
        $tz = config('segmentation.timezone');
        $start = CarbonImmutable::parse($day->toDateString(), $tz)->utc();
        $texted = RestockReminder::where('status', RestockReminder::SENT)->where('sent_at', '>=', $start)->where('sent_at', '<', $start->addDay())
            ->orderByDesc('sent_at')->get()->unique('phone_key')->values();

        if ($texted->isEmpty()) {
            return ['follow_ups' => 0, 'no_response' => 0];
        }

        $reordered = $this->reorderedSince($texted);
        $waiting = RestockReminder::whereIn('phone_key', $texted->pluck('phone_key'))->whereIn('status', [RestockReminder::PENDING, RestockReminder::QUEUED])
            ->whereDate('remind_on', '>', $day->toDateString())->pluck('phone_key')->flip();
        $optedOut = DoNotText::whereIn('phone_key', $texted->pluck('phone_key'))->pluck('phone_key')->flip();
        $craFor = $this->craPicker($texted->pluck('phone_key')->all(), $day->addDays(RestockReminder::FOLLOW_UP_DAYS));
        $counts = ['follow_ups' => 0, 'no_response' => 0];

        foreach ($texted as $text) {
            if (isset($reordered[$text->id]) || isset($waiting[$text->phone_key]) || isset($optedOut[$text->phone_key]) || $text->no_response_at) {
                continue;
            }

            if ($text->follow_up_number >= RestockReminder::MAX_FOLLOW_UPS) {
                $text->update(['no_response_at' => now()]);
                $counts['no_response']++;

                continue;
            }

            $on = $day->addDays(RestockReminder::FOLLOW_UP_DAYS)->toDateString();
            if (RestockReminder::where('phone_key', $text->phone_key)->whereDate('remind_on', $on)->exists()) {
                continue;
            }

            RestockReminder::create([
                ...$text->only(['phone_key', 'phone_number', 'customer_name', 'product_id', 'products', 'covers', 'customer_type', 'run_out_on']),
                'remind_on' => $on,
                'kind' => RestockReminder::FOLLOW_UP,
                'follow_up_number' => $text->follow_up_number + 1,
                'user_id' => $craFor($text->phone_key, $text->coveredItems()[0]['name'] ?? null, $text->user_id),
                'status' => RestockReminder::PENDING,
            ]);
            $counts['follow_ups']++;
        }

        return $counts;
    }

    /**
     * Which of these texts' customers reordered on or after the text's day: a Pancake order or delivery of a product it
     * covers, a tracker lead of theirs marked Repeat Purchase = Yes, or marked reordered by the CRA.
     *
     * @param  Collection<int, RestockReminder>  $texts
     * @return array<int, true> text id => true
     */
    public function reorderedSince(Collection $texts): array
    {
        if ($texts->isEmpty()) {
            return [];
        }

        $members = $this->members($texts->pluck('phone_key')->unique()->values()->all());
        $numbersOf = fn (RestockReminder $text) => $members[$text->phone_key] ?? [$text->phone_key];
        $numbers = $texts->flatMap($numbersOf)->unique()->values()->all();
        $since = CarbonImmutable::parse($texts->min('remind_on'));
        $ordered = $this->lastPancakeOrder($numbers, $since);
        $delivered = $this->latestDeliveries($numbers, $since);
        $markedReordered = RestockReminder::whereIn('phone_key', $texts->pluck('phone_key'))->whereNotNull('reordered_at')
            ->selectRaw('phone_key, max(reordered_at) as last')->groupBy('phone_key')->pluck('last', 'phone_key');
        $formats = collect($numbers)->flatMap(fn (string $key) => [$key, '0'.$key, '63'.$key, '+63'.$key])->all();
        $repeat = [];
        foreach (array_chunk($formats, 1000) as $chunk) {
            Lead::whereIn('phone_number', $chunk)->where('repeat_purchase', 'yes')->whereNotNull('repeat_purchase_on')
                ->toBase()->get(['phone_number', 'repeat_purchase_on'])
                ->each(function (object $lead) use (&$repeat) {
                    $key = LeadGenerator::normalizePhone((string) $lead->phone_number);
                    $repeat[$key] = max($repeat[$key] ?? '', substr((string) $lead->repeat_purchase_on, 0, 10));
                });
        }

        $reordered = [];
        foreach ($texts as $text) {
            $after = $text->remind_on->toDateString();
            $products = collect($text->coveredItems())->pluck('product_id')->filter()->all();
            $hit = collect($numbersOf($text))->contains(fn (string $number) => ($repeat[$number] ?? '') >= $after
                || collect($products)->contains(fn (int $id) => ($ordered[$number][$id] ?? '') >= $after || ($delivered[$number][$id] ?? '') >= $after))
                || substr((string) ($markedReordered[$text->phone_key] ?? ''), 0, 10) >= $after;
            if ($hit) {
                $reordered[$text->id] = true;
            }
        }

        return $reordered;
    }

    /**
     * Earlier days' texts still to send on $remindOn (queued ones not handed to a phone too), unticked or failed, and follow-ups due on it (RestockReminder::scopeToSendOn),
     * are checked again: skipped when the customer has since reordered the product (for a follow-up: since the text before it),
     * opted out or is on $remindOn's own list (one text a customer), and otherwise given the customer's CRA now (none
     * when nobody has them). A queued one whose customer is another CRA's now leaves the queue: it goes out only when that
     * CRA sends it.
     *
     * @param  list<string>  $today  main numbers on $remindOn's own list
     */
    private function refreshCarriedOver(CarbonImmutable $remindOn, array $today): void
    {
        $carried = RestockReminder::whereDate('remind_on', '<=', $remindOn->toDateString())
            ->where(fn ($q) => $q->where('status', RestockReminder::PENDING)->orWhere(fn ($q) => $q->held())
                ->orWhere(fn ($q) => $q->where('status', RestockReminder::QUEUED)->whereNull('handed_at')))
            ->where(fn ($q) => $q->whereDate('remind_on', '<', $remindOn->toDateString())->orWhere('kind', RestockReminder::FOLLOW_UP))
            ->get(['id', 'phone_key', 'product_id', 'covers', 'lead_ids', 'products', 'run_out_on', 'remind_on', 'user_id', 'kind', 'status']);

        if ($carried->isEmpty()) {
            return;
        }

        $members = $this->members($carried->pluck('phone_key')->unique()->values()->all());
        $numbersOf = fn (RestockReminder $reminder) => $members[$reminder->phone_key] ?? [$reminder->phone_key];
        $allNumbers = $carried->flatMap($numbersOf)->unique()->values()->all();
        $optedOut = DoNotText::whereIn('phone_key', $allNumbers)->pluck('phone_key')->flip();
        $afterOf = fn (RestockReminder $reminder) => $reminder->isFollowUp() ? $reminder->remind_on->subDays(RestockReminder::FOLLOW_UP_DAYS) : $reminder->remind_on;
        $since = $carried->map($afterOf)->min();
        $ordered = $this->lastPancakeOrder($allNumbers, $since);
        $delivered = $this->latestDeliveries($allNumbers, $since);
        $craFor = $this->craPicker($carried->pluck('phone_key')->unique()->values()->all(), $remindOn);
        // A list text stays with the CRA whose list its lead is on now (moved with the lead).
        $active = LeadGenerator::cras()->pluck('id')->flip();
        $leadOwners = Lead::whereIn('id', $carried->flatMap(fn (RestockReminder $reminder) => $reminder->lead_ids ?? [])->unique()->values())->pluck('assigned_to', 'id');
        $listCra = fn (RestockReminder $reminder) => collect($reminder->lead_ids ?? [])->map(fn (int $id) => $leadOwners[$id] ?? null)
            ->first(fn (?int $craId) => $craId !== null && isset($active[$craId]));
        $onToday = array_flip($today);

        foreach ($carried as $reminder) {
            $numbers = $numbersOf($reminder);
            $after = $afterOf($reminder)->toDateString();
            $products = collect($reminder->coveredItems())->pluck('product_id')->filter();
            $reason = match (true) {
                // A queued one isn't combined into today's text (earlierTexts), so it stays.
                $reminder->status !== RestockReminder::QUEUED && isset($onToday[$reminder->phone_key]) && ! $reminder->remind_on->isSameDay($remindOn) => 'In a later reminder',
                collect($numbers)->contains(fn (string $number) => isset($optedOut[$number])) => 'Opted out',
                collect($numbers)->contains(fn (string $number) => $products->contains(fn (int $id) => ($ordered[$number][$id] ?? '') >= $after
                    || ($delivered[$number][$id] ?? '') >= $after)) => 'Already reordered',
                default => null,
            };

            if ($reason) {
                $reminder->update(['status' => RestockReminder::SKIPPED, 'error' => $reason]);

                continue;
            }

            $craId = $listCra($reminder) ?? $craFor($reminder->phone_key, $reminder->coveredItems()[0]['name'] ?? null, $reminder->user_id);
            $leavesQueue = $reminder->status === RestockReminder::QUEUED && $craId !== $reminder->user_id;
            $reminder->update(['user_id' => $craId, ...($leavesQueue ? ['status' => RestockReminder::PENDING, 'queued_at' => null] : [])]);
        }
    }

    /**
     * Who texts each customer, the way the Segmentation Tracker hands out leads (LeadGenerator::assign()): the customer's
     * own CRA (High AOV / VIP, then the CRA who handled the most of their orders, then the exclusive CRA of their FSA,
     * within niches: LeadGenerator::ownerFor()); else the CRA they already have on a reminder, so a rebuild doesn't move
     * them; else (when $share) the tracker CRA with the fewest texts on $day whose niche has the product, ties to the
     * earliest. The returned picker counts each customer it hands out, so the split stays even.
     *
     * @param  list<string>  $primaries  the customers' main numbers
     * @return Closure(string $primary, ?string $product, ?int $current, bool $share = true): ?int
     */
    public function craPicker(array $primaries, CarbonImmutable $day): Closure
    {
        $active = LeadGenerator::cras()->pluck('id')->flip();
        $owners = app(LeadGenerator::class)->ownersFor($primaries);
        $niches = NicheRules::current();
        // Oldest account first: ties go to the earliest CRA, as on the tracker.
        $splitCras = LeadGenerator::segmentationCras()->sortBy('id')->pluck('id');
        // Texts on $day that a rebuild leaves alone (left out, queued or sent) already count towards each CRA's share.
        $counted = RestockReminder::whereDate('remind_on', $day->toDateString())->whereNotIn('status', [RestockReminder::PENDING, RestockReminder::SKIPPED])
            ->whereIn('user_id', $splitCras)->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');
        $load = $splitCras->mapWithKeys(fn (int $craId) => [$craId => (int) ($counted[$craId] ?? 0)])->all();

        return function (string $primary, ?string $product, ?int $current, bool $share = true) use ($active, $owners, $niches, &$load): ?int {
            $owner = LeadGenerator::ownerFor($owners, $primary, $product, $niches);
            $craId = match (true) {
                $owner !== null && isset($active[$owner]) => $owner,
                $current !== null && isset($active[$current]) => $current,
                default => null,
            };

            if ($craId === null && $share) {
                $open = array_filter($load, fn (int $total, int $id) => $niches->allows($id, $product), ARRAY_FILTER_USE_BOTH);
                $craId = $open === [] ? null : array_search(min($open), $open, true);
            }

            if ($craId !== null && $share && array_key_exists($craId, $load)) {
                $load[$craId]++;
            }

            return $craId;
        };
    }

    /**
     * The latest delivery day of each product per number since $since (Shecom or Pancake POS deliveries).
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, array<int, string>> number => product id => Y-m-d
     */
    private function latestDeliveries(array $phoneKeys, CarbonImmutable $since): array
    {
        $latest = [];
        // Delivered orders keep numbers as entered: 9…, 09…, 639… or +639….
        $formats = collect($phoneKeys)->flatMap(fn (string $key) => [$key, '0'.$key, '63'.$key, '+63'.$key])->all();
        foreach (array_chunk($formats, 1000) as $chunk) {
            DeliveredOrder::whereIn('phone_number', $chunk)->whereDate('delivered_date', '>=', $since->toDateString())
                ->get(['phone_number', 'product_raw', 'delivered_date'])
                ->each(function (DeliveredOrder $order) use (&$latest) {
                    $day = $order->delivered_date->toDateString();
                    $product = $this->catalog->match((string) $order->product_raw, $day);
                    if ($product) {
                        $number = LeadGenerator::normalizePhone((string) $order->phone_number);
                        $latest[$number][$product->id] = max($latest[$number][$product->id] ?? '', $day);
                    }
                });
        }

        return $latest;
    }

    /**
     * The template a text gets: for a follow-up the one its CRA picked when scheduling it; else its CRA's active one for
     * the purpose (restock reminder or follow-up) that fits most closely (product + customer type, then product, then
     * customer type, then any), the newest when two fit as closely; null when none fits (another CRA's never does).
     */
    public function templateFor(RestockReminder $reminder): ?SmsTemplate
    {
        $this->templates ??= SmsTemplate::where('is_active', true)->orderByDesc('id')->get();

        // A product that already ran out gets the general text (messageFor), not a reminder template.
        if (self::isOverdue($reminder)) {
            return null;
        }

        // A follow-up the CRA scheduled with a template of theirs uses it.
        if ($reminder->isFollowUp() && $reminder->sms_template_id && $reminder->status !== RestockReminder::SENT) {
            $picked = $this->templates->firstWhere('id', $reminder->sms_template_id);
            if ($picked && (int) $picked->user_id === (int) $reminder->user_id) {
                return $picked;
            }
        }

        $purpose = $reminder->isFollowUp() ? SmsTemplate::FOLLOW_UP : SmsTemplate::REMINDER;

        return $this->templates
            ->filter(fn (SmsTemplate $template) => $template->purpose === $purpose)
            ->filter(fn (SmsTemplate $template) => $reminder->user_id !== null && (int) $template->user_id === (int) $reminder->user_id
                && ($template->product_id === null || $template->product_id === $reminder->product_id)
                && ($template->customer_type === null || $template->customer_type === $reminder->customer_type))
            ->sortByDesc(fn (SmsTemplate $template) => ($template->product_id ? 2 : 0) + ($template->customer_type ? 1 : 0))
            ->first();
    }

    /**
     * Whether a list text's product already ran out before today (real date): the tracker lists run about a month behind,
     * leftovers more, so "malapit na maubos" and the date would be wrong. Such texts get the general text
     * (Settings → SMS Reminders) instead of a reminder template. Follow-ups keep their own templates.
     */
    public static function isOverdue(RestockReminder $reminder): bool
    {
        return ! $reminder->isFollowUp() && $reminder->run_out_on !== null
            && $reminder->run_out_on->toDateString() < WorkingDate::realToday()->toDateString();
    }

    /**
     * The text the customer gets: their template (or the default, or the general text for a product that already ran
     * out) filled in, with the opt-out line.
     */
    public function messageFor(RestockReminder $reminder, ?User $cra = null): string
    {
        $default = match (true) {
            $reminder->isFollowUp() => config('sms_reminders.follow_up_body'),
            self::isOverdue($reminder) => SmsSettings::overdueBody(),
            default => config('sms_reminders.default_body'),
        };

        return $this->fill($this->templateFor($reminder)?->body ?? $default, $reminder, $cra ?? $reminder->cra);
    }

    public function fill(string $body, RestockReminder $reminder, ?User $cra, bool $withOptOut = true): string
    {
        $text = strtr($body, [
            '{first_name}' => $reminder->firstName() ?: 'po',
            '{product}' => $reminder->productsPhrase(),
            '{run_out_date}' => $reminder->runOutPhrase(),
            '{cra_name}' => $cra?->smsName() ?? 'ang CRA mo',
        ]);
        $optOut = config('sms_reminders.opt_out_line');

        return trim($withOptOut && ! str_contains(strtoupper($text), 'STOP') ? $text.' '.$optOut : $text);
    }

    /**
     * Delivered orders on record per number (a customer with 2+ is a repeat customer, customerTypes()).
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, int>
     */
    private function deliveryCounts(array $phoneKeys): array
    {
        $formats = collect($phoneKeys)->flatMap(fn (string $key) => [$key, '0'.$key, '63'.$key, '+63'.$key])->all();
        $counts = [];
        foreach (array_chunk($formats, 1000) as $chunk) {
            DeliveredOrder::whereIn('phone_number', $chunk)->toBase()->get(['phone_number'])
                ->each(function (object $order) use (&$counts) {
                    $key = LeadGenerator::normalizePhone((string) $order->phone_number);
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                });
        }

        return $counts;
    }

    public static function isMobile(string $phoneKey): bool
    {
        return strlen($phoneKey) === 10 && str_starts_with($phoneKey, '9');
    }

    /**
     * @param  list<string>  $phoneKeys
     * @return array<string, string> phone key => main number
     */
    private function primaries(array $phoneKeys): array
    {
        $links = [];
        foreach (array_chunk($phoneKeys, 1000) as $chunk) {
            $links += CustomerLink::whereIn('phone_key', $chunk)->pluck('primary_phone_key', 'phone_key')->all();
        }

        return collect($phoneKeys)->mapWithKeys(fn (string $key) => [$key => (string) ($links[$key] ?? $key)])->all();
    }

    /**
     * @param  list<string>  $primaries
     * @return array<string, list<string>> main number => every number of the customer
     */
    private function members(array $primaries): array
    {
        $members = collect($primaries)->mapWithKeys(fn (string $primary) => [$primary => [$primary]])->all();
        foreach (array_chunk($primaries, 1000) as $chunk) {
            CustomerLink::whereIn('primary_phone_key', $chunk)->get(['phone_key', 'primary_phone_key'])
                ->each(function (CustomerLink $link) use (&$members) {
                    $members[$link->primary_phone_key][] = (string) $link->phone_key;
                });
        }

        return $members;
    }

    /**
     * Each number's latest counted Pancake POS order day of each product: an order placed but not yet delivered is a
     * reorder too.
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, array<int, string>> phone key => product id => day
     */
    private function lastPancakeOrder(array $phoneKeys, CarbonImmutable $since): array
    {
        $last = [];
        foreach (array_chunk($phoneKeys, 1000) as $chunk) {
            PancakeOrder::counted()->whereIn('phone_key', $chunk)->whereDate('ordered_on', '>=', $since->toDateString())
                ->get(['id', 'phone_key', 'ordered_on', 'items'])
                ->each(function (PancakeOrder $order) use (&$last) {
                    $day = $order->ordered_on->toDateString();
                    foreach ($order->items ?? [] as $item) {
                        $product = $this->catalog->match((string) ($item['name'] ?? ''), $day);
                        if ($product) {
                            $last[$order->phone_key][$product->id] = max($last[$order->phone_key][$product->id] ?? '', $day);
                        }
                    }
                });
        }

        return $last;
    }

    /**
     * High AOV / VIP first, then Cold (their lead is tagged cold), then Repeat (two or more deliveries), else First reorder.
     *
     * @param  list<string>  $primaries
     * @param  array<string, list<string>>  $members
     * @param  array<string, int>  $deliveries
     * @return array<string, string>
     */
    private function customerTypes(array $primaries, array $members, array $deliveries): array
    {
        try {
            $highValue = app(HighValueCustomers::class)->flagsForNumbers($primaries);
        } catch (Throwable $e) {
            report($e);
            $highValue = [];
        }

        $cold = Lead::whereIn('customer_tag', config('segmentation.cold_tags'))->pluck('phone_number')
            ->map(fn (string $phone) => LeadGenerator::normalizePhone($phone))->flip();

        return collect($primaries)->mapWithKeys(function (string $primary) use ($members, $deliveries, $highValue, $cold) {
            $numbers = $members[$primary] ?? [$primary];
            $flags = $highValue[$primary] ?? [];

            return [$primary => match (true) {
                ($flags['high_aov'] ?? false) || ($flags['vip'] ?? false) => 'vip',
                collect($numbers)->contains(fn (string $number) => isset($cold[$number])) => 'cold',
                collect($numbers)->sum(fn (string $number) => $deliveries[$number] ?? 0) >= 2 => 'repeat',
                default => 'first',
            }];
        })->all();
    }
}
