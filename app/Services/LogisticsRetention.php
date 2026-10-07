<?php

namespace App\Services;

use App\Support\MonthWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * "Live from Logistics" retention numbers: the logistics website's Retention
 * Summary, from the Shecom retention report.
 *
 * FB delivered = Facebook Sales (FSD) orders delivered; retained = later resold by CRD.
 * CRD delivered = CRD orders delivered; ordered again = another CRD order after delivery.
 * Retention rate = retained ÷ FB delivered. Repeat rate = ordered again ÷ CRD delivered.
 * Periods filter by delivered date. Kept in the cache; every Shecom read (lead sync included) refreshes it.
 */
class LogisticsRetention
{
    private const CACHE_KEY = 'logistics.retention';

    public function __construct(private ShecomClient $client) {}

    /**
     * Save the daily counts from a retention report.
     *
     * @param  array{summary: array<string, int|float>, days: array<string, array<string, int>>}  $report
     */
    public static function store(array $report): void
    {
        Cache::put(self::CACHE_KEY, [
            'fetched_at' => now()->toIso8601String(),
            'summary' => $report['summary'],
            'days' => $report['days'],
        ], now()->addDays(7));
    }

    /**
     * @return array{fetched_at: string, summary: array<string, int|float>, days: array<string, array<string, int>>}|null
     */
    public static function cached(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    public static function fetchedAt(): ?CarbonImmutable
    {
        $at = self::cached()['fetched_at'] ?? null;

        return $at ? CarbonImmutable::parse($at) : null;
    }

    /**
     * Read Shecom now when nothing is saved yet; refresh after the response when over $maxAgeMinutes old.
     */
    public function ensureFresh(int $maxAgeMinutes = 60): void
    {
        $at = self::fetchedAt();

        if (! $at) {
            rescue(fn () => $this->refresh(), report: fn (Throwable $e) => Log::warning('Logistics retention read failed', ['message' => $e->getMessage()]));

            return;
        }

        if ($at->lessThanOrEqualTo(now()->subMinutes($maxAgeMinutes))) {
            defer(function () {
                try {
                    Cache::lock('logistics-retention-refresh', 300)->get(fn () => $this->refresh());
                } catch (Throwable $e) {
                    Log::warning('Logistics retention refresh failed', ['message' => $e->getMessage()]);
                }
            }, 'logistics-retention-refresh');
        }
    }

    public function refresh(): void
    {
        self::store($this->client->report());
    }

    /**
     * Week (1–7, 8–14… from the 1st), month so far and all time, each with the six tiles.
     *
     * @return array<string, array{name: string, label: string, fb_delivered: int, fb_retained: int, retention_rate: ?float, crd_delivered: int, crd_again: int, repeat_rate: ?float}>|null
     */
    public static function periods(CarbonImmutable $today): ?array
    {
        $data = self::cached();

        if (! $data) {
            return null;
        }

        $month = $today->startOfMonth();
        $week = MonthWeeks::for($month)[MonthWeeks::containing($month, $today) - 1];
        $first = array_key_first($data['days']);

        $ranges = [
            'today' => ['Today', $today, $today, $today->format('D, M j')],
            // Weeks are fixed 7-day buckets from the 1st (1–7, 8–14 … 29–31); months are whole months.
            'week' => ['Week', $week['start'], $week['end'], 'Week '.$week['number'].' · '.$week['label']],
            'month' => ['Month', $month, $month->endOfMonth()->startOfDay(), $month->format('F Y')],
            'all' => ['All time', null, null, $first ? 'Since '.CarbonImmutable::parse($first)->format('M j, Y') : 'All deliveries'],
        ];

        return collect($ranges)->map(function (array $range) use ($data) {
            [$name, $from, $to, $label] = $range;
            $totals = ['fb_delivered' => 0, 'fb_retained' => 0, 'crd_delivered' => 0, 'crd_again' => 0];

            foreach ($data['days'] as $day => $counts) {
                if ($from && ($day < $from->toDateString() || $day > $to->toDateString())) {
                    continue;
                }
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + ($counts[$key] ?? 0);
                }
            }

            return [
                'name' => $name,
                'label' => $label,
                ...$totals,
                'retention_rate' => $totals['fb_delivered'] ? $totals['fb_retained'] / $totals['fb_delivered'] : null,
                'repeat_rate' => $totals['crd_delivered'] ? $totals['crd_again'] / $totals['crd_delivered'] : null,
            ];
        })->all();
    }
}
