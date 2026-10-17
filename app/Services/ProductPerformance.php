<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Product Performance: every CRD account's Pancake POS orders of the products CRD handles (Settings → Product
 * Consumption) per product for a period, against the period of the
 * same length just before it. Gross sales is the order before Pancake's discount, an order with several products
 * split between them as in Order Database → Product Report. The rates count orders: Delivered is delivered ÷
 * (delivered + RTS), RTS is RTS ÷ the same, and Cancelled is out of every order.
 */
class ProductPerformance
{
    public const CANCELLED = 6;

    /** A parcel still shipped this many days after the order is called out. */
    public const SLOW_DAYS = 7;

    /** A product needs this many delivered or returned orders before its RTS rate is called out. */
    private const MIN_FINISHED = 5;

    public function __construct(private ProductReport $report) {}

    /**
     * @param  array<string, string>  $sellers  staff key => name, the CRD accounts
     * @return array{unit: string, previousFrom: CarbonImmutable, previousTo: CarbonImmutable, total: array<string, int|float>, previousTotal: array<string, int|float>, labels: list<string>, series: list<float>, products: Collection<int, array<string, mixed>>, insights: list<array{tone: string, title: string, body: string}>}
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

        $products = $lines->groupBy('product')
            ->map(fn (Collection $group, string $name) => [
                'product' => $name,
                ...self::counts($group),
                'previous' => self::counts($previousByProduct->get($name, collect())),
                'series' => self::series($group, $unit, $buckets),
            ])
            ->sortByDesc('sales')
            ->values();

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
            'insights' => self::insights($products, $total, $lines, $unit, $days, $today),
        ];
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
