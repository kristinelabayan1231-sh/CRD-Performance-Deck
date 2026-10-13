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
 * Cancelled / waiting orders: the CRAs' own Pancake orders tagged CRD - BROADCAST or CRD - SEGMENTATION
 * (the same orders as Confirmed Orders) whose status is Canceled (6) or Waiting for goods (11), by the
 * day the order was created. Before the tags were in use (segmentation.conversion_tags_since), every
 * order a CRD account sold counts instead: with $everyCrdAccount, all of Settings → Pancake Accounts
 * (former CRAs too); otherwise only the CRAs' own accounts. Amounts are the sale (Shecom's, else Pancake's total).
 */
class CancelledOrders
{
    /**
     * The orders of $cras created $from–$to; $status narrows them to cancelled or waiting.
     *
     * @param  Collection<int, User>  $cras
     */
    public function query(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, ?string $status = null, bool $everyCrdAccount = false): Builder
    {
        return $this->crdOrders($cras, $from, $to, $everyCrdAccount)->whereIn('status', match ($status) {
            'cancelled' => [PancakeOrder::CANCELED],
            'waiting' => [PancakeOrder::WAITING],
            default => PancakeOrder::CANCELLED_OR_WAITING_STATUSES,
        });
    }

    /**
     * Every CRD order of $cras created $from–$to, any status (the rate's basis): tagged ones, or before the tags
     * were in use, the ones their accounts sold.
     *
     * @param  Collection<int, User>  $cras
     */
    public function crdOrders(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, bool $everyCrdAccount = false): Builder
    {
        $craAccounts = ConversionBreakdown::accounts($cras)->keys();
        $sellers = $everyCrdAccount ? $craAccounts->merge(PancakeOrder::crdAccounts())->unique()->values() : $craAccounts;
        $tagsSince = CarbonImmutable::parse(config('segmentation.conversion_tags_since'));

        return PancakeOrder::crdProducts()
            ->whereDate('ordered_on', '>=', $from)->whereDate('ordered_on', '<=', $to)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $tagged) => $tagged->whereDate('ordered_on', '>=', $tagsSince)
                    ->whereIn('seller_name', $craAccounts)
                    ->whereIn('conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION]))
                ->orWhere(fn (Builder $untagged) => $untagged->whereDate('ordered_on', '<', $tagsSince)->whereIn('seller_name', $sellers)));
    }

    /**
     * The CRD orders of $cras (by the same rule, created any day) that left Canceled or Waiting for goods,
     * noticed $from–$to (Manila days), most recent first, with their order.
     *
     * @param  Collection<int, User>  $cras
     * @return Collection<int, OrderStatusChange>
     */
    public function changes(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, bool $everyCrdAccount = false): Collection
    {
        $timezone = config('segmentation.timezone');
        $orders = $this->crdOrders($cras, CarbonImmutable::create(2000), WorkingDate::realToday(), $everyCrdAccount)->select('pancake_order_id');

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
    public function totals(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, bool $everyCrdAccount = false): array
    {
        $totals = self::sum($this->query($cras, $from, $to, everyCrdAccount: $everyCrdAccount)->get(['status', 'total_price', 'shecom_sales']));
        $orders = $this->crdOrders($cras, $from, $to, $everyCrdAccount)->count();

        return [...$totals, 'orders' => $orders, 'rate' => $orders ? $totals['total'] / $orders : null];
    }

    /**
     * Totals per day, most recent first.
     *
     * @param  Collection<int, User>  $cras
     * @return Collection<string, array{cancelled: int, waiting: int, total: int, amount: float}>
     */
    public function perDay(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, ?string $status = null, bool $everyCrdAccount = false): Collection
    {
        return $this->query($cras, $from, $to, $status, $everyCrdAccount)->get(['ordered_on', 'status', 'total_price', 'shecom_sales'])
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
