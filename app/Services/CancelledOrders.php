<?php

namespace App\Services;

use App\Models\OrderStatusChange;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cancelled / waiting orders: the CRAs' orders as the dashboard counts them (ConversionBreakdown::taggedOrders():
 * tagged CRD - BROADCAST / SEGMENTATION, a listed product, with the CVR Tracker's corrections) whose status is
 * Canceled (6) or Waiting for goods (11), by the day the order was created. The rate is out of those same orders,
 * deleted ones left out (confirmed + canceled), so it lines up with Total confirmed orders. Amounts are the sale
 * (Shecom's, else Pancake's total).
 */
class CancelledOrders
{
    /**
     * The orders of $cras created $from–$to; $status narrows them to cancelled or waiting.
     *
     * @param  Collection<int, User>  $cras
     */
    public function query(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, ?string $status = null): Builder
    {
        return $this->crdOrders($cras, $from, $to)->whereIn('pancake_orders.status', match ($status) {
            'cancelled' => [PancakeOrder::CANCELED],
            'waiting' => [PancakeOrder::WAITING],
            default => PancakeOrder::CANCELLED_OR_WAITING_STATUSES,
        });
    }

    /**
     * Every order of $cras created $from–$to as the dashboard counts them, deleted ones left out (the rate's basis:
     * confirmed + canceled).
     *
     * @param  Collection<int, User>  $cras
     */
    public function crdOrders(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return ConversionBreakdown::taggedOrders(ConversionBreakdown::accounts($cras), $from, $to)
            ->where(fn (Builder $q) => $q->whereNull('pancake_orders.status')->orWhere('pancake_orders.status', '!=', CraPerformance::DELETED));
    }

    /**
     * The CRD orders of $cras (by the same rule, created any day) that left Canceled or Waiting for goods,
     * noticed $from–$to (Manila days), most recent first, with their order.
     *
     * @param  Collection<int, User>  $cras
     * @return Collection<int, OrderStatusChange>
     */
    public function changes(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $timezone = config('segmentation.timezone');
        $orders = $this->crdOrders($cras, CarbonImmutable::create(2000), WorkingDate::realToday())->select('pancake_orders.pancake_order_id');

        return OrderStatusChange::with('order')
            ->whereIn('pancake_order_id', $orders)
            ->whereBetween('created_at', [
                CarbonImmutable::parse($from->toDateString(), $timezone)->utc(),
                CarbonImmutable::parse($to->toDateString(), $timezone)->endOfDay()->utc(),
            ])
            ->latest()->orderByDesc('id')
            ->get();
    }

    /**
     * Totals for the dashboard tile; rate = cancelled + waiting ÷ all their CRD orders in these dates (null with none).
     *
     * @param  Collection<int, User>  $cras
     * @return array{cancelled: int, waiting: int, total: int, amount: float, orders: int, rate: ?float}
     */
    public function totals(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = self::sum($this->query($cras, $from, $to)->get());
        $orders = $this->crdOrders($cras, $from, $to)->count();

        return [...$totals, 'orders' => $orders, 'rate' => $orders ? $totals['total'] / $orders : null];
    }

    /**
     * Totals per day, most recent first.
     *
     * @param  Collection<int, User>  $cras
     * @return Collection<string, array{cancelled: int, waiting: int, total: int, amount: float}>
     */
    public function perDay(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, ?string $status = null): Collection
    {
        return $this->query($cras, $from, $to, $status)->get()
            ->groupBy(fn (PancakeOrder $order) => $order->ordered_on->toDateString())
            ->sortKeysDesc()
            ->map(fn (Collection $orders) => self::sum($orders));
    }

    /**
     * @param  Collection<int, PancakeOrder>  $orders
     * @return array{cancelled: int, waiting: int, total: int, amount: float}
     */
    private static function sum(Collection $orders): array
    {
        $cancelled = $orders->where('status', PancakeOrder::CANCELED)->count();

        return [
            'cancelled' => $cancelled,
            'waiting' => $orders->count() - $cancelled,
            'total' => $orders->count(),
            'amount' => (float) $orders->sum(fn (PancakeOrder $order) => $order->sales()),
        ];
    }
}
