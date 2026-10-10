<?php

namespace App\Services;

use App\Models\DeliveredOrder;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;

/**
 * Churn rate = customers lost ÷ customers due × 100.
 *
 * A CRD-delivered customer (the logistics out-of-stock list) runs out on delivered date + qty ×
 * consumption days − 1 and has config customers.churn_grace_days (30) to order again. Customers
 * due in $from–$to = those whose grace ended in the range (up to today). Lost = no Pancake POS
 * order (not canceled or deleted) and no new delivery between that delivery and the end of the
 * grace. A customer counts once, by their latest delivery whose grace ended in the range, so a
 * past range's churn doesn't change later.
 */
class CustomerChurn
{
    /**
     * @return array{customers: int, lost: int, rate: ?float, grace_days: int}
     */
    public function for(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $grace = (int) config('customers.churn_grace_days');
        $to = $to->min(WorkingDate::realToday());
        $catalog = new ProductCatalog;
        $due = [];

        // Supplies of a year or more are rare; look back far enough to catch them.
        DeliveredOrder::where('source', DeliveredOrder::SOURCE_SHECOM)
            ->whereDate('delivered_date', '>=', $from->subYear()->subDays($grace))->whereDate('delivered_date', '<=', $to)
            ->get(['phone_number', 'product_raw', 'qty', 'delivered_date', 'consumption_days_per_unit'])
            ->each(function (DeliveredOrder $order) use ($catalog, $from, $to, $grace, &$due) {
                $days = $catalog->match($order->product_raw)?->consumption_days ?: $order->consumption_days_per_unit;
                $phone = LeadGenerator::normalizePhone($order->phone_number);

                if (! $days || $phone === '') {
                    return;
                }

                $deadline = $order->delivered_date->addDays($order->qty * $days - 1 + $grace);

                if ($deadline->betweenIncluded($from, $to)
                    && (! isset($due[$phone]) || $order->delivered_date->greaterThan($due[$phone]['delivered']))) {
                    $due[$phone] = ['delivered' => $order->delivered_date, 'deadline' => $deadline];
                }
            });

        if ($due === []) {
            return ['customers' => 0, 'lost' => 0, 'rate' => null, 'grace_days' => $grace];
        }

        $since = collect($due)->min(fn (array $d) => $d['delivered'])->toDateString();
        $orders = [];

        // Every later order or delivery of these customers, to see whether one fell inside their window.
        foreach (array_chunk(array_map('strval', array_keys($due)), 1000) as $chunk) {
            PancakeOrder::counted()->whereIn('phone_key', $chunk)->whereDate('ordered_on', '>', $since)
                ->get(['phone_key', 'ordered_on'])
                ->each(function (PancakeOrder $order) use (&$orders) {
                    $orders[$order->phone_key][] = $order->ordered_on;
                });
            LogisticsOrder::whereIn('phone_key', $chunk)->whereDate('delivered_date', '>', $since)
                ->get(['phone_key', 'delivered_date'])
                ->each(function (LogisticsOrder $order) use (&$orders) {
                    $orders[$order->phone_key][] = $order->delivered_date;
                });
        }

        $lost = collect($due)->reject(fn (array $d, $phone) => collect($orders[(string) $phone] ?? [])
            ->contains(fn (CarbonImmutable $day) => $day->greaterThan($d['delivered']) && $day->lessThanOrEqualTo($d['deadline'])))->count();

        return ['customers' => count($due), 'lost' => $lost, 'rate' => $lost / count($due), 'grace_days' => $grace];
    }
}
