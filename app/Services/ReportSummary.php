<?php

namespace App\Services;

use App\Models\User;
use App\Support\OnDuty;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Report Summary per CRA for a day, week or month to date, as in the CRD sheet.
 *
 * Orders BC / SC and Gross BC / SC are Conversion Breakdown's. Total gross = Gross BC + Gross SC.
 * AOV = Total gross ÷ (Orders BC + Orders SC). Average sales per CRA = Gross BC ÷ CRAs on duty.
 * Leads, Engagement and Net income will come from the Oct 1 I.S. (not in yet), so Conv %, I.BSC %
 * and Average IBSC per CRA wait for them.
 */
class ReportSummary
{
    public function __construct(private ConversionBreakdown $breakdown) {}

    /**
     * @param  Collection<int, User>  $cras
     * @return array{rows: Collection<int, array{cra: User, metrics: array<string, int|float|null>}>, total: array<string, int|float|null>, on_duty: float|null, average_sales: float|null}
     */
    public function for(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $this->breakdown->days($cras, $from, $to);

        $rows = $cras->map(fn (User $cra) => [
            'cra' => $cra,
            'metrics' => self::metrics(ConversionBreakdown::sum($days[$cra->id] ?? [])),
        ])->values();

        $total = self::metrics(ConversionBreakdown::sum($rows->pluck('metrics')));
        $onDuty = OnDuty::countFor($from, $to);

        return [
            'rows' => $rows,
            'total' => $total,
            'on_duty' => $onDuty,
            'average_sales' => $onDuty ? $total['bc_gross'] / $onDuty : null,
        ];
    }

    /**
     * @param  array<string, int|float|null>  $sum
     * @return array<string, int|float|null>
     */
    private static function metrics(array $sum): array
    {
        return [...$sum, 'aov' => $sum['orders'] ? $sum['gross'] / $sum['orders'] : null];
    }
}
