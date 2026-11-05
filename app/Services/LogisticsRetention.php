<?php

namespace App\Services;

use App\Models\LogisticsOrder;
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
 * Ranges filter by delivered date. Kept in the cache; every Shecom read (lead sync included) refreshes it.
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
        $report = $this->client->report();
        self::store($report);
        LogisticsOrder::remember($report['delivered']);
    }

    /**
     * The six tiles for deliveries from $from to $to.
     *
     * @return array{name: string, label: string, fb_delivered: int, fb_retained: int, retention_rate: ?float, crd_delivered: int, crd_again: int, repeat_rate: ?float}|null
     */
    public static function range(CarbonImmutable $from, CarbonImmutable $to, string $label): ?array
    {
        $data = self::cached();

        if (! $data) {
            return null;
        }

        $totals = ['fb_delivered' => 0, 'fb_retained' => 0, 'crd_delivered' => 0, 'crd_again' => 0];

        foreach ($data['days'] as $day => $counts) {
            if ($day < $from->toDateString() || $day > $to->toDateString()) {
                continue;
            }
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + ($counts[$key] ?? 0);
            }
        }

        return [
            'name' => $label,
            'label' => $label,
            ...$totals,
            'retention_rate' => $totals['fb_delivered'] ? $totals['fb_retained'] / $totals['fb_delivered'] : null,
            'repeat_rate' => $totals['crd_delivered'] ? $totals['crd_again'] / $totals['crd_delivered'] : null,
        ];
    }
}
