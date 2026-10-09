<?php

namespace App\Services;

use App\Models\DeliveredOrder;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;

/**
 * Churn rate = customers lost ÷ customers who ran out × 100.
 *
 * Customers who ran out = CRD-delivered customers (the logistics out-of-stock list) whose product
 * ran out between $from and $to (up to today): delivered date + qty × consumption days − 1.
 * Lost = no order since that delivery: no Pancake POS order (not canceled or deleted) placed
 * after it and no later delivery. A customer counts once, by their latest delivery that ran out
 * in the range; one who reorders later stops counting as lost.
 */
class CustomerChurn
{
    /**
     * @return array{customers: int, lost: int, rate: ?float}
     */
    public function for(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $to = $to->min(WorkingDate::realToday());
        $catalog = new ProductCatalog;
        $ranOut = [];

        // Supplies of a year or more are rare; look back far enough to catch them.
        DeliveredOrder::where('source', DeliveredOrder::SOURCE_SHECOM)
            ->whereDate('delivered_date', '>=', $from->subYear())->whereDate('delivered_date', '<=', $to)
            ->get(['phone_number', 'product_raw', 'qty', 'delivered_date', 'consumption_days_per_unit'])
            ->each(function (DeliveredOrder $order) use ($catalog, $from, $to, &$ranOut) {
                $days = $catalog->match($order->product_raw)?->consumption_days ?: $order->consumption_days_per_unit;
                $phone = LeadGenerator::normalizePhone($order->phone_number);

                if (! $days || $phone === '') {
                    return;
                }

                $outOfStock = $order->delivered_date->addDays($order->qty * $days - 1);

                if ($outOfStock->betweenIncluded($from, $to)
                    && (! isset($ranOut[$phone]) || $order->delivered_date->greaterThan($ranOut[$phone]))) {
                    $ranOut[$phone] = $order->delivered_date;
                }
            });

        if ($ranOut === []) {
            return ['customers' => 0, 'lost' => 0, 'rate' => null];
        }

        $phones = array_map('strval', array_keys($ranOut));
        $lastOrdered = [];

        foreach (array_chunk($phones, 1000) as $chunk) {
            PancakeOrder::counted()->whereIn('phone_key', $chunk)
                ->selectRaw('phone_key, max(ordered_on) as last_on')->groupBy('phone_key')->get()
                ->each(function ($row) use (&$lastOrdered) {
                    $lastOrdered[$row->phone_key] = CarbonImmutable::parse($row->last_on);
                });
            LogisticsOrder::whereIn('phone_key', $chunk)
                ->selectRaw('phone_key, max(delivered_date) as last_on')->groupBy('phone_key')->get()
                ->each(function ($row) use (&$lastOrdered) {
                    $delivered = CarbonImmutable::parse($row->last_on);
                    $known = $lastOrdered[$row->phone_key] ?? null;
                    $lastOrdered[$row->phone_key] = $known && $known->greaterThan($delivered) ? $known : $delivered;
                });
        }

        // An order placed after the delivery that ran out, or a later delivery, means they came back.
        $lost = collect($ranOut)->filter(fn (CarbonImmutable $delivered, $phone) => ! isset($lastOrdered[(string) $phone])
            || $lastOrdered[(string) $phone]->lessThanOrEqualTo($delivered))->count();

        return ['customers' => count($ranOut), 'lost' => $lost, 'rate' => $lost / count($ranOut)];
    }
}
