<?php

namespace App\Services;

use App\Models\CustomerLink;
use App\Models\DeliveredOrder;
use App\Models\DoNotText;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\RestockReminder;
use App\Models\SmsTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Who gets a restock text on a reminder day, and what it says. A customer is due when a delivered order (Shecom or
 * Pancake POS) runs out replenishment_days_before days later: delivered date + qty × consumption days − 1, days from
 * Settings → Product Consumption, as for leads. Always by the real date: the Working Date runs behind, and texts go
 * to customers in real time. TSD upsell products never match, so TSD-only orders never get a text.
 */
class RestockReminders
{
    /** How far back a delivery can still run out on the day (qty × consumption days). */
    private const LOOKBACK_DAYS = 400;

    /** A customer's products running out within this many days of the first go in one text. */
    public const COMBINE_DAYS = 7;

    /** @var Collection<int, SmsTemplate>|null */
    private ?Collection $templates = null;

    public function __construct(private ProductCatalog $catalog, private CustomerOwners $owners) {}

    public static function daysBefore(): int
    {
        return (int) config('segmentation.replenishment_days_before');
    }

    /**
     * Saves $remindOn's reminders: one per customer (merged numbers once), for their own CRA, for customers with a product
     * running out on $remindOn + daysBefore(). Their other products running out within COMBINE_DAYS go in the same text
     * (covers), as do earlier days' reminders not sent yet, so a customer gets one text; a product and run-out already
     * texted (or left out by the CRA) is never texted again, and products they restocked (same product delivered or
     * ordered since) are left out. Ones already left out, queued or sent stay as they are; a pending one whose customer
     * has since reordered, opted out or was already texted is skipped.
     *
     * @return array{due: int, skipped: int}
     */
    public function build(CarbonImmutable $remindOn): array
    {
        $runOut = $remindOn->addDays(self::daysBefore());
        [$due, $latestDelivery, $deliveries] = $this->runningOut($runOut, $runOut->addDays(self::COMBINE_DAYS - 1));

        // One customer per main number (Customer Database merge); only customers with a product running out on $runOut.
        $primaryOf = $this->primaries(array_keys($due));
        $customers = [];
        foreach ($due as $phoneKey => $orders) {
            $primary = $primaryOf[$phoneKey];
            $customers[$primary] = [...($customers[$primary] ?? []), ...$orders];
        }
        $customers = array_filter($customers, fn (array $orders) => collect($orders)->contains('run_out', $runOut->toDateString()));
        $members = $this->members(array_keys($customers));

        $allNumbers = array_values(array_unique(array_merge(...array_values($members ?: [[]]))));
        $orderedSince = $this->lastPancakeOrder($allNumbers, $runOut->subDays(self::LOOKBACK_DAYS));
        $optedOut = DoNotText::whereIn('phone_key', $allNumbers)->pluck('phone_key')->flip();
        $owners = $this->owners->for(array_keys($customers));
        $types = $this->customerTypes(array_keys($customers), $members, $deliveries);
        [$coveredOn, $carried] = $this->earlierTexts(array_keys($customers), $remindOn);

        $saved = [];
        $skipped = 0;
        foreach ($customers as $primary => $orders) {
            $numbers = $members[$primary] ?? [$primary];
            // A product counts as restocked when the same product was delivered or ordered (Pancake POS, not canceled)
            // after the delivery that runs out; another product doesn't count. Only products not restocked are texted.
            $stillDue = collect($orders)->reject(fn (array $order) => collect($numbers)->contains(
                fn (string $number) => ($latestDelivery[$number][$order['product']->id] ?? '') > $order['delivered']
                    || ($orderedSince[$number][$order['product']->id] ?? '') > $order['delivered']));
            $textedOn = fn (array $order) => $coveredOn[$primary.'|'.$order['product']->id.'|'.$order['run_out']] ?? null;
            $toText = $stillDue->reject(fn (array $order) => $textedOn($order) !== null);
            $todays = fn (Collection $list) => $list->where('run_out', $runOut->toDateString());
            $opted = collect($numbers)->contains(fn (string $number) => isset($optedOut[$number]));
            $first = collect($orders)->sortByDesc('delivered')->first();

            $reason = match (true) {
                $opted => 'Opted out',
                $todays($stillDue)->isEmpty() => 'Already reordered',
                $todays($toText)->isEmpty() => 'Already texted on '.CarbonImmutable::parse($textedOn($todays($stillDue)->first()))->format('M j'),
                default => null,
            };

            // Every product and run-out the text is for: earliest run-out of each product, and earlier unsent reminders.
            $covers = ($reason ? $todays(collect($orders)) : $toText)->sortBy('run_out')->unique(fn (array $order) => $order['product']->id)
                ->map(fn (array $order) => ['product_id' => $order['product']->id, 'name' => $order['product']->name, 'run_out_on' => $order['run_out']])->values();
            if (! $reason) {
                foreach ($carried[$primary] ?? [] as $earlier) {
                    $covers = $covers->concat($earlier->coveredItems());
                }
                $covers = $covers->sortBy('run_out_on')->unique('product_id')->values();
            }

            $attributes = [
                'phone_number' => $first['phone'],
                'customer_name' => $first['name'],
                'product_id' => $covers->first()['product_id'],
                'products' => $covers->pluck('name')->implode(' + '),
                'covers' => $covers->all(),
                'customer_type' => $types[$primary],
                'run_out_on' => $covers->min('run_out_on'),
                'user_id' => $owners[$primary] ?? null,
            ];
            $skipped += $reason ? 1 : 0;
            $saved[] = $primary;

            $reminder = RestockReminder::where('phone_key', $primary)->whereDate('remind_on', $remindOn->toDateString())->first();
            $status = ['status' => $reason ? RestockReminder::SKIPPED : RestockReminder::PENDING, 'error' => $reason];

            if (! $reminder) {
                RestockReminder::create([...$attributes, ...$status, 'phone_key' => $primary, 'remind_on' => $remindOn->toDateString()]);
            } elseif (in_array($reminder->status, [RestockReminder::PENDING, RestockReminder::SKIPPED], true)) {
                // A follow-up due the same day becomes this text, keeping its products and its place in the follow-ups.
                if ($reminder->isFollowUp() && $reminder->status === RestockReminder::PENDING) {
                    $attributes['covers'] = collect($attributes['covers'])->concat($reminder->coveredItems())->sortBy('run_out_on')->unique('product_id')->values()->all();
                    $attributes['products'] = collect($attributes['covers'])->pluck('name')->implode(' + ');
                }
                $reminder->update([...$attributes, ...$status, 'kind' => RestockReminder::REMINDER]);
            }

            if (! $reason) {
                RestockReminder::whereIn('id', collect($carried[$primary] ?? [])->pluck('id'))
                    ->update(['status' => RestockReminder::SKIPPED, 'error' => 'Combined into the '.$remindOn->format('M j').' text']);
            }
        }

        // Pending reminders no longer due (e.g. a product's consumption days changed) are skipped.
        RestockReminder::whereDate('remind_on', $remindOn->toDateString())->where('status', RestockReminder::PENDING)
            ->where('kind', RestockReminder::REMINDER)->whereNotIn('phone_key', $saved ?: [''])
            ->update(['status' => RestockReminder::SKIPPED, 'error' => 'Not due any more']);

        $this->refreshCarriedOver($remindOn, $saved);

        return ['due' => count($customers) - $skipped, 'skipped' => $skipped];
    }

    /**
     * These customers' texts from before $remindOn: the day each product and run-out was texted or left out
     * ("main number|product id|Y-m-d" => remind day), and their reminders still to send on $remindOn (combined into it).
     *
     * @param  list<string>  $primaries
     * @return array{0: array<string, string>, 1: array<string, list<RestockReminder>>}
     */
    private function earlierTexts(array $primaries, CarbonImmutable $remindOn): array
    {
        $coveredOn = [];
        $carried = [];

        foreach (array_chunk($primaries, 1000) as $chunk) {
            RestockReminder::whereIn('phone_key', $chunk)->whereDate('remind_on', '<', $remindOn->toDateString())
                ->whereIn('status', RestockReminder::COVERING)
                ->get(['id', 'phone_key', 'product_id', 'products', 'covers', 'run_out_on', 'remind_on', 'status', 'kind'])
                ->each(function (RestockReminder $reminder) use (&$coveredOn, &$carried, $remindOn) {
                    foreach ($reminder->coveredItems() as $item) {
                        $key = $reminder->phone_key.'|'.$item['product_id'].'|'.$item['run_out_on'];
                        $coveredOn[$key] = min($coveredOn[$key] ?? '9999', $reminder->remind_on->toDateString());
                    }
                    if ($reminder->status === RestockReminder::PENDING && ! $reminder->isFollowUp() && $reminder->run_out_on->gte($remindOn)) {
                        $carried[$reminder->phone_key][] = $reminder;
                    }
                });
        }

        // A reminder still to send doesn't keep today's text from covering the same products again: it is combined into it.
        foreach ($carried as $primary => $reminders) {
            foreach ($reminders as $reminder) {
                foreach ($reminder->coveredItems() as $item) {
                    unset($coveredOn[$primary.'|'.$item['product_id'].'|'.$item['run_out_on']]);
                }
            }
        }

        return [$coveredOn, $carried];
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
        $owners = $this->owners->for($texted->pluck('phone_key')->all());
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
                'user_id' => $owners[$text->phone_key] ?? $text->user_id,
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
     * Earlier days' texts still to send on $remindOn and follow-ups due on it (RestockReminder::scopeToSendOn) are checked
     * again: skipped when the customer has since reordered the product (for a follow-up: since the text before it),
     * opted out or is on $remindOn's own list (one text a customer), and otherwise given the customer's CRA now (none
     * when nobody has them).
     *
     * @param  list<string>  $today  main numbers on $remindOn's own list
     */
    private function refreshCarriedOver(CarbonImmutable $remindOn, array $today): void
    {
        $carried = RestockReminder::toSendOn($remindOn)->where('status', RestockReminder::PENDING)
            ->where(fn ($q) => $q->whereDate('remind_on', '<', $remindOn->toDateString())->orWhere('kind', RestockReminder::FOLLOW_UP))
            ->get(['id', 'phone_key', 'product_id', 'covers', 'products', 'run_out_on', 'remind_on', 'user_id', 'kind']);

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
        $owners = $this->owners->for($carried->pluck('phone_key')->unique()->values()->all());
        $onToday = array_flip($today);

        foreach ($carried as $reminder) {
            $numbers = $numbersOf($reminder);
            $after = $afterOf($reminder)->toDateString();
            $products = collect($reminder->coveredItems())->pluck('product_id')->filter();
            $reason = match (true) {
                isset($onToday[$reminder->phone_key]) && ! $reminder->remind_on->isSameDay($remindOn) => 'In a later reminder',
                collect($numbers)->contains(fn (string $number) => isset($optedOut[$number])) => 'Opted out',
                collect($numbers)->contains(fn (string $number) => $products->contains(fn (int $id) => ($ordered[$number][$id] ?? '') >= $after
                    || ($delivered[$number][$id] ?? '') >= $after)) => 'Already reordered',
                default => null,
            };

            $reminder->update($reason
                ? ['status' => RestockReminder::SKIPPED, 'error' => $reason]
                : ['user_id' => $owners[$reminder->phone_key] ?? null]);
        }
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
     * The text the customer gets: their template (or the default) filled in, with the opt-out line.
     */
    public function messageFor(RestockReminder $reminder, ?User $cra = null): string
    {
        $default = $reminder->isFollowUp() ? config('sms_reminders.follow_up_body') : config('sms_reminders.default_body');

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
     * Delivered orders running out on $runOut to $until (with their run-out day), by number; plus each number's latest
     * delivery of each product (reorders) and delivery count (customer type) in the window.
     *
     * @return array{0: array<string, list<array{delivered: string, product: Product, phone: string, name: ?string, run_out: string}>>, 1: array<string, array<int, string>>, 2: array<string, int>}
     */
    private function runningOut(CarbonImmutable $runOut, CarbonImmutable $until): array
    {
        $due = [];
        $latest = [];
        $count = [];

        DeliveredOrder::query()
            ->whereBetween('delivered_date', [$runOut->subDays(self::LOOKBACK_DAYS)->toDateString(), $runOut->toDateString()])
            ->select(['id', 'order_id', 'customer_name', 'phone_number', 'product_raw', 'qty', 'delivered_date', 'consumption_days_per_unit'])
            ->orderBy('id')
            ->lazy(2000)
            ->each(function (DeliveredOrder $order) use ($runOut, $until, &$due, &$latest, &$count) {
                $phoneKey = LeadGenerator::normalizePhone((string) $order->phone_number);

                if (! self::isMobile($phoneKey)) {
                    return;
                }

                $delivered = $order->delivered_date->toDateString();
                $count[$phoneKey] = ($count[$phoneKey] ?? 0) + 1;
                $product = $this->catalog->match((string) $order->product_raw, $delivered);

                if (! $product) {
                    return;
                }

                $latest[$phoneKey][$product->id] = max($latest[$phoneKey][$product->id] ?? '', $delivered);
                $days = $product->consumption_days ?: $order->consumption_days_per_unit;

                if (! $days) {
                    return;
                }

                // The delivery day is day 1.
                $runsOut = $order->delivered_date->addDays(max(1, (int) $order->qty) * $days - 1)->toDateString();
                if ($runsOut >= $runOut->toDateString() && $runsOut <= $until->toDateString()) {
                    $due[$phoneKey][] = ['delivered' => $delivered, 'product' => $product, 'phone' => $order->phone_number, 'name' => $order->customer_name, 'run_out' => $runsOut];
                }
            });

        return [$due, $latest, $count];
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
