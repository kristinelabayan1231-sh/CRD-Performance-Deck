<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\PancakeAccounts;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * GET /api/external/v1/retention/daily: CRD's figures per real day for another department's report, counted as the
 * Daily I.S. counts them.
 *
 * - Orders: every CRA's own Pancake POS orders (their account and its aliases, current and past CRAs), and others' the
 *   CVR Tracker counts for a CRA, by the day the order was created. Only orders with a CRD product or only a TSD upsell
 *   product. Deleted orders (status 7) are left out entirely.
 * - orders: not canceled. grossSales: every order, canceled ones too (the canceled value is also cancelledSales, so a
 *   report can subtract it). Sales are Shecom's amount once synced, else Pancake's total. Status is the order's status now.
 * - deliveredSales: Delivered / Payment collected. returnedSales: Returning / Returned / Partially returned.
 * - leads: the leads on that day's Segmentation Tracker list assigned to a CRA (the paired lead day when a Working Date
 *   is set), leads added from POS sales left out. leadsContacted: those a CRA worked (a status, a date of contact or
 *   marked processed).
 * - repeatCustomerOrders: orders (not canceled) of customers with a delivered order before that day in the Customer
 *   Database, merged numbers counted together.
 * - reactivatedCustomers: not tracked yet, always null.
 */
class RetentionDaily
{
    /** Longest range one request may ask for, in days. */
    public const MAX_DAYS = 92;

    public function __construct(private CvrTracker $cvrTracker) {}

    /**
     * One entry per day $from–$to, days without orders or leads included.
     *
     * @return list<array<string, mixed>>
     */
    public function days(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cras = PancakeAccounts::craUsers();
        $names = $cras->mapWithKeys(fn (User $cra) => [$cra->id => $cra->displayName()]);
        $orders = $this->orders($cras, $from, $to);
        $leads = $this->leads($from, $to);

        $days = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $dayOrders = $orders->get($date, collect());
            $dayLeads = $leads->get($date, collect());
            $counted = $dayOrders->where('status', '!==', PancakeOrder::CANCELED);
            $boughtBefore = $this->cvrTracker->deliveredBefore($counted->pluck('phone_key')->filter()->unique()->values()->all(), $day);

            $agentIds = $dayOrders->pluck('cra')->merge($dayLeads->keys())->unique()
                ->sortBy(fn (int $id) => $names->keys()->search($id) === false ? PHP_INT_MAX : $names->keys()->search($id), SORT_NUMERIC);

            $days[] = [
                'date' => $date,
                ...$this->figures($dayOrders, $dayLeads->sum('leads'), $dayLeads->sum('contacted')),
                'repeatCustomerOrders' => $counted->filter(fn (array $order) => ($boughtBefore[$order['phone_key']] ?? 0) > 0)->count(),
                'reactivatedCustomers' => null,
                'agents' => $agentIds->map(fn (int $id) => [
                    'id' => $id,
                    'name' => $names[$id] ?? User::find($id)?->displayName(),
                    ...$this->figures($dayOrders->where('cra', $id), $dayLeads[$id]['leads'] ?? 0, $dayLeads[$id]['contacted'] ?? 0),
                ])->values()->all(),
            ];
        }

        return $days;
    }

    /**
     * Leads, orders, sales and statuses of a set of orders and leads.
     *
     * @param  Collection<int, array{cra: int, status: ?int, sales: float, phone_key: ?string}>  $orders
     * @return array<string, mixed>
     */
    private function figures(Collection $orders, int $leads, int $contacted): array
    {
        $salesOf = fn (array $statuses) => round((float) $orders->whereIn('status', $statuses)->sum('sales'), 2);
        $canceled = $orders->where('status', PancakeOrder::CANCELED);

        return [
            'leads' => $leads,
            'leadsContacted' => $contacted,
            'orders' => $orders->count() - $canceled->count(),
            'cancelledOrders' => $canceled->count(),
            'grossSales' => round((float) $orders->sum('sales'), 2),
            'cancelledSales' => round((float) $canceled->sum('sales'), 2),
            'deliveredSales' => $salesOf(ProductReport::DELIVERED),
            'returnedSales' => $salesOf(ProductReport::RTS),
            'byStatus' => $this->byStatus($orders),
        ];
    }

    /**
     * Orders and sales per status, in the order Pancake moves through them.
     *
     * @param  Collection<int, array{status: ?int, sales: float}>  $orders
     * @return list<array{status: ?int, name: string, orders: int, sales: float}>
     */
    private function byStatus(Collection $orders): array
    {
        $labels = collect(config('customers.pos_statuses'))->map(fn (array $status) => $status[0]);
        $rank = $labels->keys()->flip();

        return $orders->groupBy(fn (array $order) => $order['status'] ?? 'none')
            ->map(fn (Collection $group, int|string $status) => [
                'status' => $status === 'none' ? null : (int) $status,
                'name' => $status === 'none' ? 'No status' : ($labels[$status] ?? "Status {$status}"),
                'orders' => $group->count(),
                'sales' => round((float) $group->sum('sales'), 2),
            ])
            ->sortBy(fn (array $row) => $rank[$row['status']] ?? PHP_INT_MAX, SORT_NUMERIC)
            ->values()->all();
    }

    /**
     * The CRAs' orders $from–$to by day (Y-m-d), as PageOrders reads them for the Daily I.S., without the page.
     *
     * @param  Collection<int, User>  $cras
     * @return Collection<string, Collection<int, array{cra: int, status: ?int, sales: float, phone_key: ?string}>>
     */
    private function orders(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $accounts = PancakeAccounts::craBySeller($cras)->map(fn (User $cra) => $cra->id);
        if ($accounts->isEmpty()) {
            return collect();
        }

        $orders = [];
        PancakeOrder::crdSales()
            ->leftJoin('cvr_order_entries as cvr', 'cvr.pancake_order_id', '=', 'pancake_orders.pancake_order_id')
            ->where(fn ($q) => $q->whereNull('pancake_orders.status')->orWhere('pancake_orders.status', '!=', CraPerformance::DELETED))
            ->where('pancake_orders.ordered_on', '>=', $from->toDateString())
            ->where('pancake_orders.ordered_on', '<', $to->addDay()->toDateString())
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('cvr.user_id')->whereIn('pancake_orders.seller_name', $accounts->keys()))
                ->orWhereIn('cvr.user_id', $cras->pluck('id')))
            ->toBase()
            ->select(['pancake_orders.id', 'pancake_orders.ordered_on', 'pancake_orders.seller_name', 'pancake_orders.status',
                'pancake_orders.phone_key', 'pancake_orders.total_price', 'pancake_orders.shecom_sales', 'cvr.user_id as counted_for'])
            ->lazyById(2000, 'pancake_orders.id', 'id')
            ->each(function (object $order) use (&$orders, $accounts) {
                $orders[substr((string) $order->ordered_on, 0, 10)][] = [
                    'cra' => $order->counted_for !== null ? (int) $order->counted_for : $accounts[$order->seller_name],
                    'status' => $order->status === null ? null : (int) $order->status,
                    // PancakeOrder::sales(): Shecom's amount once synced, else Pancake's total.
                    'sales' => (float) ($order->shecom_sales ?? $order->total_price),
                    'phone_key' => $order->phone_key,
                ];
            });

        return collect($orders)->map(fn (array $day) => collect($day));
    }

    /**
     * Each real day's assigned leads and those worked, per CRA: real day (Y-m-d) => CRA id => counts.
     *
     * @return Collection<string, Collection<int, array{leads: int, contacted: int}>>
     */
    private function leads(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $lag = WorkingDate::lagDays();

        return Lead::whereNotNull('assigned_to')->withoutPosSales()
            ->where('listed_on', '>=', WorkingDate::leadDayFor($from)->toDateString())
            ->where('listed_on', '<', WorkingDate::leadDayFor($to)->addDay()->toDateString())
            ->toBase()
            ->selectRaw('date(listed_on) as lead_day, assigned_to, count(*) as leads')
            ->selectRaw('sum(case when status is not null or contact_date is not null or processed_at is not null then 1 else 0 end) as contacted')
            ->groupByRaw('date(listed_on), assigned_to')
            ->get()
            ->groupBy(fn (object $row) => CarbonImmutable::parse($row->lead_day)->addDays($lag)->toDateString())
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn (object $row) => [
                (int) $row->assigned_to => ['leads' => (int) $row->leads, 'contacted' => (int) $row->contacted],
            ]));
    }
}
