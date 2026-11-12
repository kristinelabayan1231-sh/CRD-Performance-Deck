<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * CRA Gross Sales & IBSC: each CRA's Gross Sales and NET INCOME per month of a year, from their Actual Income card. A
 * CRA's month is empty (null) until something is typed on their Actual Income card for it (a month from the CRD Sales
 * Report sheet: until they have a card there); TOTAL adds up the CRAs shown. Each month's top seller is the CRA with the
 * most Gross Sales.
 */
class CraGrossSales
{
    public function __construct(private ActualIncome $income) {}

    /**
     * Rows: $cras (the active CRAs) and anyone else on a sheet month's cards that year (a CRA who has left).
     *
     * @param  Collection<int, User>  $cras
     * @return array{months: list<CarbonImmutable>, rows: Collection<int, User>, top: array<int, int|null>, cras: array<int, array{months: array<int, array{gross: float, net: float}|null>, year: array{gross: float, net: float}|null}>, totals: array<int, array{gross: float, net: float}|null>, year: array{gross: float, net: float}|null}
     */
    public function year(Collection $cras, int $year): array
    {
        $months = array_map(fn (int $month) => CarbonImmutable::create($year, $month, 1), range(1, 12));
        $monthly = array_map(fn (CarbonImmutable $month) => $this->income->month($cras, $month), $months);
        $rows = $cras->concat(collect($monthly)->pluck('shown')->flatten())
            ->unique('id')->sortBy(fn (User $user) => $user->craSortKey())->values();

        $cells = [];
        foreach ($rows as $cra) {
            foreach ($monthly as $index => $data) {
                $cells[$cra->id][$index] = ($data['filled'][$cra->id] ?? false)
                    ? ['gross' => (float) $data['cras'][$cra->id]['gross'], 'net' => (float) $data['cras'][$cra->id]['net_income']]
                    : null;
            }
        }

        $totals = array_map(fn (int $index) => self::add(array_column($cells, $index)), array_keys($months));
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
            'totals' => $totals,
            'top' => $top,
            'year' => self::add($totals),
        ];
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
