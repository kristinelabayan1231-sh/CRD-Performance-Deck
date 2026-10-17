<?php

namespace App\Services;

use App\Models\CustomerLink;
use App\Models\CvrDayEntry;
use App\Models\CvrOrderEntry;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * CVR Tracker: one CRA's reorders for one day, laid out like the CRAs' Google Sheet.
 *
 * Orders = the CRA's own Pancake orders that day tagged CRD - BROADCAST or CRD - SEGMENTATION, not canceled or deleted,
 * by when they were created. Untagged ones are left out (they're in the order issues).
 * Assigned = Type of Order is Segmentation, or the order's number matches a lead assigned to any CRA in the Segmentation
 * Tracker (leads added from POS sales left out, since they come from the order itself); otherwise Pancake.
 * Total # of Orders = the customer's delivered orders before that day (Customer Database, merged numbers too) + this one.
 * Engaged / Reserved / Not answering = the CRA's Segmentation Tracker leads on that day's list (the paired lead day when
 * a working date is set): a Time of Contact and feedback other than PURCHASED / Repeat Purchase = Reserve /
 * status PJR/Inactive/CBR/Drop call.
 */
class CvrTracker
{
    /**
     * @return array{orders: list<array<string, mixed>>, summary: array<string, int|float|null>, sheet_missing: list<array{customer: string, amount: ?float}>, issues: Collection<int, array<string, mixed>>}
     */
    public function day(User $cra, CarbonImmutable $day): array
    {
        $orders = $this->orders($cra, $day);
        $entries = CvrOrderEntry::whereIn('pancake_order_id', $orders->pluck('pancake_order_id'))->get()->keyBy('pancake_order_id');
        // The sheet's order first, then the rest by time.
        $orders = $orders->sortBy(fn (PancakeOrder $order) => [$entries->get($order->pancake_order_id)?->position ?? PHP_INT_MAX, $order->ordered_at?->getTimestamp() ?? 0, $order->id]);
        $assigned = $this->assignedNumbers($orders->pluck('phone_key')->filter()->unique()->values()->all());
        $earlier = $this->deliveredBefore($orders->pluck('phone_key')->filter()->unique()->values()->all(), $day);

        $rows = $orders->map(function (PancakeOrder $order) use ($entries, $assigned, $earlier) {
            $entry = $entries[$order->pancake_order_id] ?? null;
            $type = $entry?->order_type ?? $order->conversion_type;
            $matched = $entry?->lead_source !== null
                ? $entry->lead_source === CvrOrderEntry::ASSIGNED
                : $order->phone_key !== null && isset($assigned[$order->phone_key]);

            return [
                'id' => $order->pancake_order_id,
                'total_orders' => $order->phone_key ? ($earlier[$order->phone_key] ?? 0) + 1 : null,
                'phone' => $order->phone_number,
                'page' => $order->page_name,
                'customer' => $order->customer_name,
                'amount' => $order->sales(),
                // A Segmentation order is always an assigned lead; else the sheet's pick or a matching tracker lead.
                'assigned' => $type === PancakeOrder::SEGMENTATION || $matched,
                'matched' => $matched,
                'seller' => $order->seller_name,
                'upsell' => $entry?->upsell,
                'tagged_type' => $order->conversion_type,
                'type' => $type,
                'point_of_contact' => $entry?->point_of_contact,
                'time' => $order->ordered_at?->copy()->setTimezone(config('segmentation.timezone')),
            ];
        })->values()->all();

        return [
            'orders' => $rows,
            'summary' => $this->summary($cra, $day, collect($rows)),
            'sheet_missing' => CvrDayEntry::where('user_id', $cra->id)->whereDate('date', $day)->value('sheet_missing') ?? [],
            'issues' => app(CraIssues::class)->for(collect([$cra]), $day)
                ->filter(fn (array $issue) => $issue['day']?->isSameDay($day))->values(),
        ];
    }

    /**
     * Each CRA's number of orders on $day, for the CRA tabs.
     *
     * @param  Collection<int, User>  $cras
     * @return Collection<int, int> cra id => orders
     */
    public function orderCounts(Collection $cras, CarbonImmutable $day): Collection
    {
        return $cras->mapWithKeys(fn (User $cra) => [$cra->id => $this->ordersQuery($cra, $day)->count()]);
    }

    /**
     * @return Collection<int, PancakeOrder>
     */
    private function orders(User $cra, CarbonImmutable $day): Collection
    {
        return $this->ordersQuery($cra, $day)
            ->get(['id', 'pancake_order_id', 'ordered_at', 'seller_name', 'customer_name', 'phone_number', 'phone_key', 'page_name', 'conversion_type', 'total_price', 'shecom_sales']);
    }

    /**
     * The CRA's tagged orders on $day: their own (unless counted for another CRA) and others' counted for them.
     */
    private function ordersQuery(User $cra, CarbonImmutable $day): Builder
    {
        $seller = $cra->pancake_name ? PancakeEngagement::staffKey($cra->pancake_name) : null;
        $claimed = CvrOrderEntry::whereNotNull('user_id')->select('pancake_order_id');

        return $this->tagged($day)->where(fn (Builder $q) => $q
            ->when($seller, fn (Builder $q) => $q->where('seller_name', $seller)->whereNotIn('pancake_order_id', (clone $claimed)->where('user_id', '!=', $cra->id)))
            ->orWhereIn('pancake_order_id', (clone $claimed)->where('user_id', $cra->id)));
    }

    private function tagged(CarbonImmutable $day): Builder
    {
        return PancakeOrder::crdProducts()->counted()
            ->whereIn('conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION])
            ->whereDate('ordered_on', $day);
    }

    /**
     * The numbers among $phoneKeys that belong to a lead assigned to a CRA in the Segmentation Tracker.
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, true>
     */
    private function assignedNumbers(array $phoneKeys): array
    {
        if ($phoneKeys === []) {
            return [];
        }

        // Lead numbers are stored as entered: 9…, 09…, 639… or +639….
        $formats = collect($phoneKeys)->flatMap(fn (string $key) => [$key, '0'.$key, '63'.$key, '+63'.$key])->all();

        return Lead::whereNotNull('assigned_to')->withoutPosSales()->whereIn('phone_number', $formats)->pluck('phone_number')
            ->mapWithKeys(fn (string $phone) => [LeadGenerator::normalizePhone($phone) => true])->all();
    }

    /**
     * Each number's customer's delivered orders before $day, every number of a merged customer counted together.
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, int>
     */
    private function deliveredBefore(array $phoneKeys, CarbonImmutable $day): array
    {
        if ($phoneKeys === []) {
            return [];
        }

        $primaryOf = collect($phoneKeys)->mapWithKeys(fn (string $key) => [$key => $key])
            ->replace(CustomerLink::whereIn('phone_key', $phoneKeys)->pluck('primary_phone_key', 'phone_key'));
        $members = CustomerLink::whereIn('primary_phone_key', $primaryOf->values()->unique())->pluck('primary_phone_key', 'phone_key');
        // Every number => its customer's main number (replace(), not merge(): numbers are numeric keys).
        $customerOf = $primaryOf->values()->unique()->mapWithKeys(fn ($key) => [$key => (string) $key])->replace($members);

        $delivered = LogisticsOrder::covered()->where('non_crd', false)
            ->whereIn('phone_key', $customerOf->keys()->map(fn ($key) => (string) $key)->all())->where('delivered_date', '<', $day->toDateString())
            ->pluck('phone_key')->countBy(fn (string $key) => (string) $customerOf[$key]);

        return $primaryOf->map(fn ($primary) => (int) ($delivered[(string) $primary] ?? 0))->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|float|null>
     */
    private function summary(User $cra, CarbonImmutable $day, Collection $rows): array
    {
        $ofType = fn (string $type) => $rows->where('type', $type);
        $gross = (float) $rows->sum('amount');
        $leads = Lead::where('assigned_to', $cra->id)->withoutPosSales()->whereDate('est_out_of_stock_date', WorkingDate::leadDayFor($day));

        $status = [
            'engaged' => (clone $leads)->whereNotNull('contact_time')
                ->where(fn ($q) => $q->whereNull('feedback')->orWhere('feedback', '!=', 'purchased'))->count(),
            'reserved' => (clone $leads)->where('repeat_purchase', 'reserve')->count(),
            'not_answering' => (clone $leads)->where('status', 'pjr_drop_call')->count(),
        ];
        $unconfirmed = CvrDayEntry::where('user_id', $cra->id)->whereDate('date', $day)->value('unconfirmed');
        // # of engagements will come from the Daily I.S.; until then CVR can't be worked out.
        $engagements = null;

        return [
            'gross' => $gross,
            'bc_orders' => $ofType(PancakeOrder::BROADCAST)->count(),
            'bc_amount' => (float) $ofType(PancakeOrder::BROADCAST)->sum('amount'),
            'sc_orders' => $ofType(PancakeOrder::SEGMENTATION)->count(),
            'sc_amount' => (float) $ofType(PancakeOrder::SEGMENTATION)->sum('amount'),
            'orders' => $rows->count(),
            'aov' => $rows->isEmpty() ? null : $gross / $rows->count(),
            'engagements' => $engagements,
            'cvr' => $engagements ? $ofType(PancakeOrder::SEGMENTATION)->count() / $engagements : null,
            'contacted' => $rows->whereNotNull('point_of_contact')->count(),
            'unconfirmed' => $unconfirmed,
            ...$status,
            'status_total' => (int) $unconfirmed + array_sum($status),
            'pancake_leads' => $rows->where('assigned', false)->count(),
            'assigned_leads' => $rows->where('assigned', true)->count(),
        ];
    }
}
