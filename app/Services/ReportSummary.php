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
 * Average sales per CRA = Gross BC ÷ CRAs on duty.
 * Leads and Engagement are the Daily I.S. totals (PC Report Total Inquiries / Total Engagement on the product pages),
 * added up over the days. Conv % and AOV tally with the Daily I.S. CRA card: Conv % = its orders (canceled included)
 * ÷ Engagement, AOV = its Gross Sales ÷ its orders. Net income is the Daily I.S. CRA card's NET INCOME, added up over
 * the days. I.BSC % = Net income ÷ Total gross (BC & SC). Average IBSC per CRA = Net income ÷ CRAs on duty.
 */
class ReportSummary
{
    public function __construct(private ConversionBreakdown $breakdown, private DailyIncomeStatement $statement) {}

    /**
     * @param  Collection<int, User>  $cras
     * @return array{rows: Collection<int, array{cra: User, metrics: array<string, int|float|null>}>, total: array<string, int|float|null>, on_duty: float|null, average_sales: float|null, average_ibsc: float|null}
     */
    public function for(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $this->breakdown->days($cras, $from, $to);
        $reach = $this->statement->reach($cras, $from, $to);
        $cards = $this->statement->cardTotals($cras, $from, $to);

        $rows = $cras->map(fn (User $cra) => [
            'cra' => $cra,
            'metrics' => self::metrics(ConversionBreakdown::sum($days[$cra->id] ?? []), $reach[$cra->id], $cards[$cra->id]),
        ])->values();

        $total = self::metrics(ConversionBreakdown::sum($rows->pluck('metrics')), [
            'leads' => $rows->sum('metrics.is_leads'),
            'engagements' => $rows->sum('metrics.is_engagements'),
        ], [
            'net_income' => $rows->sum('metrics.net_income'),
            'orders' => $rows->sum('metrics.is_orders'),
            'gross' => $rows->sum('metrics.is_gross'),
        ]);
        $onDuty = OnDuty::countFor($from, $to);

        return [
            'rows' => $rows,
            'total' => $total,
            'on_duty' => $onDuty,
            'average_sales' => $onDuty ? $total['bc_gross'] / $onDuty : null,
            'average_ibsc' => $onDuty ? $total['net_income'] / $onDuty : null,
        ];
    }

    /**
     * @param  array<string, int|float|null>  $sum
     * @param  array{leads: int, engagements: int}  $reach  Daily I.S. leads and engagements
     * @param  array{net_income: float, orders: int, gross: float}  $card  Daily I.S. CRA card: NET INCOME, orders, Gross Sales
     * @return array<string, int|float|null>
     */
    private static function metrics(array $sum, array $reach, array $card): array
    {
        return [
            ...$sum,
            'is_orders' => $card['orders'],
            'is_gross' => $card['gross'],
            'aov' => $card['orders'] ? $card['gross'] / $card['orders'] : null,
            'is_leads' => $reach['leads'],
            'is_engagements' => $reach['engagements'],
            'conv' => $reach['engagements'] ? $card['orders'] / $reach['engagements'] : null,
            'net_income' => $card['net_income'],
            'ibsc' => $sum['gross'] ? $card['net_income'] / $sum['gross'] : null,
        ];
    }
}
