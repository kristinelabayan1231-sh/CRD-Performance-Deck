<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Product Performance: every CRD account's Pancake POS orders of the products CRD handles (Settings → Product
 * Consumption) per product for a period, against the period of the
 * same length just before it. Gross sales is the order before Pancake's discount, an order with several products
 * split between them as in Order Database → Product Report. The rates count orders: Delivered is delivered ÷
 * (delivered + RTS), RTS is RTS ÷ the same, and Cancelled is out of every order. Rank is the product's place by
 * gross sales (1 = top), or by RTS (1 = the lowest), against its place the period before.
 */
class ProductPerformance
{
    public const CANCELLED = 6;

    /** A parcel still shipped this many days after the order is called out. */
    public const SLOW_DAYS = 7;

    /** A product needs this many delivered or returned orders before its RTS rate is called out. */
    private const MIN_FINISHED = 5;

    /** How far back the graph of the past days, weeks and months goes. */
    private const TREND_DAYS = 30;

    private const TREND_WEEKS = 12;

    private const TREND_MONTHS = 12;

    public function __construct(private ProductReport $report) {}

    /**
     * @param  array<string, string>  $sellers  staff key => name, the CRD accounts
     * @param  ?list<string>  $only  only these products (the totals, ranks and insights too), null for every product CRD handles
     * @return array{unit: string, previousFrom: CarbonImmutable, previousTo: CarbonImmutable, total: array<string, int|float>, previousTotal: array<string, int|float>, labels: list<string>, series: list<float>, products: Collection<int, array<string, mixed>>, rts: array{rts: int, finished: int, rate: ?float}, insights: list<array{tone: string, title: string, body: string}>}
     */
    public function build(array $sellers, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today, ?array $only = null): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $unit = match (true) {
            $days === 1 => 'hour',
            $days <= 62 => 'day',
            default => 'week',
        };

        // Only the products CRD handles (Settings → Product Consumption); orders' other items are left out.
        $handled = self::handled($only);
        $lines = $this->report->lines($sellers, $from, $to)->filter($handled)->values();
        $previous = $this->report->lines($sellers, $previousFrom, $previousTo)->filter($handled)->values();
        $buckets = self::buckets($unit, $from, $to);
        $previousByProduct = $previous->groupBy('product');

        $products = $lines->groupBy('product')
            ->map(fn (Collection $group, string $name) => [
                'product' => $name,
                ...self::counts($group),
                'previous' => self::counts($previousByProduct->get($name, collect())),
                'series' => self::series($group, $unit, $buckets),
                'rts_series' => self::rtsSeries($group, $unit, $buckets),
            ])
            ->sortByDesc('sales')
            ->values();
        $products = self::ranked($products);

        $total = self::counts($lines);

        return [
            'unit' => $unit,
            'previousFrom' => $previousFrom,
            'previousTo' => $previousTo,
            'total' => $total,
            'previousTotal' => self::counts($previous),
            'labels' => self::labels($unit, $buckets),
            'series' => self::series($lines, $unit, $buckets),
            'products' => $products,
            'rts' => ['rts' => $total['rts'], 'finished' => $total['delivered'] + $total['rts'], 'rate' => self::rtsRate($total)],
            'insights' => [
                ...self::insights($products, $total, $lines, $unit, $days, $today),
                ...self::rankingInsights($products),
                ...self::lowestRtsInsight($products),
            ],
        ];
    }

    /**
     * The past days, weeks (Monday–Sunday) and months up to $to for each tab, from one read of the orders: gross sales,
     * orders and RTS (%) per slot, for all the tab's products together and for each one, most sales first. Slots before
     * the first order are left off, so a product list that started lately doesn't show months of nothing. Partial = the
     * last slot isn't over yet. With the overall ('index') and the CRD, TSD and Testing tabs given, the overall's lines are
     * those three instead of its products, to compare them.
     *
     * @param  array<string, string>  $sellers  staff key => name, the CRD accounts
     * @param  array<string, ?list<string>>  $tabs  tab => only these products, null for every product CRD handles
     * @return array<string, array<string, array{labels: list<string>, partial: bool, total: array{sales: list<float>, orders: list<int>, rts: list<?float>}, products: list<array{name: string, sales: list<float>, orders: list<int>, rts: list<?float>}>}>>
     */
    public function trend(array $sellers, CarbonImmutable $to, CarbonImmutable $today, array $tabs): array
    {
        $months = $to->startOfMonth()->subMonths(self::TREND_MONTHS - 1);
        $all = $this->report->lines($sellers, $months, $to);

        $trends = collect($tabs)->map(function (?array $only) use ($all, $months, $to, $today) {
            $lines = $all->filter(self::handled($only))->values();

            return [
                'day' => self::trendView($lines, 'day', $to->subDays(self::TREND_DAYS - 1), $to, $today),
                'week' => self::trendView($lines, 'week', $to->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(self::TREND_WEEKS - 1), $to, $today),
                'month' => self::trendView($lines, 'month', $months, $to, $today),
            ];
        })->all();

        return isset($trends['index'], $trends['crd'], $trends['tsd'], $trends['testing']) ? self::compared($trends) : $trends;
    }

    /**
     * The overall tab's lines as CRD, TSD and Testing, on its slots: null before a group's first order, so a group that
     * started lately has no line there.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $trends
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function compared(array $trends): array
    {
        foreach ($trends['index'] as $unit => $view) {
            $trends['index'][$unit]['comparison'] = true;
            $trends['index'][$unit]['products'] = collect(['crd' => 'CRD products', 'tsd' => 'TSD products', 'testing' => 'Testing products'])
                ->map(function (string $name, string $tab) use ($trends, $unit, $view) {
                    $group = $trends[$tab][$unit];
                    $places = array_flip($group['labels']);
                    $values = fn (string $metric) => array_map(fn (string $label) => isset($places[$label]) ? $group['total'][$metric][$places[$label]] : null, $view['labels']);

                    return ['name' => $name, 'sales' => $values('sales'), 'orders' => $values('orders'), 'rts' => $values('rts')];
                })
                ->values()
                ->all();
        }

        return $trends;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array{labels: list<string>, partial: bool, total: array{sales: list<float>, orders: list<int>, rts: list<?float>}, products: list<array{name: string, sales: list<float>, orders: list<int>, rts: list<?float>}>}
     */
    private static function trendView(Collection $lines, string $unit, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): array
    {
        $lines = $lines->filter(fn (array $line) => $line['day'] >= $from->toDateString());
        if ($lines->isEmpty()) {
            return ['labels' => [], 'partial' => false, 'total' => ['sales' => [], 'orders' => [], 'rts' => []], 'products' => []];
        }

        $first = $lines->map(fn (array $line) => self::slotOf($line, $unit))->min();
        $buckets = array_values(array_filter(self::buckets($unit, $from, $to), fn (string $bucket) => $bucket >= $first));
        $metrics = function (Collection $group) use ($unit, $buckets) {
            $counts = $group->groupBy(fn (array $line) => self::slotOf($line, $unit))->map(fn (Collection $slot) => self::counts($slot));
            $rate = fn (string $bucket) => isset($counts[$bucket]) ? self::rtsRate($counts[$bucket]) : null;

            return [
                'sales' => array_map(fn (string $bucket) => round($counts[$bucket]['sales'] ?? 0, 2), $buckets),
                'orders' => array_map(fn (string $bucket) => $counts[$bucket]['orders'] ?? 0, $buckets),
                'rts' => array_map(fn (string $bucket) => $rate($bucket) === null ? null : round($rate($bucket) * 100, 1), $buckets),
            ];
        };
        $lastDay = match ($unit) {
            'week' => CarbonImmutable::parse(end($buckets))->addDays(6),
            'month' => CarbonImmutable::parse(end($buckets))->endOfMonth(),
            default => CarbonImmutable::parse(end($buckets)),
        };

        return [
            'labels' => self::labels($unit, $buckets),
            'partial' => $lastDay->toDateString() > $to->toDateString() || $lastDay->toDateString() >= $today->toDateString(),
            'total' => $metrics($lines),
            'products' => $lines->groupBy('product')
                ->sortByDesc(fn (Collection $group) => $group->sum('sales'))
                ->map(fn (Collection $group, string $name) => ['name' => $name, ...$metrics($group)])
                ->values()
                ->all(),
        ];
    }

    /**
     * Only the products CRD handles (Settings → Product Consumption), and of those only $only when given.
     *
     * @param  ?list<string>  $only
     */
    private static function handled(?array $only): callable
    {
        return fn (array $line) => $line['listed'] && ($only === null || in_array($line['product'], $only, true));
    }

    /**
     * The slot of the chart a line falls in: its hour, day, week (by its Monday) or month (by its 1st).
     *
     * @param  array{day: string, hour: int}  $line
     */
    private static function slotOf(array $line, string $unit): int|string
    {
        return match ($unit) {
            'hour' => $line['hour'],
            'week' => CarbonImmutable::parse($line['day'])->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'month' => substr($line['day'], 0, 8).'01',
            default => $line['day'],
        };
    }

    /**
     * Each product's place now and the period before, by gross sales (1 = the most) and by RTS (1 = the lowest; null
     * when it had no sales, or no delivered or returned orders, then), and its place by gross sales in every slot of the chart.
     *
     * @param  Collection<int, array<string, mixed>>  $products  sorted by gross sales
     * @return Collection<int, array<string, mixed>>
     */
    private static function ranked(Collection $products): Collection
    {
        $places = fn (Collection $values) => $values->filter(fn (?float $value) => $value !== null)->sortDesc()->keys()->flip()->map(fn (int $i) => $i + 1);
        $sales = $places($products->map(fn (array $product) => $product['sales'] > 0 ? $product['sales'] : null));
        $previousSales = $places($products->map(fn (array $product) => $product['previous']['sales'] > 0 ? $product['previous']['sales'] : null));
        // Lowest RTS first: the rates turned negative so the same descending sort puts the lowest on top.
        $lowest = fn (?float $rate) => $rate === null ? null : -$rate;
        $rts = $places($products->map(fn (array $product) => $lowest(self::rtsRate($product))));
        $previousRts = $places($products->map(fn (array $product) => $lowest(self::rtsRate($product['previous']))));
        $slots = count($products->first()['series'] ?? []);
        $slotPlaces = collect(range(0, max(0, $slots - 1)))->map(fn (int $slot) => $places($products->map(fn (array $product) => $product['series'][$slot] ?? 0.0)));

        return $products->map(fn (array $product, int $i) => [
            ...$product,
            'rank' => $sales[$i] ?? null,
            'previous_rank' => $previousSales[$i] ?? null,
            'rts_rate' => self::rtsRate($product),
            'rts_rank' => $rts[$i] ?? null,
            'previous_rts_rank' => $previousRts[$i] ?? null,
            'rank_series' => $slots ? $slotPlaces->map(fn (Collection $slot) => $slot[$i])->all() : [],
        ]);
    }

    /**
     * RTS ÷ (delivered + RTS), or null with neither.
     *
     * @param  array{delivered: int, rts: int}  $counts
     */
    public static function rtsRate(array $counts): ?float
    {
        $finished = $counts['delivered'] + $counts['rts'];

        return $finished > 0 ? $counts['rts'] / $finished : null;
    }

    /**
     * RTS per slot of the chart, by the day (or hour) the orders were created; null where none was delivered or returned.
     *
     * @param  Collection<int, array{order: int, status: int, day: string, hour: int}>  $lines
     * @param  list<int|string>  $buckets
     * @return list<?float>
     */
    private static function rtsSeries(Collection $lines, string $unit, array $buckets): array
    {
        $rates = $lines->groupBy(fn (array $line) => self::slotOf($line, $unit))->map(fn (Collection $group) => self::rtsRate(self::counts($group)));

        return array_map(fn (int|string $bucket) => $rates[$bucket] ?? null, $buckets);
    }

    /**
     * The product that climbed the most places by gross sales, and the one that fell the most.
     *
     * @param  Collection<int, array<string, mixed>>  $products
     * @return list<array{tone: string, title: string, body: string}>
     */
    private static function rankingInsights(Collection $products): array
    {
        $moved = $products->filter(fn (array $product) => $product['rank'] !== null && $product['previous_rank'] !== null)
            ->map(fn (array $product) => [...$product, 'move' => $product['previous_rank'] - $product['rank']]);
        $insights = [];
        if ($up = $moved->where('move', '>', 0)->sortByDesc('move')->first()) {
            $insights[] = ['tone' => 'good', 'title' => "{$up['product']} moved up to #{$up['rank']}", 'body' => "From #{$up['previous_rank']} by gross sales the period before."];
        }
        if ($down = $moved->where('move', '<', 0)->sortBy('move')->first()) {
            $insights[] = ['tone' => 'bad', 'title' => "{$down['product']} dropped to #{$down['rank']}", 'body' => "It was #{$down['previous_rank']} by gross sales the period before."];
        }

        return $insights;
    }

    /**
     * The product with the lowest RTS, once it has enough delivered or returned orders (the highest is in insights()).
     *
     * @param  Collection<int, array<string, mixed>>  $products
     * @return list<array{tone: string, title: string, body: string}>
     */
    private static function lowestRtsInsight(Collection $products): array
    {
        $finished = $products->filter(fn (array $product) => $product['delivered'] + $product['rts'] >= self::MIN_FINISHED);
        $best = $finished->sortBy(fn (array $product) => $product['rts_rate'])->first();
        if (! $best || $finished->count() < 2) {
            return [];
        }

        $done = $best['delivered'] + $best['rts'];

        return [[
            'tone' => 'good',
            'title' => "{$best['product']} has the lowest RTS: ".self::rate($best['rts'], $done)."% ({$best['rts']}/{$done})",
            'body' => "{$best['delivered']} of its {$done} delivered or returned orders were delivered.",
        ]];
    }

    /**
     * Orders per status group and per Pancake status, sales and pieces; an order counts once however many lines it has.
     *
     * @param  Collection<int, array{order: int, status: int, qty: int, sales: float}>  $lines
     * @return array{sales: float, qty: int, orders: int, delivered: int, rts: int, shipped: int, cancelled: int, open: int, statuses: array<int, int>}
     */
    public static function counts(Collection $lines): array
    {
        $statuses = $lines->unique('order')->pluck('status');
        $count = fn (array $group) => $statuses->filter(fn (int $status) => in_array($status, $group, true))->count();
        $counts = [
            'delivered' => $count(ProductReport::DELIVERED),
            'rts' => $count(ProductReport::RTS),
            'shipped' => $count(ProductReport::SHIPPED),
            'cancelled' => $count([self::CANCELLED]),
        ];

        return [
            'sales' => (float) $lines->sum('sales'),
            'qty' => (int) $lines->sum('qty'),
            'orders' => $statuses->count(),
            ...$counts,
            // New, confirmed, packing, waiting and the like: not shipped yet.
            'open' => $statuses->count() - array_sum($counts),
            'statuses' => $statuses->countBy()->all(),
        ];
    }

    /**
     * $part out of $whole as a rounded percentage, or null when $whole is 0.
     */
    public static function rate(int|float $part, int|float $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }

    /**
     * The change from $before to $now as a rounded percentage, or null when there was nothing before.
     */
    public static function change(float $now, float $before): ?int
    {
        return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
    }

    /**
     * The chart's slots: the day's hours, the days, the weeks (by their Monday) or the months (by their 1st).
     *
     * @return list<int|string>
     */
    private static function buckets(string $unit, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($unit === 'hour') {
            return range(0, 23);
        }

        $step = match ($unit) {
            'week' => 'addWeek',
            'month' => 'addMonth',
            default => 'addDay',
        };
        $buckets = [];
        for ($slot = $unit === 'week' ? $from->startOfWeek(CarbonImmutable::MONDAY) : $from; $slot->lessThanOrEqualTo($to); $slot = $slot->{$step}()) {
            $buckets[] = $slot->toDateString();
        }

        return $buckets;
    }

    /**
     * @param  list<int|string>  $buckets
     * @return list<string>
     */
    private static function labels(string $unit, array $buckets): array
    {
        return array_map(fn (int|string $bucket) => match ($unit) {
            'hour' => CarbonImmutable::today()->setTime((int) $bucket, 0)->format('g A'),
            'week' => 'Week of '.CarbonImmutable::parse($bucket)->format('M j'),
            'month' => CarbonImmutable::parse($bucket)->format('M Y'),
            default => CarbonImmutable::parse($bucket)->format('M j'),
        }, $buckets);
    }

    /**
     * Sales per slot, in the slots' order.
     *
     * @param  Collection<int, array{sales: float, day: string, hour: int}>  $lines
     * @param  list<int|string>  $buckets
     * @return list<float>
     */
    private static function series(Collection $lines, string $unit, array $buckets): array
    {
        $sums = $lines->groupBy(fn (array $line) => self::slotOf($line, $unit))->map(fn (Collection $group) => (float) $group->sum('sales'));

        return array_map(fn (int|string $bucket) => $sums[$bucket] ?? 0.0, $buckets);
    }

    /**
     * Up to four notes: the top product, the highest RTS, parcels still on the way, and the biggest drop or the best weekday.
     *
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  array<string, int|float>  $total
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return list<array{tone: string, title: string, body: string}>
     */
    private static function insights(Collection $products, array $total, Collection $lines, string $unit, int $days, CarbonImmutable $today): array
    {
        $insights = [];
        $peso = fn (float $amount) => '₱'.number_format($amount);
        if ($top = $products->first()) {
            $change = self::change($top['sales'], $top['previous']['sales']);
            $insights[] = [
                'tone' => 'good',
                'title' => "{$top['product']} leads with {$peso($top['sales'])}",
                'body' => self::rate($top['sales'], $total['sales']).'% of gross sales'
                    .($change === null ? '' : ($change >= 0 ? ", up {$change}%" : ', down '.abs($change).'%').' on the period before'),
            ];
        }

        $worst = $products->filter(fn (array $product) => $product['delivered'] + $product['rts'] >= self::MIN_FINISHED)
            ->sortByDesc(fn (array $product) => $product['rts'] / ($product['delivered'] + $product['rts']))
            ->first();
        if ($worst && $worst['rts'] > 0) {
            $finished = $worst['delivered'] + $worst['rts'];
            $before = self::rate($worst['previous']['rts'], $worst['previous']['delivered'] + $worst['previous']['rts']);
            $insights[] = [
                'tone' => 'bad',
                'title' => "{$worst['product']} has the highest RTS: ".self::rate($worst['rts'], $finished)."% ({$worst['rts']}/{$finished})",
                'body' => $before === null ? 'Returned out of its delivered and returned orders.' : "It was {$before}% the period before.",
            ];
        }

        $shipped = $lines->filter(fn (array $line) => in_array($line['status'], ProductReport::SHIPPED, true));
        if ($shipped->isNotEmpty()) {
            $slow = $shipped->filter(fn (array $line) => $line['day'] < $today->subDays(self::SLOW_DAYS)->toDateString())->unique('order')->count();
            $count = $shipped->unique('order')->count();
            $insights[] = [
                'tone' => 'neutral',
                'title' => "{$count} ".str('parcel')->plural($count).' still on the way',
                'body' => $peso((float) $shipped->sum('sales')).' not delivered yet'.($slow > 0 ? "; {$slow} shipped over ".self::SLOW_DAYS.' days ago.' : '.'),
            ];
        }

        $drop = $products->map(fn (array $product) => [...$product, 'change' => self::change($product['sales'], $product['previous']['sales'])])
            ->filter(fn (array $product) => $product['change'] !== null && $product['change'] <= -10)
            ->sortBy('change')->first();
        if ($drop) {
            $insights[] = [
                'tone' => 'bad',
                'title' => "{$drop['product']} sales down ".abs($drop['change']).'%',
                'body' => "{$peso($drop['sales'])} against {$peso($drop['previous']['sales'])} the period before.",
            ];
        } elseif ($unit === 'day' && $days >= 14 && $lines->isNotEmpty()) {
            $byWeekday = $lines->groupBy(fn (array $line) => CarbonImmutable::parse($line['day'])->dayOfWeekIso)
                ->map(fn (Collection $group) => $group->sum('sales') / max(1, $group->pluck('day')->unique()->count()));
            $best = $byWeekday->sortDesc()->keys()->first();
            $others = $byWeekday->except($best);
            $insights[] = [
                'tone' => 'good',
                'title' => 'Best day: '.CarbonImmutable::now()->isoWeekday($best)->format('l'),
                'body' => 'Averages '.$peso($byWeekday[$best]).' a day'
                    .($others->isNotEmpty() && $others->avg() > 0 ? ', '.self::rate($byWeekday[$best] - $others->avg(), $others->avg()).'% above the other days.' : '.'),
            ];
        }

        return $insights;
    }
}
