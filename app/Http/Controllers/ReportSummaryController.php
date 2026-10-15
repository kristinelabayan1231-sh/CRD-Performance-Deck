<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\PancakeSync;
use App\Services\ReportSummary;
use App\Support\MonthWeeks;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportSummaryController extends Controller
{
    /**
     * The weekly section (a week, or the month to date) above the daily section, for side-by-side comparison.
     */
    public function index(Request $request, ReportSummary $report, PancakeSync $pancake): View
    {
        // Results are on real days (the tracker alone runs on the working date).
        $today = WorkingDate::realToday();

        $filters = $request->validate([
            'period' => ['nullable', Rule::in(['week', 'mtd'])],
            'month' => ['nullable', 'date_format:Y-m'],
            'week' => ['nullable', 'integer', 'min:1', 'max:5'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $mode = $filters['period'] ?? 'week';
        $month = CarbonImmutable::parse(($filters['month'] ?? $today->format('Y-m')).'-01')->min($today->startOfMonth());
        $weeks = MonthWeeks::for($month);
        $day = CarbonImmutable::parse($filters['date'] ?? $today->toDateString())->min($today);

        if ($mode === 'mtd') {
            $end = $month->endOfMonth()->startOfDay()->min($today);
            $period = ['from' => $month, 'to' => $end, 'title' => 'Month to date', 'label' => $month->format('M j').'–'.$end->format('j, Y')];
        } else {
            $number = min((int) ($filters['week'] ?? MonthWeeks::containing($month, $today)), count($weeks));
            $week = $weeks[$number - 1];
            $period = [
                'from' => $week['start'], 'to' => $week['end']->min($today)->max($week['start']), 'number' => $number,
                'title' => ['1st', '2nd', '3rd', '4th', '5th'][$number - 1].' week',
                'label' => $week['start']->format('F j').'–'.$week['end']->format('j, Y'),
            ];
        }

        $cras = LeadGenerator::cras();

        // Keep today's Pancake numbers fresh when today is on screen; it runs after the page has been sent.
        $showsToday = $day->equalTo($today) || $today->betweenIncluded($period['from'], $period['to']);
        if ($showsToday && PancakeSync::isStale($today) && ! PancakeSync::isRunning($today)) {
            $pancake->syncLater($today);
        }

        return view('report-summary.index', [
            'mode' => $mode,
            'month' => $month,
            'weeks' => $weeks,
            'period' => $period,
            'day' => $day,
            'today' => $today,
            'periodReport' => $report->for($cras, $period['from'], $period['to']),
            'dayReport' => $report->for($cras, $day, $day),
            'missingAccounts' => $cras->filter(fn (User $cra) => ! $cra->pancake_name)->values(),
            'lastSync' => PancakeSync::lastSync($today),
        ]);
    }
}
