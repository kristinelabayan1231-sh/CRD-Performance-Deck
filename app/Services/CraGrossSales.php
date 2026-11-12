<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * CRA Gross Sales & IBSC: each CRA's Gross Sales and NET INCOME per month of a year, from their Actual Income card. A
 * CRA's month is empty (null) until something is typed on their Actual Income card for it; TOTAL adds up the months
 * shown.
 */
class CraGrossSales
{
    public function __construct(private ActualIncome $income) {}

    /**
     * @param  Collection<int, User>  $cras
     * @return array{months: list<CarbonImmutable>, cras: array<int, array{months: array<int, array{gross: float, net: float}|null>, year: array{gross: float, net: float}|null}>, totals: array<int, array{gross: float, net: float}|null>, year: array{gross: float, net: float}|null}
     */
    public function year(Collection $cras, int $year): array
    {
        $months = array_map(fn (int $month) => CarbonImmutable::create($year, $month, 1), range(1, 12));
        $cells = $cras->mapWithKeys(fn (User $cra) => [$cra->id => []])->all();

        foreach ($months as $index => $month) {
            $data = $this->income->month($cras, $month);
            foreach ($cras as $cra) {
                $cells[$cra->id][$index] = ($data['typed']['cras'][$cra->id] ?? []) === []
                    ? null
                    : ['gross' => (float) $data['cras'][$cra->id]['gross'], 'net' => (float) $data['cras'][$cra->id]['net_income']];
            }
        }

        $totals = array_map(fn (int $index) => self::add(array_column($cells, $index)), array_keys($months));

        return [
            'months' => $months,
            'cras' => array_map(fn (array $row) => ['months' => $row, 'year' => self::add($row)], $cells),
            'totals' => $totals,
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
