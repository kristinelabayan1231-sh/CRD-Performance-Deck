<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Customers a CRA sold to in Pancake POS who aren't in the Segmentation Tracker: each becomes a catered
 * lead on the order day for that CRA, with Repeat Purchase = Yes, so the dashboard's Retained and Repeat
 * tiles count them. CRD Lead when the customer had an earlier CRA-handled order, else FSD Lead.
 *
 * Only orders sold from a CRA's own Pancake account (User Access), with a product from the Product
 * Consumption list, not canceled or deleted.
 */
class PosLeads
{
    public function __construct(private CustomerDatabase $customers) {}

    /**
     * Add the day's missing POS customers as leads.
     *
     * @return int leads added
     */
    public function addMissing(CarbonImmutable $day): int
    {
        /** @var Collection<string, User> $cras */
        $cras = LeadGenerator::cras()->filter(fn (User $cra) => $cra->pancake_name)
            ->keyBy(fn (User $cra) => PancakeEngagement::staffKey($cra->pancake_name));

        if ($cras->isEmpty()) {
            return 0;
        }

        $orders = PancakeOrder::whereDate('ordered_on', $day)->whereIn('seller_name', $cras->keys())
            ->crdProducts()->counted()->whereNotNull('phone_key')
            ->orderBy('ordered_at')->orderBy('id')
            ->get()
            ->unique('phone_key');

        $known = $this->trackedPhones($orders->pluck('phone_key')->all());
        $catalog = new ProductCatalog;
        $added = 0;

        foreach ($orders->reject(fn (PancakeOrder $order) => isset($known[$order->phone_key])) as $order) {
            $this->addLead($order, $cras[$order->seller_name], $catalog);
            $added++;
        }

        return $added;
    }

    private function addLead(PancakeOrder $order, User $cra, ProductCatalog $catalog): void
    {
        $items = collect($order->items ?? []);
        $names = $items->pluck('name')->filter()->values();
        $product = $names->map(fn (string $name) => $catalog->match($name))->filter()->first();
        $orderedAt = $order->ordered_at ?? now();
        $day = $order->ordered_on->toDateString();

        Lead::create([
            'order_id' => Lead::POS_PREFIX.$order->pancake_order_id,
            'customer_name' => trim((string) $order->customer_name),
            'phone_number' => $order->phone_key,
            'product_name' => $product?->name ?? ($names->first() ?? '—'),
            'product_raw' => $names->implode(', '),
            'qty' => max(1, (int) $items->sum('qty')),
            // Not delivered yet: the order day stands in, and the order day is its lead day.
            'delivered_date' => $day,
            'consumption_days' => (int) $product?->consumption_days,
            'est_out_of_stock_date' => $day,
            'lead_type' => $this->customers->hasCraOrderBefore($order->phone_key, $orderedAt) ? Lead::TYPE_CRD : Lead::TYPE_FSD,
            'assigned_to' => $cra->id,
            'assigned_at' => $orderedAt,
            'status' => 'active',
            'status_updated_by' => $cra->id,
            'status_updated_at' => $orderedAt,
            'repeat_purchase' => 'yes',
            'repeat_purchase_on' => $day,
            'contact_date' => $day,
            'feedback' => 'purchased',
            'notes' => "Added from Pancake POS order #{$order->pancake_order_id} (customer wasn't in the tracker).",
            'notes_updated_at' => $orderedAt,
        ]);
    }

    /**
     * The numbers (last 10 digits) among $phoneKeys that already have a lead, on any lead day.
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, true>
     */
    private function trackedPhones(array $phoneKeys): array
    {
        // Lead numbers are stored as entered: 9…, 09…, 639… or +639….
        $formats = collect($phoneKeys)->flatMap(fn (string $key) => [$key, '0'.$key, '63'.$key, '+63'.$key]);

        return $formats->chunk(1000)
            ->flatMap(fn (Collection $chunk) => Lead::whereIn('phone_number', $chunk->values()->all())->pluck('phone_number'))
            ->mapWithKeys(fn (string $phone) => [LeadGenerator::normalizePhone($phone) => true])
            ->all();
    }
}
