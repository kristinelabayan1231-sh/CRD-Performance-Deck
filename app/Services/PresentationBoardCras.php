<?php

namespace App\Services;

use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Presentation Board → CRA performance: each CRA's year so far, kept as its own snapshot so a failure here never costs
 * the board its figures.
 *
 * Gross sales = the same orders as the board's Sales (Conversion Breakdown). NI = the Daily I.S. CRA card's NET INCOME,
 * from NI_FROM on. Capacity a day = customers answered (Segmentation Productivity's Answered) ÷ days they answered
 * anyone. High AOV and VIP = the customers assigned to the CRA now (Customer Database), so they follow no month.
 */
class PresentationBoardCras
{
    public const CACHE_KEY = 'presentation-board.cras';

    /** The first month with a Daily I.S. worth showing. */
    public const NI_FROM = '2026-11';

    public function __construct(
        private SegmentationProductivity $productivity,
        private DailyIncomeStatement $statement,
        private HighValueCustomers $highValue,
    ) {}

    /**
     * The last snapshot; null until the first one is taken (presentation-board:refresh-cras).
     *
     * @return array{months: array<string, array<int, array{gross: float, answered: int, answered_days: int, ni: ?float}>>, high_value: array<int, array{high_aov: int, vip: int}>}|null
     */
    public function snapshot(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * Take the snapshot again.
     *
     * @return array{months: array<string, array<int, array{gross: float, answered: int, answered_days: int, ni: ?float}>>, high_value: array<int, array{high_aov: int, vip: int}>}
     */
    public function refresh(): array
    {
        $snapshot = $this->build(WorkingDate::realToday());
        Cache::forever(self::CACHE_KEY, $snapshot);

        return $snapshot;
    }

    /**
     * The year up to $today.
     *
     * @return array{months: array<string, array<int, array{gross: float, answered: int, answered_days: int, ni: ?float}>>, high_value: array<int, array{high_aov: int, vip: int}>}
     */
    public function build(CarbonImmutable $today): array
    {
        $cras = LeadGenerator::cras();
        $accounts = ConversionBreakdown::accounts($cras);
        $months = [];

        for ($start = $today->startOfYear(); $start->lessThanOrEqualTo($today); $start = $start->addMonth()) {
            $end = $start->endOfMonth()->startOfDay()->min($today);
            $key = $start->format('Y-m');
            $months[$key] = $cras->mapWithKeys(fn (User $cra) => [$cra->id => ['gross' => 0.0, 'answered' => 0, 'answered_days' => 0, 'ni' => null]])->all();

            if ($accounts->isNotEmpty()) {
                foreach (ConversionBreakdown::taggedOrders($accounts, $start, $end)->cursor() as $order) {
                    $craId = ConversionBreakdown::craFor($order, $accounts);
                    if (isset($months[$key][$craId])) {
                        $months[$key][$craId]['gross'] += $order->sales();
                    }
                }
            }

            foreach ($this->productivity->answeredPerDay($cras, $start, $end) as $craId => $days) {
                $months[$key][$craId]['answered'] = array_sum($days);
                $months[$key][$craId]['answered_days'] = count(array_filter($days));
            }

            if ($key >= self::NI_FROM) {
                foreach ($this->statement->netIncome($cras, $start, $end) as $craId => $netIncome) {
                    $months[$key][$craId]['ni'] = $netIncome;
                }
            }
        }

        return ['months' => $months, 'high_value' => $this->highValueCounts($cras)];
    }

    /**
     * The figures for months $from–$to (Y-m), highest gross first.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{rows: list<array{user: User, rank: int, gross: float, share: float, ni: ?float, capacity: ?float, answered_days: int, high_aov: int, vip: int}>, gross: float, high_aov: int, vip: int}
     */
    public function range(array $snapshot, string $from, string $to): array
    {
        $months = array_filter($snapshot['months'], fn (string $month) => $month >= $from && $month <= $to, ARRAY_FILTER_USE_KEY);

        $rows = LeadGenerator::cras()->map(function (User $cra) use ($months, $snapshot) {
            $figures = array_filter(array_column($months, $cra->id));
            $answered = array_sum(array_column($figures, 'answered'));
            $days = array_sum(array_column($figures, 'answered_days'));
            $netIncomes = array_filter(array_column($figures, 'ni'), fn (?float $netIncome) => $netIncome !== null);

            return [
                'user' => $cra,
                'gross' => (float) array_sum(array_column($figures, 'gross')),
                'ni' => $netIncomes === [] ? null : (float) array_sum($netIncomes),
                'capacity' => $days ? $answered / $days : null,
                'answered_days' => $days,
                'high_aov' => $snapshot['high_value'][$cra->id]['high_aov'] ?? 0,
                'vip' => $snapshot['high_value'][$cra->id]['vip'] ?? 0,
            ];
        })->sortByDesc('gross')->values();

        $gross = (float) $rows->sum('gross');

        return [
            'rows' => $rows->map(fn (array $row, int $index) => [...$row, 'rank' => $index + 1, 'share' => $gross ? $row['gross'] / $gross : 0.0])->all(),
            'gross' => $gross,
            'high_aov' => $rows->sum('high_aov'),
            'vip' => $rows->sum('vip'),
        ];
    }

    /**
     * @param  Collection<int, User>  $cras
     * @return array<int, array{high_aov: int, vip: int}>
     */
    private function highValueCounts(Collection $cras): array
    {
        $counts = $cras->mapWithKeys(fn (User $cra) => [$cra->id => ['high_aov' => 0, 'vip' => 0]])->all();
        $customers = $this->highValue->all();
        $owners = $this->highValue->crasFor(array_map('strval', array_keys($customers)));

        foreach ($customers as $phoneKey => $customer) {
            $craId = $owners[$phoneKey] ?? null;
            if ($craId !== null && isset($counts[$craId])) {
                $counts[$craId]['high_aov'] += $customer['high_aov'] ? 1 : 0;
                $counts[$craId]['vip'] += $customer['vip'] ? 1 : 0;
            }
        }

        return $counts;
    }
}
