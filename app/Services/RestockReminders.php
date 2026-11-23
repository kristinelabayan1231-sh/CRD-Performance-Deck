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

    /** @var Collection<int, SmsTemplate>|null */
    private ?Collection $templates = null;

    public function __construct(private ProductCatalog $catalog, private CustomerOwners $owners) {}

    public static function daysBefore(): int
    {
        return (int) config('segmentation.replenishment_days_before');
    }

    /**
     * Saves $remindOn's reminders: one per customer (merged numbers once), for their own CRA, with only the products they
     * haven't restocked (same product delivered or ordered since). Ones already left out,
     * queued or sent stay as they are; a pending one whose customer has since reordered or opted out is skipped.
     *
     * @return array{due: int, skipped: int}
     */
    public function build(CarbonImmutable $remindOn): array
    {
        $runOut = $remindOn->addDays(self::daysBefore());
        [$due, $latestDelivery, $deliveries] = $this->runningOut($runOut);

        // One customer per main number (Customer Database merge).
        $primaryOf = $this->primaries(array_keys($due));
        $members = $this->members(array_values(array_unique($primaryOf)));
        $customers = [];
        foreach ($due as $phoneKey => $orders) {
            $primary = $primaryOf[$phoneKey];
            $customers[$primary] = [...($customers[$primary] ?? []), ...$orders];
        }

        $allNumbers = array_values(array_unique(array_merge(...array_values($members ?: [[]]))));
        $orderedSince = $this->lastPancakeOrder($allNumbers, $runOut->subDays(self::LOOKBACK_DAYS));
        $optedOut = DoNotText::whereIn('phone_key', $allNumbers)->pluck('phone_key')->flip();
        $owners = $this->owners->for(array_keys($customers));
        $types = $this->customerTypes(array_keys($customers), $members, $deliveries);

        $saved = [];
        $skipped = 0;
        foreach ($customers as $primary => $orders) {
            $numbers = $members[$primary] ?? [$primary];
            // A product counts as restocked when the same product was delivered or ordered (Pancake POS, not canceled)
            // after the delivery that runs out; another product doesn't count. Only products not restocked are texted.
            $stillDue = collect($orders)->reject(fn (array $order) => collect($numbers)->contains(
                fn (string $number) => ($latestDelivery[$number][$order['product']->id] ?? '') > $order['delivered']
                    || ($orderedSince[$number][$order['product']->id] ?? '') > $order['delivered']));
            $reordered = $stillDue->isEmpty();
            $opted = collect($numbers)->contains(fn (string $number) => isset($optedOut[$number]));
            $first = collect($orders)->sortByDesc('delivered')->first();
            $products = ($reordered ? collect($orders) : $stillDue)->pluck('product')->unique('id')->values();

            $attributes = [
                'phone_number' => $first['phone'],
                'customer_name' => $first['name'],
                'product_id' => $products->first()->id,
                'products' => $products->pluck('name')->implode(' + '),
                'customer_type' => $types[$primary],
                'run_out_on' => $runOut->toDateString(),
                'user_id' => $owners[$primary] ?? null,
            ];
            $reason = match (true) {
                $opted => 'Opted out',
                $reordered => 'Already reordered',
                default => null,
            };
            $skipped += $reason ? 1 : 0;
            $saved[] = $primary;

            $reminder = RestockReminder::where('phone_key', $primary)->whereDate('remind_on', $remindOn->toDateString())->first();

            if (! $reminder) {
                RestockReminder::create([...$attributes, 'phone_key' => $primary, 'remind_on' => $remindOn->toDateString(),
                    'status' => $reason ? RestockReminder::SKIPPED : RestockReminder::PENDING, 'error' => $reason]);
            } elseif (in_array($reminder->status, [RestockReminder::PENDING, RestockReminder::SKIPPED], true)) {
                $reminder->update([...$attributes, 'status' => $reason ? RestockReminder::SKIPPED : RestockReminder::PENDING, 'error' => $reason]);
            }
        }

        // Pending ones no longer due (e.g. a product's consumption days changed) are skipped.
        RestockReminder::whereDate('remind_on', $remindOn->toDateString())->where('status', RestockReminder::PENDING)
            ->whereNotIn('phone_key', $saved ?: [''])
            ->update(['status' => RestockReminder::SKIPPED, 'error' => 'Not due any more']);

        return ['due' => count($customers) - $skipped, 'skipped' => $skipped];
    }

    /**
     * The template a reminder gets: its CRA's active one that fits most closely (product + customer type, then product,
     * then customer type, then any), the newest when two fit as closely; null when none fits (another CRA's never does).
     */
    public function templateFor(RestockReminder $reminder): ?SmsTemplate
    {
        $this->templates ??= SmsTemplate::where('is_active', true)->where('purpose', SmsTemplate::REMINDER)->orderByDesc('id')->get();

        return $this->templates
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
        return $this->fill($this->templateFor($reminder)?->body ?? config('sms_reminders.default_body'), $reminder, $cra ?? $reminder->cra);
    }

    public function fill(string $body, RestockReminder $reminder, ?User $cra, bool $withOptOut = true): string
    {
        $text = strtr($body, [
            '{first_name}' => $reminder->firstName() ?: 'po',
            '{product}' => $reminder->products,
            '{run_out_date}' => $reminder->run_out_on->format('j M'),
            '{cra_name}' => $cra?->smsName() ?? 'ang CRA mo',
        ]);
        $optOut = config('sms_reminders.opt_out_line');

        return trim($withOptOut && ! str_contains(strtoupper($text), 'STOP') ? $text.' '.$optOut : $text);
    }

    /**
     * Delivered orders running out on $runOut, by number; plus each number's latest delivery of each product (reorders)
     * and delivery count (customer type) in the window.
     *
     * @return array{0: array<string, list<array{delivered: string, product: Product, phone: string, name: ?string}>>, 1: array<string, array<int, string>>, 2: array<string, int>}
     */
    private function runningOut(CarbonImmutable $runOut): array
    {
        $due = [];
        $latest = [];
        $count = [];

        DeliveredOrder::query()
            ->whereBetween('delivered_date', [$runOut->subDays(self::LOOKBACK_DAYS)->toDateString(), $runOut->toDateString()])
            ->select(['id', 'order_id', 'customer_name', 'phone_number', 'product_raw', 'qty', 'delivered_date', 'consumption_days_per_unit'])
            ->orderBy('id')
            ->lazy(2000)
            ->each(function (DeliveredOrder $order) use ($runOut, &$due, &$latest, &$count) {
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
                if ($order->delivered_date->addDays(max(1, (int) $order->qty) * $days - 1)->isSameDay($runOut)) {
                    $due[$phoneKey][] = ['delivered' => $delivered, 'product' => $product, 'phone' => $order->phone_number, 'name' => $order->customer_name];
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
