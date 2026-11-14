<?php

namespace App\Services;

use App\Models\CraGrossSale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * CRA Gross Sales & IBSC: each CRA's Gross Sales and Net Income per month of a year, typed in on the page
 * (cra_gross_sales; copied once from the Actual Income cards on 2026-11-14, no longer taken from them). A CRA's month is
 * empty (null) until something is typed for it; TOTAL adds up the CRAs shown. Each month's top seller is the CRA with
 * the most Gross Sales.
 *
 * Running ACB = (the year's positive + negative Net Income so far) × 1.4% (segmentation.acb). Before June 2026 a month
 * only counts when its Net Income is below ₱250,000; from June 2026 every month counts.
 */
class CraGrossSales
{
    /**
     * Rows: $cras (the active CRAs) and anyone else with figures that year (a CRA who has left).
     *
     * @param  Collection<int, User>  $cras
     * @return array{months: list<CarbonImmutable>, rows: Collection<int, User>, top: array<int, int|null>, cras: array<int, array{months: array<int, array{gross: float, net: float}|null>, year: array{gross: float, net: float}|null}>, typed: array<int, array<int, array{gross: float|null, net: float|null}>>, acb: array<int, float|null>, acbTotal: float|null, totals: array<int, array{gross: float, net: float}|null>, year: array{gross: float, net: float}|null}
     */
    public function year(Collection $cras, int $year): array
    {
        $months = array_map(fn (int $month) => CarbonImmutable::create($year, $month, 1), range(1, 12));
        $saved = CraGrossSale::whereYear('month', $year)->get(['month', 'user_id', 'gross', 'net']);
        $others = User::whereIn('id', $saved->pluck('user_id')->unique()->diff($cras->pluck('id')))->get();
        $rows = $cras->concat($others)->unique('id')->sortBy(fn (User $user) => $user->craSortKey())->values();

        $cells = [];
        $typed = [];
        foreach ($rows as $cra) {
            foreach ($months as $index => $month) {
                $figures = $saved->first(fn (CraGrossSale $sale) => $sale->user_id === $cra->id && $sale->month->month === $month->month);
                $filled = $figures !== null && ($figures->gross !== null || $figures->net !== null);
                $cells[$cra->id][$index] = $filled ? ['gross' => (float) $figures->gross, 'net' => (float) $figures->net] : null;
                $typed[$cra->id][$index] = ['gross' => $figures?->gross, 'net' => $figures?->net];
            }
        }

        $totals = array_map(fn (int $index) => self::add(array_column($cells, $index)), array_keys($months));
        $acb = array_map(fn (array $row) => self::acb($row, $months), $cells);
        // The month's top seller: the CRA with the most Gross Sales (the first in CRA order on a tie).
        $top = array_map(function (int $index) use ($cells): ?int {
            $best = null;
            foreach ($cells as $craId => $row) {
                if ($row[$index] !== null && ($best === null || $row[$index]['gross'] > $cells[$best][$index]['gross'])) {
                    $best = $craId;
                }
            }

            return $best;
        }, array_keys($months));

        return [
            'months' => $months,
            'rows' => $rows,
            'cras' => array_map(fn (array $row) => ['months' => $row, 'year' => self::add($row)], $cells),
            'typed' => $typed,
            'acb' => $acb,
            'acbTotal' => array_filter($acb, fn (?float $value) => $value !== null) === [] ? null : (float) array_sum($acb),
            'totals' => $totals,
            'top' => $top,
            'year' => self::add($totals),
        ];
    }

    /**
     * A CRA's Running ACB for the year: the Net Income of the months that count × the rate; null without any.
     *
     * @param  array<int, array{gross: float, net: float}|null>  $row
     * @param  list<CarbonImmutable>  $months
     */
    private static function acb(array $row, array $months): ?float
    {
        $cap = (float) config('segmentation.acb.cap');
        $cappedUntil = CarbonImmutable::parse(config('segmentation.acb.capped_until'));
        $counted = [];
        foreach ($row as $index => $cell) {
            if ($cell !== null && ($months[$index]->gte($cappedUntil) || $cell['net'] < $cap)) {
                $counted[] = $cell['net'];
            }
        }

        return $counted === [] ? null : round(array_sum($counted) * (float) config('segmentation.acb.rate'), 2);
    }

    /**
     * Add up the cells that have figures; null when none do.
     *
     * @param  array<int, array{gross: float, net: float}|null>  $cells
     * @return array{gross: float, net: float}|null
     */
    private static function add(array $cells): ?array
    {
        $filled = array_filter($cells);

        return $filled === [] ? null : [
            'gross' => array_sum(array_column($filled, 'gross')),
            'net' => array_sum(array_column($filled, 'net')),
        ];
    }
}
