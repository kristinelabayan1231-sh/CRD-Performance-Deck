<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Support\MonthWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WeeklySegmentationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $canViewAll = $user->can('segmentation.view_all');
        $today = Lead::today();

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'week' => ['nullable', 'integer', 'min:1', 'max:5'],
            'cra' => ['nullable', 'string'],
        ]);

        $month = CarbonImmutable::parse(($filters['month'] ?? $today->format('Y-m')).'-01');
        $weeks = MonthWeeks::for($month);
        $weekNumber = min((int) ($filters['week'] ?? MonthWeeks::containing($month, $today)), count($weeks));
        $week = $weeks[$weekNumber - 1];
        $days = collect(range(0, $week['start']->diffInDays($week['end'])))->map(fn ($i) => $week['start']->addDays($i));

        $allCras = LeadGenerator::cras();
        $cra = $canViewAll ? ($filters['cra'] ?? 'all') : (string) $user->id;
        // CRAs see only themselves; supervisors see everyone or one CRA.
        $shownCras = match (true) {
            ! $canViewAll => collect([$user]),
            ctype_digit($cra) => $allCras->where('id', (int) $cra)->values(),
            default => $allCras,
        };
        $ids = $shownCras->pluck('id');

        $weekLeads = Lead::whereIn('assigned_to', $ids)
            ->whereDate('est_out_of_stock_date', '>=', $week['start'])
            ->whereDate('est_out_of_stock_date', '<=', $week['end']);

        // assigned_to => day => [assigned, handled]
        $grid = (clone $weekLeads)
            ->selectRaw('assigned_to, date(est_out_of_stock_date) as day, count(*) as assigned, sum(case when status is not null then 1 else 0 end) as handled')
            ->groupBy('assigned_to', 'day')
            ->get()
            ->groupBy('assigned_to')
            ->map(fn ($rows) => $rows->keyBy(fn ($row) => CarbonImmutable::parse($row->day)->toDateString()));

        $rows = $shownCras->map(function (User $cra) use ($grid, $days, $today) {
            $cells = $days->map(function (CarbonImmutable $day) use ($grid, $cra, $today) {
                $row = $grid[$cra->id][$day->toDateString()] ?? null;
                $assigned = (int) ($row->assigned ?? 0);
                $handled = (int) ($row->handled ?? 0);

                return [
                    'day' => $day,
                    'assigned' => $assigned,
                    'handled' => $handled,
                    'future' => $day->greaterThan($today),
                    'state' => match (true) {
                        $day->greaterThan($today) || $assigned === 0 => 'none',
                        $handled === $assigned => 'done',
                        $day->equalTo($today) => 'today',
                        $handled === 0 => 'missed',
                        default => 'partial',
                    },
                ];
            });

            $assigned = $cells->sum('assigned');
            $handled = $cells->sum('handled');
            // Only past lead days can be unprocessed; today's open leads are still in progress.
            $unprocessed = $cells->filter(fn ($c) => $c['day']->lessThan($today))->sum(fn ($c) => $c['assigned'] - $c['handled']);

            return [
                'cra' => $cra,
                'cells' => $cells,
                'assigned' => $assigned,
                'handled' => $handled,
                'unprocessed' => $unprocessed,
                'in_progress' => $assigned - $handled - $unprocessed,
                'rate' => $assigned ? round($handled / $assigned * 100) : null,
            ];
        })->values();

        // This week's carry-over customers, grouped per CRA.
        $unprocessed = (clone $weekLeads)->carryOver($today)
            ->with(['assignee', 'transfers.fromUser'])
            ->orderBy('est_out_of_stock_date')
            ->orderBy('customer_name')
            ->get()
            ->groupBy('assigned_to');

        $totals = [
            'assigned' => $rows->sum('assigned'),
            'handled' => $rows->sum('handled'),
            'unprocessed' => $rows->sum('unprocessed'),
            'backlog' => Lead::carryOver($today)->whereIn('assigned_to', $ids)->count(),
        ];

        return view('segmentation.weekly', [
            'month' => $month,
            'weeks' => $weeks,
            'week' => $week,
            'days' => $days,
            'rows' => $rows,
            'unprocessed' => $unprocessed,
            'totals' => $totals,
            'filters' => ['month' => $month->format('Y-m'), 'week' => $weekNumber, 'cra' => $cra],
            'cras' => $allCras,
            'canViewAll' => $canViewAll,
            'canTransfer' => $user->can('segmentation.transfer'),
            'workload' => $user->can('segmentation.transfer') ? LeadGenerator::workload($today) : [],
            'today' => $today,
        ]);
    }
}
