<?php

namespace App\Services;

use App\Models\DeliveredOrder;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;

/**
 * Churn rate = customers lost ÷ customers due × 100, for CRD, FSD and both together.
 *
 * A delivered customer runs out on delivered date + qty × consumption days − 1 and has config
 * customers.churn_grace_days (30) to order again. CRD = the CRD-delivered orders (the logistics
 * out-of-stock list). FSD = the FSD-delivered logistics orders, with qty from the Pancake delivered
 * orders (an order without a known qty is left out until it has one) and consumption days from
 * Settings → Product Consumption. Customers due in $from–$to = those whose grace ended in the range
 * (up to today). Lost = no Pancake POS order (not canceled or deleted) and no new delivery between
 * that delivery and the end of the grace. A customer counts once per list, by their latest delivery
 * whose grace ended in the range, so a past range's churn doesn't change later; overall takes each
 * customer's latest delivery across both lists. delivered_months counts the customers by the month
 * they were delivered (Y-m), usually months before the range.
 */
class CustomerChurn
{
    /**
     * @return array{crd: array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}, fsd: array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}, all: array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}, grace_days: int}
     */
    public function for(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $grace = (int) config('customers.churn_grace_days');
        $to = $to->min(WorkingDate::realToday());
        $catalog = new ProductCatalog;
        // Supplies of a year or more are rare; look back far enough to catch them.
        $since = $from->subYear()->subDays($grace);
        $due = ['crd' => [], 'fsd' => []];

        // Plain rows and Y-m-d strings: the FSD list runs to tens of thousands of deliveries.
        $consider = function (string $team, string $phone, string $delivered, int $qty, ?int $days) use ($from, $to, $grace, &$due) {
            if (! $days || $phone === '') {
                return;
            }

            $deadline = CarbonImmutable::parse($delivered)->addDays($qty * $days - 1 + $grace);

            if ($deadline->betweenIncluded($from, $to) && (! isset($due[$team][$phone]) || $delivered > $due[$team][$phone]['delivered'])) {
                $due[$team][$phone] = ['delivered' => $delivered, 'deadline' => $deadline->toDateString()];
            }
        };

        DeliveredOrder::where('source', DeliveredOrder::SOURCE_SHECOM)
            ->whereDate('delivered_date', '>=', $since)->whereDate('delivered_date', '<=', $to)
            ->toBase()->get(['phone_number', 'product_raw', 'qty', 'delivered_date', 'consumption_days_per_unit'])
            ->each(fn (object $order) => $consider(
                'crd',
                LeadGenerator::normalizePhone((string) $order->phone_number),
                substr((string) $order->delivered_date, 0, 10),
                max(1, (int) $order->qty),
                $catalog->match((string) $order->product_raw)?->consumption_days ?: $order->consumption_days_per_unit,
            ));

        // FSD in batches, each with its qty from the Pancake delivered orders.
        $days = [];
        LogisticsOrder::where('team', LogisticsOrder::TEAM_FSD)
            ->whereDate('delivered_date', '>=', $since)->whereDate('delivered_date', '<=', $to)
            ->toBase()->select(['id', 'order_id', 'phone_key', 'product', 'delivered_date'])
            ->chunkById(5000, function ($orders) use ($consider, $catalog, &$days) {
                $qty = DeliveredOrder::where('source', DeliveredOrder::SOURCE_PANCAKE)
                    ->whereIn('order_id', $orders->pluck('order_id')->map(fn ($id) => (string) $id)->all())
                    ->toBase()->pluck('qty', 'order_id')->all();

                foreach ($orders as $order) {
                    if (! isset($qty[$order->order_id])) {
                        continue;
                    }

                    if (! array_key_exists($order->product, $days)) {
                        $days[$order->product] = $catalog->match((string) $order->product)?->consumption_days;
                    }

                    $consider('fsd', (string) $order->phone_key, substr((string) $order->delivered_date, 0, 10), max(1, (int) $qty[$order->order_id]), $days[$order->product]);
                }
            });

        // Overall: each customer's latest delivery across both lists.
        $due['all'] = $due['crd'];
        foreach ($due['fsd'] as $phone => $d) {
            if (! isset($due['all'][$phone]) || $d['delivered'] > $due['all'][$phone]['delivered']) {
                $due['all'][$phone] = $d;
            }
        }

        $orders = $this->ordersSince($due['all']);

        return [
            'crd' => $this->summary($due['crd'], $orders),
            'fsd' => $this->summary($due['fsd'], $orders),
            'all' => $this->summary($due['all'], $orders),
            'grace_days' => $grace,
        ];
    }

    /**
     * Every later order or delivery of these customers, by phone, to see whether one fell inside their window.
     *
     * @param  array<string, array{delivered: string, deadline: string}>  $due
     * @return array<string, list<string>>
     */
    private function ordersSince(array $due): array
    {
        if ($due === []) {
            return [];
        }

        $since = min(array_column($due, 'delivered'));
        $orders = [];

        foreach (array_chunk(array_map('strval', array_keys($due)), 1000) as $chunk) {
            PancakeOrder::counted()->whereIn('phone_key', $chunk)->whereDate('ordered_on', '>', $since)
                ->toBase()->get(['phone_key', 'ordered_on'])
                ->each(function (object $order) use (&$orders) {
                    $orders[$order->phone_key][] = substr((string) $order->ordered_on, 0, 10);
                });
            LogisticsOrder::whereIn('phone_key', $chunk)->whereDate('delivered_date', '>', $since)
                ->toBase()->get(['phone_key', 'delivered_date'])
                ->each(function (object $order) use (&$orders) {
                    $orders[$order->phone_key][] = substr((string) $order->delivered_date, 0, 10);
                });
        }

        return $orders;
    }

    /**
     * @param  array<string, array{delivered: string, deadline: string}>  $due
     * @param  array<string, list<string>>  $orders
     * @return array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}
     */
    private function summary(array $due, array $orders): array
    {
        $lost = collect($due)->reject(fn (array $d, $phone) => collect($orders[(string) $phone] ?? [])
            ->contains(fn (string $day) => $day > $d['delivered'] && $day <= $d['deadline']))->count();

        return [
            'customers' => count($due),
            'lost' => $lost,
            'rate' => $due === [] ? null : $lost / count($due),
            'delivered_months' => collect($due)->countBy(fn (array $d) => substr($d['delivered'], 0, 7))->sortKeys()->all(),
        ];
    }
}
