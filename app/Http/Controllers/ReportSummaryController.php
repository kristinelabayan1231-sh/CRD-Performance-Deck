<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\PancakeSync;
use App\Services\ReportSummary;
use App\Support\MonthWeeks;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportSummaryController extends Controller
{
    /** The most days the daily section shows side by side. */
    public const MAX_RANGE_DAYS = 31;

    /**
     * The weekly section (a week, or the month to date) above the daily section, for side-by-side comparison. The daily
     * section shows one day, or with a To date a range of days, each day's table next to the one before (up to MAX_RANGE_DAYS).
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
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $mode = $filters['period'] ?? 'week';
        $month = CarbonImmutable::parse(($filters['month'] ?? $today->format('Y-m')).'-01')->min($today->startOfMonth());
        $weeks = MonthWeeks::for($month);
        $day = CarbonImmutable::parse($filters['date'] ?? $today->toDateString())->min($today);
        // A To date before the day swaps them; a range longer than MAX_RANGE_DAYS stops there.
        $rangeEnd = isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->min($today) : $day;
        [$day, $rangeEnd] = $rangeEnd->lessThan($day) ? [$rangeEnd, $day] : [$day, $rangeEnd];
        $isCapped = $day->diffInDays($rangeEnd) >= self::MAX_RANGE_DAYS;
        $rangeEnd = $rangeEnd->min($day->addDays(self::MAX_RANGE_DAYS - 1));

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
        $showsToday = $rangeEnd->equalTo($today) || $today->betweenIncluded($period['from'], $period['to']);
        if ($showsToday && PancakeSync::isStale($today) && ! PancakeSync::isRunning($today)) {
            $pancake->syncLater($today);
        }

        $dayReports = [];
        foreach (CarbonPeriod::create($day, $rangeEnd) as $date) {
            $date = CarbonImmutable::instance($date)->startOfDay();
            $dayReports[] = ['day' => $date, 'report' => $report->for($cras, $date, $date)];
        }

        return view('report-summary.index', [
            'mode' => $mode,
            'month' => $month,
            'weeks' => $weeks,
            'period' => $period,
            'day' => $day,
            'today' => $today,
            'periodReport' => $report->for($cras, $period['from'], $period['to']),
            'rangeEnd' => $rangeEnd,
            'isRange' => ! $rangeEnd->equalTo($day),
            'isCapped' => $isCapped,
            'dayReport' => $dayReports[0]['report'],
            'dayReports' => $dayReports,
            'missingAccounts' => $cras->filter(fn (User $cra) => ! $cra->pancake_name)->values(),
            'lastSync' => PancakeSync::lastSync($today),
        ]);
    }
}
