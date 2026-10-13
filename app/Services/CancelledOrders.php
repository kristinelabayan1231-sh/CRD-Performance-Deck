<?php

namespace App\Services;

use App\Models\PancakeOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cancelled / waiting orders: the CRAs' own Pancake orders tagged CRD - BROADCAST or CRD - SEGMENTATION
 * (the same orders as Confirmed Orders) whose status is Canceled (6) or Waiting for goods (11), by the
 * day the order was created. Amounts are the CRA's sale (Shecom's, else Pancake's total).
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
        return $this->crdOrders($cras, $from, $to)->whereIn('status', match ($status) {
            'cancelled' => [PancakeOrder::CANCELED],
            'waiting' => [PancakeOrder::WAITING],
            default => PancakeOrder::CANCELLED_OR_WAITING_STATUSES,
        });
    }

    /**
     * Every order of $cras tagged CRD - BROADCAST or CRD - SEGMENTATION created $from–$to, any status: the rate's basis.
     *
     * @param  Collection<int, User>  $cras
     */
    public function crdOrders(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return PancakeOrder::crdProducts()
            ->whereIn('seller_name', ConversionBreakdown::accounts($cras)->keys())
            ->whereIn('conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION])
            ->whereDate('ordered_on', '>=', $from)->whereDate('ordered_on', '<=', $to);
    }

    /**
     * Totals for the dashboard tile; rate = cancelled + waiting ÷ all their CRD orders in these dates (null with none).
     *
     * @param  Collection<int, User>  $cras
     * @return array{cancelled: int, waiting: int, total: int, amount: float, orders: int, rate: ?float}
     */
    public function totals(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = self::sum($this->query($cras, $from, $to)->get(['status', 'total_price', 'shecom_sales']));
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
        return $this->query($cras, $from, $to, $status)->get(['ordered_on', 'status', 'total_price', 'shecom_sales'])
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
