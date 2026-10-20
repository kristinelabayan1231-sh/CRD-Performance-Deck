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
 * they were delivered (Y-m), usually months before the range. customers() lists them one by one.
 */
class CustomerChurn
{
    /**
     * @return array{crd: array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}, fsd: array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}, all: array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}, grace_days: int}
     */
    public function for(CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$due, $outcomes] = $this->evaluate($from, $to);

        return [
            'crd' => $this->summary($due['crd'], $outcomes['crd']),
            'fsd' => $this->summary($due['fsd'], $outcomes['fsd']),
            'all' => $this->summary($due['all'], $outcomes['all']),
            'grace_days' => (int) config('customers.churn_grace_days'),
        ];
    }

    /**
     * for(), kept as the Customer Database's results are (a few minutes, or until customers:warm-cache refreshes it).
     *
     * @return array{crd: array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}, fsd: array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}, all: array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}, grace_days: int}
     */
    public function cached(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return CustomerDatabase::remember(CustomerDatabase::cacheKey('churn', [$from->toDateString(), $to->toDateString()]), fn () => $this->for($from, $to));
    }

    /**
     * The customers behind for(): one compact row per customer of the list ('all', 'crd' or 'fsd'), soonest deadline
     * first (then by number), as [phone_key, team, product, order_id, delivered, ran_out, deadline, back order id,
     * back date, back source, last order id, last date, last source]; row() turns one into named fields. Compact because a long range has tens of thousands of customers and a page
     * shows fifty. back = the first order or delivery inside their window (null when lost); last_order = their latest
     * order or delivery since the one judged (that one when nothing came after).
     *
     * @return list<list<?string>>
     */
    public function customers(CarbonImmutable $from, CarbonImmutable $to, string $list = 'all'): array
    {
        [$due, $outcomes] = $this->evaluate($from, $to, $list);

        $rows = [];
        foreach ($due[$list] ?? [] as $phone => $d) {
            $back = $outcomes[$list][(string) $phone]['back'] ?? null;
            $last = $outcomes[$list][(string) $phone]['last'] ?? ['order_id' => $d['order_id'], 'date' => $d['delivered'], 'source' => 'delivery'];
            $rows[] = [(string) $phone, $d['team'], $d['product'], $d['order_id'], $d['delivered'], $d['ran_out'], $d['deadline'],
                $back['order_id'] ?? null, $back['date'] ?? null, $back['source'] ?? null, $last['order_id'], $last['date'], $last['source']];
        }
        unset($due, $outcomes);

        usort($rows, fn (array $a, array $b) => [$a[6], $a[0]] <=> [$b[6], $b[0]]);

        return $rows;
    }

    /**
     * One of customers()' compact rows with named fields.
     *
     * @param  list<?string>  $row
     * @return array{phone_key: string, team: string, product: string, order_id: string, delivered: string, ran_out: string, deadline: string, back: ?array{order_id: string, date: string, source: string}, last_order: array{order_id: string, date: string, source: string}}
     */
    public static function row(array $row): array
    {
        return [
            'phone_key' => $row[0], 'team' => $row[1], 'product' => $row[2], 'order_id' => $row[3], 'delivered' => $row[4],
            'ran_out' => $row[5], 'deadline' => $row[6],
            'back' => $row[7] === null ? null : ['order_id' => $row[7], 'date' => $row[8], 'source' => $row[9]],
            'last_order' => ['order_id' => $row[10], 'date' => $row[11], 'source' => $row[12]],
        ];
    }

    /**
     * Who is due in $from–$to per list, and per list and customer whether they came back in time (see outcomes();
     * with $detailsFor, the orders themselves for that one list).
     *
     * @return array{0: array{crd: array<string, array<string, string>>, fsd: array<string, array<string, string>>, all: array<string, array<string, string>>}, 1: array<string, array<string, mixed>>}
     */
    private function evaluate(CarbonImmutable $from, CarbonImmutable $to, ?string $detailsFor = null): array
    {
        $grace = (int) config('customers.churn_grace_days');
        $to = $to->min(WorkingDate::realToday());
        $catalog = new ProductCatalog;
        // Supplies of a year or more are rare; look back far enough to catch them.
        $since = $from->subYear()->subDays($grace);
        $due = ['crd' => [], 'fsd' => []];

        // Plain rows, Y-m-d strings and day numbers (days since 1970, UTC; worked out once per date): the FSD list
        // runs to tens of thousands of deliveries.
        $dayNumbers = [];
        $dayNumber = function (string $date) use (&$dayNumbers): int {
            return $dayNumbers[$date] ??= intdiv(strtotime($date.' 00:00:00 UTC'), 86400);
        };
        // One copy of each date and product name, shared by every customer that has it (tens of thousands of rows).
        $dates = [];
        $date = function (int $day) use (&$dates): string {
            return $dates[$day] ??= gmdate('Y-m-d', $day * 86400);
        };
        $names = [];
        $fromDay = $dayNumber($from->toDateString());
        $toDay = $dayNumber($to->toDateString());
        $consider = function (string $team, string $phone, string $delivered, int $qty, ?int $days, string $orderId, string $product) use ($fromDay, $toDay, $grace, $dayNumber, $date, &$names, &$due) {
            if (! $days || $phone === '') {
                return;
            }

            $ranOut = $dayNumber($delivered) + $qty * $days - 1;
            $deadline = $ranOut + $grace;

            if ($deadline >= $fromDay && $deadline <= $toDay && (! isset($due[$team][$phone]) || $delivered > $due[$team][$phone]['delivered'])) {
                $due[$team][$phone] = [
                    'team' => $team, 'product' => $names[$product] ??= $product, 'order_id' => $orderId, 'delivered' => $date($dayNumber($delivered)),
                    'ran_out' => $date($ranOut), 'deadline' => $date($deadline),
                ];
            }
        };

        DeliveredOrder::where('source', DeliveredOrder::SOURCE_SHECOM)
            ->where('delivered_date', '>=', $since->toDateString())->where('delivered_date', '<', $to->addDay()->toDateString())
            ->toBase()->get(['order_id', 'phone_number', 'product_raw', 'qty', 'delivered_date', 'consumption_days_per_unit'])
            ->each(function (object $order) use ($consider, $catalog) {
                // Only products on the Product Consumption list count.
                if ($catalog->unlistedText((string) $order->product_raw)) {
                    return;
                }

                $product = $catalog->match((string) $order->product_raw);
                $consider(
                    'crd',
                    LeadGenerator::normalizePhone((string) $order->phone_number),
                    substr((string) $order->delivered_date, 0, 10),
                    max(1, (int) $order->qty),
                    $product?->consumption_days ?: $order->consumption_days_per_unit,
                    (string) $order->order_id,
                    $product?->name ?? (string) $order->product_raw,
                );
            });

        // FSD in batches, each with its qty from the Pancake delivered orders.
        $days = [];
        LogisticsOrder::where('team', LogisticsOrder::TEAM_FSD)->where('non_crd', false)
            ->where('delivered_date', '>=', $since->toDateString())->where('delivered_date', '<', $to->addDay()->toDateString())
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
                        $product = $catalog->match((string) $order->product);
                        $days[$order->product] = [$product?->consumption_days, $product?->name ?? (string) $order->product];
                    }

                    $consider('fsd', (string) $order->phone_key, substr((string) $order->delivered_date, 0, 10), max(1, (int) $qty[$order->order_id]),
                        $days[$order->product][0], (string) $order->order_id, $days[$order->product][1]);
                }
            });

        // Overall: each customer's latest delivery across both lists.
        $due['all'] = $due['crd'];
        foreach ($due['fsd'] as $phone => $d) {
            if (! isset($due['all'][$phone]) || $d['delivered'] > $due['all'][$phone]['delivered']) {
                $due['all'][$phone] = $d;
            }
        }

        return [$due, $this->outcomes($due, $detailsFor)];
    }

    /**
     * Per list and customer, whether an order or delivery came after the judged delivery and by the deadline (true =
     * came back, false = lost). With $detailsFor, for that list only: the first such one (back, null when lost) and the
     * latest one after the judged delivery (last, null when nothing came after). Customers are looked up 1,000 at a
     * time and only this is kept, so a long range doesn't hold every later order in memory.
     *
     * @param  array{crd: array<string, array<string, string>>, fsd: array<string, array<string, string>>, all: array<string, array<string, string>>}  $due
     * @return array<string, array<string, bool|array{back: ?array{order_id: string, date: string, source: string}, last: ?array{order_id: string, date: string, source: string}}>>
     */
    private function outcomes(array $due, ?string $detailsFor = null): array
    {
        $lists = $detailsFor === null ? ['crd', 'fsd', 'all'] : [$detailsFor];
        $outcomes = array_fill_keys($lists, []);
        $dates = [];

        foreach (array_chunk(array_map('strval', array_keys(array_replace(...array_map(fn (string $list) => $due[$list] ?? [], $lists)))), 1000) as $chunk) {
            $since = min(array_map(fn (string $phone) => min(array_filter(array_map(fn (string $list) => $due[$list][$phone]['delivered'] ?? null, $lists))), $chunk));
            $orders = [];

            // Orders first, then deliveries: on the same day an order comes before a delivery.
            PancakeOrder::counted()->crdProducts()->whereIn('phone_key', $chunk)->where('ordered_on', '>=', date('Y-m-d', strtotime($since.' +1 day')))
                ->toBase()->get(['phone_key', 'pancake_order_id', 'ordered_on'])
                ->each(function (object $order) use (&$orders, &$dates) {
                    $date = substr((string) $order->ordered_on, 0, 10);
                    $orders[$order->phone_key][] = ['order_id' => (string) $order->pancake_order_id, 'date' => $dates[$date] ??= $date, 'source' => 'order'];
                });
            LogisticsOrder::where('non_crd', false)->whereIn('phone_key', $chunk)->where('delivered_date', '>=', date('Y-m-d', strtotime($since.' +1 day')))
                ->toBase()->get(['phone_key', 'order_id', 'delivered_date'])
                ->each(function (object $order) use (&$orders, &$dates) {
                    $date = substr((string) $order->delivered_date, 0, 10);
                    $orders[$order->phone_key][] = ['order_id' => (string) $order->order_id, 'date' => $dates[$date] ??= $date, 'source' => 'delivery'];
                });

            foreach ($chunk as $phone) {
                foreach ($lists as $list) {
                    if (! isset($due[$list][$phone])) {
                        continue;
                    }

                    $d = $due[$list][$phone];
                    $back = $last = null;
                    foreach ($orders[$phone] ?? [] as $o) {
                        if ($o['date'] <= $d['delivered']) {
                            continue;
                        }
                        // The earliest inside the window (the first listed on a tie) and the latest after it (the last listed on a tie).
                        if ($o['date'] <= $d['deadline'] && ($back === null || $o['date'] < $back['date'])) {
                            $back = $o;
                        }
                        if ($last === null || $o['date'] >= $last['date']) {
                            $last = $o;
                        }
                    }

                    $outcomes[$list][$phone] = $detailsFor === null ? $back !== null : ['back' => $back, 'last' => $last];
                }
            }
        }

        return $outcomes;
    }

    /**
     * @param  array<string, array<string, string>>  $due
     * @param  array<string, bool>  $cameBack
     * @return array{customers: int, lost: int, rate: ?float, delivered_months: array<string, int>}
     */
    private function summary(array $due, array $cameBack): array
    {
        $lost = 0;
        $months = [];
        foreach ($due as $phone => $d) {
            $lost += ($cameBack[$phone] ?? false) ? 0 : 1;
            $month = substr($d['delivered'], 0, 7);
            $months[$month] = ($months[$month] ?? 0) + 1;
        }
        ksort($months);

        return [
            'customers' => count($due),
            'lost' => $lost,
            'rate' => $due === [] ? null : $lost / count($due),
            'delivered_months' => $months,
        ];
    }
}
