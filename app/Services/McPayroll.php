<?php

namespace App\Services;

use App\Models\MonthlyCostRow;
use App\Models\MonthlyCostSnapshot;
use App\Support\MonthlyCost;
use Carbon\CarbonImmutable;

/**
 * The MC / salaries API (GET /api/external/v1/crd/mc/summary and /roster): CRD - MC's payroll for a month.
 *
 * Figures come from CRD - MC as typed in, and are saved for the month they were read in (record()). The current month
 * follows every change in CRD - MC; a past month keeps what it was when it ended, and a month before the first save
 * has none. Salaries = the named rows' monthly salaries + the shared salaries (Sales Manager, VP of Internal Affairs,
 * CEO) at CRD's share. Headcount = the named rows; a vacant row costs nothing. 13th Month = salaries ÷ 12, SIL =
 * salaries × 12 ÷ 313, Gov. Benefits = (PHIC + HDMF + SSS) per person × headcount: the same as CRD - MC's totals.
 */
class McPayroll
{
    public const DEPARTMENT = 'crd';

    public const GROUP_LEADERSHIP = 'leadership';

    public const GROUP_TEAM = 'crd team';

    public function __construct(private MonthlyCost $cost) {}

    /**
     * The saved figures for $month (Y-m), saving the current month first; null for a future month or one never saved.
     */
    public function forMonth(string $month): ?MonthlyCostSnapshot
    {
        $current = self::currentMonth();

        if ($month > $current) {
            return null;
        }
        if ($month === $current) {
            return $this->record();
        }

        return MonthlyCostSnapshot::firstWhere('month', $month);
    }

    /**
     * Save CRD - MC's figures as this month's; changed_at moves only when they differ from what was saved.
     */
    public function record(): MonthlyCostSnapshot
    {
        $month = self::currentMonth();
        $summary = $this->summary();
        $roster = $this->roster();
        $hash = hash('sha256', json_encode([$summary, $roster]));
        $snapshot = MonthlyCostSnapshot::firstWhere('month', $month);

        if ($snapshot?->content_hash === $hash) {
            return $snapshot;
        }

        return MonthlyCostSnapshot::updateOrCreate(['month' => $month], [
            'summary' => $summary, 'roster' => $roster, 'content_hash' => $hash, 'changed_at' => now(),
        ]);
    }

    /**
     * The summary for the API, with month and updatedAt.
     *
     * @return array{month: string, headcount: int, salaries: float, thirteenthMonth: float, sil: float, govBenefits: float, govBreakdown: array{phic: float, hdmf: float, sss: float}, updatedAt: string}
     */
    public static function summaryOf(MonthlyCostSnapshot $snapshot): array
    {
        return ['month' => $snapshot->month, ...$snapshot->summary, 'updatedAt' => self::updatedAt($snapshot)];
    }

    /**
     * The roster for the API, with month and updatedAt.
     *
     * @return array{month: string, employees: list<array<string, mixed>>, updatedAt: string}
     */
    public static function rosterOf(MonthlyCostSnapshot $snapshot): array
    {
        return ['month' => $snapshot->month, 'employees' => $snapshot->roster, 'updatedAt' => self::updatedAt($snapshot)];
    }

    public static function currentMonth(): string
    {
        return now(config('segmentation.timezone'))->format('Y-m');
    }

    private static function updatedAt(MonthlyCostSnapshot $snapshot): string
    {
        return CarbonImmutable::parse($snapshot->changed_at)->timezone(config('segmentation.timezone'))->toIso8601String();
    }

    /**
     * @return array{headcount: int, salaries: float, thirteenthMonth: float, sil: float, govBenefits: float, govBreakdown: array{phic: float, hdmf: float, sss: float}}
     */
    private function summary(): array
    {
        $totals = $this->cost->totals(MonthlyCostRow::all());
        $people = $totals['people'];

        return [
            'headcount' => $people,
            'salaries' => $totals['customer_retention'],
            'thirteenthMonth' => $totals['thirteenth_month'],
            'sil' => $totals['sil'],
            'govBenefits' => $totals['gov_benefits'],
            'govBreakdown' => [
                'phic' => round($this->cost->setting('phic') * $people, 2),
                'hdmf' => round($this->cost->setting('hdmf') * $people, 2),
                'sss' => round($this->cost->setting('sss') * $people, 2),
            ],
        ];
    }

    /**
     * One row per person on CRD - MC (vacant positions left out), then each shared salary CRD carries a part of. Start
     * and end dates aren't kept in CRD - MC, so they are null; whoever is listed is active.
     *
     * @return list<array{position: string, name: ?string, group: string, monthlySalary: float, allocationPct: float, status: string, startDate: null, endDate: null}>
     */
    private function roster(): array
    {
        $team = MonthlyCostRow::orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (MonthlyCostRow $row) => $row->isFilled())
            ->map(fn (MonthlyCostRow $row) => [
                'position' => $row->position,
                'name' => trim((string) $row->name),
                'group' => self::GROUP_TEAM,
                'monthlySalary' => $row->monthlySalary(),
                'allocationPct' => 100.0,
                'status' => 'active',
                'startDate' => null,
                'endDate' => null,
            ]);

        $shared = collect($this->cost->shared())
            ->filter(fn (array $row) => $row['salary'] > 0 && $row['share'] > 0)
            ->map(fn (array $row) => [
                'position' => $row['label'],
                'name' => null,
                'group' => self::GROUP_LEADERSHIP,
                'monthlySalary' => (float) $row['salary'],
                'allocationPct' => (float) $row['share'],
                'status' => 'active',
                'startDate' => null,
                'endDate' => null,
            ]);

        return $team->concat($shared)->values()->all();
    }
}
