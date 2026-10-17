<?php

namespace App\Services;

use App\Models\CvrOrderEntry;
use App\Models\Lead;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A CRA's Pancake POS sale shows in the Segmentation Tracker as a repeat purchase on the order day, so the
 * dashboard's Retained and Repeat tiles count it:
 *
 * - Customer already in the tracker: their latest lead (by lead day, up to the order day) gets Repeat
 *   Purchase = Yes and moves to Catered, unless it is Yes already.
 * - Customer not in the tracker: a catered lead for that CRA on the lead day paired with the order day (Settings →
 *   Working Date), with Repeat Purchase = Yes on the order day; CRD Lead when they had an earlier CRA-handled order,
 *   else FSD Lead. On the paired lead day it shows in the tracker the CRAs are working and counts in the dashboard's leads.
 *
 * Only orders sold from a CRA's own Pancake account (User Access), with a product from the Product
 * Consumption list, not canceled or deleted. An order the CVR Tracker counts for another CRA (also one made under an
 * account not listed, e.g. AJ DELA CRUZ) is that CRA's sale.
 */
class PosLeads
{
    public function __construct(private CustomerDatabase $customers) {}

    /**
     * Record the day's CRA sales in the tracker.
     *
     * @return array{added: int, marked: int}
     */
    public function record(CarbonImmutable $day): array
    {
        /** @var Collection<string, User> $cras */
        $cras = LeadGenerator::cras()->filter(fn (User $cra) => $cra->pancake_name)
            ->keyBy(fn (User $cra) => PancakeEngagement::staffKey($cra->pancake_name));

        if ($cras->isEmpty()) {
            return ['added' => 0, 'marked' => 0];
        }

        // The CVR Tracker's CRA for an order made under another account, or moved to another CRA.
        $byId = $cras->keyBy('id');
        $claimed = CvrOrderEntry::whereNotNull('user_id')->pluck('user_id', 'pancake_order_id');

        $orders = PancakeOrder::whereDate('ordered_on', $day)
            ->where(fn ($q) => $q->whereIn('seller_name', $cras->keys())->orWhereIn('pancake_order_id', $claimed->keys()))
            ->crdProducts()->counted()->whereNotNull('phone_key')
            ->orderBy('ordered_at')->orderBy('id')
            ->get();
        $craFor = fn (PancakeOrder $order): ?User => isset($claimed[$order->pancake_order_id])
            ? $byId->get($claimed[$order->pancake_order_id])
            : $cras->get($order->seller_name);
        $orders = $orders->filter(fn (PancakeOrder $order) => $craFor($order) !== null)->unique('phone_key');

        $tracked = $this->trackedLeads($orders->pluck('phone_key')->all());
        $catalog = new ProductCatalog;
        $result = ['added' => 0, 'marked' => 0];

        foreach ($orders as $order) {
            $leads = $tracked[$order->phone_key] ?? null;

            if ($leads === null) {
                $this->addLead($order, $craFor($order), $catalog);
                $result['added']++;
            } elseif ($this->markBought($leads, $order)) {
                $result['marked']++;
            }
        }

        return $result;
    }

    /**
     * Repeat Purchase = Yes on the order day for the customer's latest lead up to that day (else their
     * earliest later one); false when that lead is Yes already.
     *
     * @param  Collection<int, Lead>  $leads
     */
    private function markBought(Collection $leads, PancakeOrder $order): bool
    {
        $day = $order->ordered_on;
        $lead = $leads->filter(fn (Lead $lead) => $lead->est_out_of_stock_date->lessThanOrEqualTo($day))->sortByDesc('est_out_of_stock_date')->first()
            ?? $leads->sortBy('est_out_of_stock_date')->first();

        if ($lead->repeat_purchase === 'yes') {
            return false;
        }

        $lead->forceFill([
            'repeat_purchase' => 'yes',
            'repeat_purchase_on' => $day->toDateString(),
            // Off the Pending list: the customer bought.
            ...($lead->isProcessed() ? [] : ['processed_at' => now()]),
        ])->save();

        return true;
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
            // Not delivered yet: the order day stands in. Its lead day is the one the CRAs were working that day.
            'delivered_date' => $day,
            'consumption_days' => (int) $product?->consumption_days,
            'est_out_of_stock_date' => WorkingDate::leadDayFor($order->ordered_on)->toDateString(),
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
     * The leads of the numbers (last 10 digits) among $phoneKeys that are in the tracker, on any lead day.
     *
     * @param  list<string>  $phoneKeys
     * @return Collection<string, Collection<int, Lead>>
     */
    private function trackedLeads(array $phoneKeys): Collection
    {
        // Lead numbers are stored as entered: 9…, 09…, 639… or +639….
        $formats = collect($phoneKeys)->flatMap(fn (string $key) => [$key, '0'.$key, '63'.$key, '+63'.$key]);

        return $formats->chunk(1000)
            ->flatMap(fn (Collection $chunk) => Lead::whereIn('phone_number', $chunk->values()->all())->get())
            ->groupBy(fn (Lead $lead) => LeadGenerator::normalizePhone($lead->phone_number));
    }
}
