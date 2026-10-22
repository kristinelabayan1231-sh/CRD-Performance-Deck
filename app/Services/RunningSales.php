<?php

namespace App\Services;

use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\MonthWeeks;
use App\Support\SalesGoals;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Running Sales: every CRA's sales so far today, this week (MonthWeeks) or this month, overall and per product, against
 * their daily goal (own, else the general CRA daily goal) × the days so far. Sales are Conversion Breakdown's gross sales:
 * CRD - BROADCAST and CRD - SEGMENTATION orders, every status, with the CVR Tracker's corrections; orders leave out
 * canceled and deleted ones. Each order is split over its products as in CraProductSales; items not in Settings →
 * Product Consumption count under Others.
 *
 * Kept as a snapshot, taken every two hours by the scheduler (running-sales:refresh) and by the page's Refresh button.
 */
class RunningSales
{
    /** @var array<string, string> */
    public const PERIODS = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month'];

    public const EVERY_HOURS = 2;

    public const OTHERS = 'Others';

    public function __construct(private CraProductSales $productSales) {}

    /**
     * The last snapshot of $period; taken again when it is from another day, or over two hours old because the
     * scheduler was asleep (Render's free plan sleeps when idle).
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $period): array
    {
        $snapshot = Cache::get(self::key($period));
        $isCurrent = $snapshot
            && $snapshot['today'] === WorkingDate::realToday()->toDateString()
            && CarbonImmutable::parse($snapshot['fetched_at'])->greaterThan(now()->subHours(self::EVERY_HOURS));

        return $isCurrent ? $snapshot : $this->take($period);
    }

    /**
     * Take every period's snapshot again.
     */
    public function refresh(): void
    {
        foreach (array_keys(self::PERIODS) as $period) {
            $this->take($period);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function take(string $period): array
    {
        $snapshot = $this->build($period, WorkingDate::realToday());
        Cache::forever(self::key($period), $snapshot);

        return $snapshot;
    }

    /**
     * @return array{period: string, label: string, from: string, today: string, days: int, fetched_at: string, products: array<string, array{sales: float, qty: int}>, cras: list<array{id: int, name: string, sales: float, orders: int, goal: float, products: array<string, array{sales: float, qty: int}>}>, team: array{sales: float, orders: int, goal: float}}
     */
    public function build(string $period, CarbonImmutable $today): array
    {
        $from = match ($period) {
            'week' => MonthWeeks::for($today)[MonthWeeks::containing($today, $today) - 1]['start'],
            'month' => $today->startOfMonth(),
            default => $today,
        };
        $days = (int) $from->diffInDays($today) + 1;
        $cras = LeadGenerator::cras();
        $accounts = ConversionBreakdown::accounts($cras);

        $tally = [];
        if ($accounts->isNotEmpty()) {
            ConversionBreakdown::taggedOrders($accounts, $from, $today)->get()
                ->each(function (PancakeOrder $order) use ($accounts, &$tally) {
                    $cra = ConversionBreakdown::craFor($order, $accounts);
                    $tally[$cra]['sales'] = ($tally[$cra]['sales'] ?? 0) + $order->sales();
                    $tally[$cra]['orders'] = ($tally[$cra]['orders'] ?? 0) + ($order->isCounted() ? 1 : 0);

                    foreach ($this->productSales->split($order) as $item) {
                        $name = $item['listed'] ? $item['name'] : self::OTHERS;
                        $tally[$cra]['products'][$name]['sales'] = ($tally[$cra]['products'][$name]['sales'] ?? 0) + $item['sales'];
                        $tally[$cra]['products'][$name]['qty'] = ($tally[$cra]['products'][$name]['qty'] ?? 0) + $item['qty'];
                    }
                });
        }

        $general = SalesGoals::craDaily();
        // Highest sales first; ties keep the CRA order.
        $rows = $cras->map(fn (User $cra) => [
            'id' => $cra->id,
            'name' => $cra->displayName(),
            'sales' => (float) ($tally[$cra->id]['sales'] ?? 0),
            'orders' => (int) ($tally[$cra->id]['orders'] ?? 0),
            'goal' => SalesGoals::dailyFor($cra, $general) * $days,
            'products' => $tally[$cra->id]['products'] ?? [],
        ])->sortByDesc('sales')->values()->all();

        // The team's products, best seller first, Others last.
        $products = [];
        foreach ($rows as $row) {
            foreach ($row['products'] as $name => $sold) {
                $products[$name]['sales'] = ($products[$name]['sales'] ?? 0) + $sold['sales'];
                $products[$name]['qty'] = ($products[$name]['qty'] ?? 0) + $sold['qty'];
            }
        }
        uksort($products, fn (string $a, string $b) => [$a === self::OTHERS, -$products[$a]['sales']] <=> [$b === self::OTHERS, -$products[$b]['sales']]);

        return [
            'period' => $period,
            'label' => $from->equalTo($today) ? $today->format('D, M j') : $from->format('M j').'–'.$today->format('j, Y'),
            'from' => $from->toDateString(),
            'today' => $today->toDateString(),
            'days' => $days,
            'fetched_at' => now()->toIso8601String(),
            'products' => $products,
            'cras' => $rows,
            'team' => [
                'sales' => (float) array_sum(array_column($rows, 'sales')),
                'orders' => (int) array_sum(array_column($rows, 'orders')),
                'goal' => (float) array_sum(array_column($rows, 'goal')),
            ],
        ];
    }

    /**
     * When the scheduler takes the next snapshot: the next even hour.
     */
    public static function nextFetch(): CarbonImmutable
    {
        $now = CarbonImmutable::now(config('segmentation.timezone'));

        return $now->startOfHour()->addHours(self::EVERY_HOURS - $now->hour % self::EVERY_HOURS);
    }

    private static function key(string $period): string
    {
        return 'running-sales.'.$period;
    }
}
