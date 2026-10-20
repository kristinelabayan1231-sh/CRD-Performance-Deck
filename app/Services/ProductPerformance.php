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
 * gross sales (1 = top), against its place the period before. Retention = of the customers due to reorder in the
 * period (as the churn rate counts them, by the product of their delivery), the share who came back in time.
 */
class ProductPerformance
{
    public const CANCELLED = 6;

    /** A parcel still shipped this many days after the order is called out. */
    public const SLOW_DAYS = 7;

    /** A product needs this many delivered or returned orders before its RTS rate is called out. */
    private const MIN_FINISHED = 5;

    public function __construct(private ProductReport $report, private CustomerChurn $churn) {}

    /**
     * @param  array<string, string>  $sellers  staff key => name, the CRD accounts
     * @return array{unit: string, previousFrom: CarbonImmutable, previousTo: CarbonImmutable, total: array<string, int|float>, previousTotal: array<string, int|float>, labels: list<string>, series: list<float>, products: Collection<int, array<string, mixed>>, retention: array{due: int, back: int, rate: ?float}, retentionGoal: float, insights: list<array{tone: string, title: string, body: string}>}
     */
    public function build(array $sellers, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): array
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
        $handled = fn (array $line) => $line['listed'];
        $lines = $this->report->lines($sellers, $from, $to)->filter($handled)->values();
        $previous = $this->report->lines($sellers, $previousFrom, $previousTo)->filter($handled)->values();
        $buckets = self::buckets($unit, $from, $to);
        $previousByProduct = $previous->groupBy('product');

        $retention = $this->churn->byProduct($from, $to->min($today));
        $previousRetention = $this->churn->byProduct($previousFrom, $previousTo);
        $products = $lines->groupBy('product')
            ->map(fn (Collection $group, string $name) => [
                'product' => $name,
                ...self::counts($group),
                'previous' => self::counts($previousByProduct->get($name, collect())),
                'series' => self::series($group, $unit, $buckets),
                'retention' => self::retention($retention[$name] ?? null),
                'previous_retention' => self::retention($previousRetention[$name] ?? null),
                'retention_series' => self::retentionSeries($retention[$name]['days'] ?? [], $unit, $buckets),
            ])
            ->sortByDesc('sales')
            ->values();
        $products = self::ranked($products);

        $total = self::counts($lines);
        $due = $products->sum(fn (array $product) => $product['retention']['due']);
        $back = $products->sum(fn (array $product) => $product['retention']['back']);
        $totalRetention = ['due' => $due, 'back' => $back, 'rate' => $due > 0 ? $back / $due : null];
        $goal = (float) config('customers.product_retention_goal') / 100;

        return [
            'unit' => $unit,
            'previousFrom' => $previousFrom,
            'previousTo' => $previousTo,
            'total' => $total,
            'previousTotal' => self::counts($previous),
            'labels' => self::labels($unit, $buckets),
            'series' => self::series($lines, $unit, $buckets),
            'products' => $products,
            'retention' => $totalRetention,
            'retentionGoal' => $goal,
            'insights' => [
                ...self::insights($products, $total, $lines, $unit, $days, $today),
                ...self::rankingInsights($products),
                ...self::retentionInsights($products, $goal),
            ],
        ];
    }

    /**
     * Each product's place now and the period before, by gross sales and by retention (1 = top; null when it had no
     * sales, or no customers due, then), and its place by gross sales in every slot of the chart.
     *
     * @param  Collection<int, array<string, mixed>>  $products  sorted by gross sales
     * @return Collection<int, array<string, mixed>>
     */
    private static function ranked(Collection $products): Collection
    {
        $places = fn (Collection $values) => $values->filter(fn (?float $value) => $value !== null)->sortDesc()->keys()->flip()->map(fn (int $i) => $i + 1);
        $sales = $places($products->map(fn (array $product) => $product['sales'] > 0 ? $product['sales'] : null));
        $previousSales = $places($products->map(fn (array $product) => $product['previous']['sales'] > 0 ? $product['previous']['sales'] : null));
        $retention = $places($products->map(fn (array $product) => $product['retention']['rate']));
        $previousRetention = $places($products->map(fn (array $product) => $product['previous_retention']['rate']));
        $slots = count($products->first()['series'] ?? []);
        $slotPlaces = collect(range(0, max(0, $slots - 1)))->map(fn (int $slot) => $places($products->map(fn (array $product) => $product['series'][$slot] ?? 0.0)));

        return $products->map(fn (array $product, int $i) => [
            ...$product,
            'rank' => $sales[$i] ?? null,
            'previous_rank' => $previousSales[$i] ?? null,
            'retention_rank' => $retention[$i] ?? null,
            'previous_retention_rank' => $previousRetention[$i] ?? null,
            'rank_series' => $slots ? $slotPlaces->map(fn (Collection $slot) => $slot[$i])->all() : [],
        ]);
    }

    /**
     * @param  array{due: int, back: int}|null  $counts
     * @return array{due: int, back: int, rate: ?float}
     */
    private static function retention(?array $counts): array
    {
        $due = $counts['due'] ?? 0;
        $back = $counts['back'] ?? 0;

        return ['due' => $due, 'back' => $back, 'rate' => $due > 0 ? $back / $due : null];
    }

    /**
     * Retention per slot of the chart, by the customers' reorder deadline (null where nobody was due). A one-day chart
     * runs by the hour, which deadlines don't have, so it has none.
     *
     * @param  array<string, array{due: int, back: int}>  $days
     * @param  list<int|string>  $buckets
     * @return list<?float>
     */
    private static function retentionSeries(array $days, string $unit, array $buckets): array
    {
        if ($unit === 'hour') {
            return array_fill(0, count($buckets), null);
        }

        $slots = [];
        foreach ($days as $day => $counts) {
            $slot = $unit === 'week' ? CarbonImmutable::parse($day)->startOfWeek(CarbonImmutable::MONDAY)->toDateString() : $day;
            $slots[$slot]['due'] = ($slots[$slot]['due'] ?? 0) + $counts['due'];
            $slots[$slot]['back'] = ($slots[$slot]['back'] ?? 0) + $counts['back'];
        }

        return array_map(fn (int|string $bucket) => isset($slots[$bucket]) && $slots[$bucket]['due'] > 0 ? $slots[$bucket]['back'] / $slots[$bucket]['due'] : null, $buckets);
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
     * The product that keeps the most customers, the one that loses the most, and how many are below the goal.
     *
     * @param  Collection<int, array<string, mixed>>  $products
     * @return list<array{tone: string, title: string, body: string}>
     */
    private static function retentionInsights(Collection $products, float $goal): array
    {
        $due = $products->filter(fn (array $product) => $product['retention']['rate'] !== null)->sortByDesc(fn (array $product) => $product['retention']['rate'])->values();
        if ($due->isEmpty()) {
            return [];
        }

        $percent = fn (float $rate) => number_format($rate * 100, 1).'%';
        $goalLabel = rtrim(rtrim(number_format($goal * 100, 1), '0'), '.').'%';
        $best = $due->first();
        $insights = [[
            'tone' => 'good',
            'title' => "{$best['product']} keeps the most customers: {$percent($best['retention']['rate'])}",
            'body' => "{$best['retention']['back']} of {$best['retention']['due']} reordered before the deadline.",
        ]];

        $worst = $due->last();
        if ($due->count() > 1 && $worst['retention']['rate'] < $goal) {
            $lost = $worst['retention']['due'] - $worst['retention']['back'];
            $insights[] = [
                'tone' => 'bad',
                'title' => "{$worst['product']} loses most of its customers",
                'body' => "Only {$percent($worst['retention']['rate'])} came back. {$lost} lost, below the {$goalLabel} goal.",
            ];
        }

        $below = $due->filter(fn (array $product) => $product['retention']['rate'] < $goal)->count();
        if ($below > 0) {
            $insights[] = [
                'tone' => 'neutral',
                'title' => "{$below} of {$due->count()} ".str('product')->plural($due->count()).' below the retention goal',
                'body' => "Fewer than {$goalLabel} of their customers due to reorder came back in time.",
            ];
        }

        return $insights;
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
     * The chart's slots: the day's hours, the days, or the weeks (by their Monday).
     *
     * @return list<int|string>
     */
    private static function buckets(string $unit, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($unit === 'hour') {
            return range(0, 23);
        }

        $step = $unit === 'week' ? 'addWeek' : 'addDay';
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
        $sums = $lines->groupBy(fn (array $line) => match ($unit) {
            'hour' => $line['hour'],
            'week' => CarbonImmutable::parse($line['day'])->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            default => $line['day'],
        })->map(fn (Collection $group) => (float) $group->sum('sales'));

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
