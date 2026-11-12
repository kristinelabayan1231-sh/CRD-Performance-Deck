<?php

namespace App\Services;

use App\Models\CustomerLink;
use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Support\NicheRules;
use Carbon\CarbonImmutable;

/**
 * Customers missing a lead: their last delivery (Customer Database deliveries, a Product Consumption product) ran out of
 * stock by the lead day — delivered + qty × consumption days − 1 — but no lead was ever made for it or a later delivery,
 * and they haven't ordered again since. Most are from before the app made leads every day (leads:create-missing).
 *
 * Each becomes a lead as the lead generator would have made it on its out-of-stock day: for the customer's own CRA
 * (LeadGenerator::ownerFor), else unassigned in the pool for the daily fill to hand out.
 */
class MissingLeads
{
    /** Customers worked out at a time, so memory stays small however many there are. */
    private const CHUNK = 2000;

    public function __construct(private CustomerOwners $owners, private HighValueCustomers $highValue) {}

    /**
     * Work out every missing lead as of $leadDay, a batch of customers at a time, handing each batch's lead rows to $each.
     *
     * @param  callable(array<string, array<string, mixed>>): void  $each  customer (main number) => lead row
     */
    public function scan(CarbonImmutable $leadDay, callable $each): void
    {
        $primaryOf = CustomerLink::pluck('primary_phone_key', 'phone_key')->map(fn ($key) => (string) $key)->all();
        $customerOf = fn (string $key) => $primaryOf[$key] ?? $key;

        // Leads already made: by order, and each customer's latest delivery that has one.
        $leadOrders = [];
        $leadDelivered = [];
        foreach (Lead::toBase()->select(['order_id', 'phone_number', 'delivered_date'])->cursor() as $lead) {
            $leadOrders[(string) $lead->order_id] = true;
            $customer = $customerOf(LeadGenerator::normalizePhone((string) $lead->phone_number));
            $leadDelivered[$customer] = max($leadDelivered[$customer] ?? '', substr((string) $lead->delivered_date, 0, 10));
        }

        $numbersOf = [];
        LogisticsOrder::covered()->where('non_crd', false)->whereNotNull('phone_key')->distinct()->pluck('phone_key')
            ->each(function ($key) use (&$numbersOf, $customerOf) {
                $numbersOf[$customerOf((string) $key)][] = (string) $key;
            });

        $context = [
            'lead_day' => $leadDay->startOfDay(),
            'lead_orders' => $leadOrders,
            'lead_delivered' => $leadDelivered,
            'catalog' => new ProductCatalog,
            'niches' => NicheRules::current(),
            'cras' => LeadGenerator::cras()->pluck('id')->all(),
        ];

        foreach (array_chunk($numbersOf, self::CHUNK, true) as $chunk) {
            $rows = $this->buildChunk($chunk, $context);

            if ($rows !== []) {
                $each($rows);
            }
        }
    }

    /**
     * The missing leads of these customers as lead rows, each with its CRA or none (the pool).
     *
     * @param  array<string, list<string>>  $numbersOf  customer (main number) => every number
     * @param  array<string, mixed>  $context  from scan()
     * @return array<string, array<string, mixed>> customer => lead row
     */
    private function buildChunk(array $numbersOf, array $context): array
    {
        $customerOf = [];
        foreach ($numbersOf as $customer => $numbers) {
            foreach ($numbers as $number) {
                $customerOf[$number] = (string) $customer;
            }
        }
        $numbers = array_keys($customerOf);

        // Each customer's last delivery.
        $last = [];
        foreach (LogisticsOrder::covered()->where('non_crd', false)->whereIn('phone_key', $numbers)
            ->orderBy('delivered_date')->orderBy('id')
            ->get(['order_id', 'team', 'customer_name', 'phone_number', 'phone_key', 'product', 'qty', 'delivered_date']) as $order) {
            $last[$customerOf[(string) $order->phone_key]] = $order;
        }

        // Each customer's latest counted order: one after the delivery is a reorder.
        $lastOrdered = [];
        PancakeOrder::counted()->whereIn('phone_key', $numbers)->toBase()->groupBy('phone_key')
            ->selectRaw('phone_key, max(ordered_on) as last_on')
            ->get()->each(function ($row) use (&$lastOrdered, $customerOf) {
                $customer = $customerOf[(string) $row->phone_key];
                $lastOrdered[$customer] = max($lastOrdered[$customer] ?? '', substr((string) $row->last_on, 0, 10));
            });

        $candidates = [];
        foreach ($last as $customer => $order) {
            $delivered = $order->delivered_date->toDateString();

            if (isset($context['lead_orders'][(string) $order->order_id]) || ($context['lead_delivered'][$customer] ?? '') >= $delivered
                || ($lastOrdered[$customer] ?? '') > $delivered) {
                continue;
            }

            $product = $context['catalog']->match((string) $order->product, $delivered);
            if ($product !== null && $product->consumption_days) {
                $candidates[$customer] = [$order, $product, $delivered];
            }
        }

        // Qty from the delivery, else the saved delivered order, else 1 (flagged, as the lead generator does).
        $noQty = array_values(array_map(fn (array $c) => (string) $c[0]->order_id, array_filter($candidates, fn (array $c) => ! $c[0]->qty)));
        $savedQty = $noQty === [] ? [] : DeliveredOrder::whereIn('order_id', $noQty)->whereNotNull('qty')->pluck('qty', 'order_id')->all();

        $rows = [];
        foreach ($candidates as $customer => [$order, $product, $delivered]) {
            $qty = (int) ($order->qty ?: ($savedQty[(string) $order->order_id] ?? 0));
            $units = max(1, $qty);
            $outOfStock = CarbonImmutable::parse($delivered)->addDays($units * $product->consumption_days - 1);

            if ($outOfStock->greaterThan($context['lead_day'])) {
                continue;
            }

            $rows[(string) $customer] = [
                'order_id' => (string) $order->order_id,
                'lead_type' => $order->team === LogisticsOrder::TEAM_CRD ? Lead::TYPE_CRD : Lead::TYPE_FSD,
                'customer_name' => trim((string) $order->customer_name) ?: null,
                'phone_number' => (string) $order->phone_number,
                'product_name' => $product->name,
                'product_raw' => (string) $order->product,
                'qty' => $units,
                'qty_unknown' => $qty === 0,
                'consumption_days' => (int) $product->consumption_days,
                'delivered_date' => $delivered,
                'est_out_of_stock_date' => $outOfStock->toDateString(),
                'assigned_to' => null,
            ];
        }

        if ($rows === []) {
            return [];
        }

        // Their own CRA as the lead rules pick one: a claim or High AOV / VIP CRA whatever the product, else the CRA with
        // the most orders when the product is in their niche. Everyone else (FSD customers no CRA has handled) is the pool.
        $keys = array_map('strval', array_keys($rows));
        $owners = ['high_value' => $this->owners->claimed($keys) + $this->highValue->crasFor($keys), 'handled' => $this->owners->for($keys)];

        foreach ($rows as $key => $row) {
            $owner = LeadGenerator::ownerFor($owners, (string) $key, $row['product_name'], $context['niches']);
            $rows[$key]['assigned_to'] = $owner !== null && in_array($owner, $context['cras'], true) ? $owner : null;
        }

        return $rows;
    }
}
