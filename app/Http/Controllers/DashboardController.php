<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\ConversionBreakdown;
use App\Services\LeadGenerator;
use App\Services\LogisticsRetention;
use App\Services\PancakeSync;
use App\Services\SalesGoalProgress;
use App\Services\SegmentationStats;
use App\Support\DashboardVersion;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LeadGenerator $generator, SegmentationStats $stats, SalesGoalProgress $goals, PancakeSync $pancake, ConversionBreakdown $breakdown, LogisticsRetention $logistics): View
    {
        $user = $request->user();
        $realToday = WorkingDate::realToday();

        // One date and one period for the whole dashboard. Results and logistics use the
        // date as picked; the Segmentation panel uses its paired lead day (Settings → Working Date).
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$realToday->toDateString()],
            'period' => ['nullable', Rule::in(['today', 'week', 'month'])],
        ]);
        $day = isset($filters['date']) ? CarbonImmutable::parse($filters['date']) : $realToday;
        $period = $filters['period'] ?? 'today';
        $leadDay = WorkingDate::leadDayFor($day);

        $segmentation = null;
        $logisticsPeriods = null;
        $logisticsFetchedAt = null;

        if ($user->can('segmentation.view')) {
            // Same freshness rule as the tracker, after the page is sent.
            $generator->syncLater(Lead::today());

            // Supervisors see every CRA; a CRA sees only their own numbers.
            $cras = $user->can('segmentation.view_all') ? LeadGenerator::cras() : collect([$user]);
            $segmentation = $stats->periods($cras, $leadDay);

            // Company-wide retention from the logistics report; the lead sync above usually just refreshed it.
            $logistics->ensureFresh();
            $logisticsPeriods = LogisticsRetention::periods($day);
            $logisticsFetchedAt = LogisticsRetention::fetchedAt();
        }

        $salesGoals = null;
        $conversion = null;

        if ($user->can('conversion.view')) {
            // Today's sales come from Pancake; refresh them after the page is sent when over ten minutes old.
            if (PancakeSync::isStale($realToday) && ! PancakeSync::isRunning($realToday)) {
                $pancake->syncLater($realToday);
            }

            $cras = $user->can('conversion.view_all') ? LeadGenerator::cras() : collect([$user]);
            $salesGoals = $goals->for($cras, $day, $period);
            $conversion = $breakdown->periods($cras, $day);
        }

        // Managers/supervisors: FSD leads whose quantity Pancake didn't have (qty 1 assumed).
        $qtyUnknown = $user->can('segmentation.view_all')
            ? Lead::where('qty_unknown', true)->whereDate('est_out_of_stock_date', '<=', Lead::today())
                ->with('assignee')->orderByDesc('est_out_of_stock_date')->orderBy('customer_name')->get()
            : collect();

        return view('dashboard', [
            'qtyUnknown' => $qtyUnknown, 'segmentation' => $segmentation, 'salesGoals' => $salesGoals, 'conversion' => $conversion,
            'logistics' => $user->can('segmentation.view'), 'logisticsPeriods' => $logisticsPeriods, 'logisticsFetchedAt' => $logisticsFetchedAt,
            'day' => $day, 'period' => $period, 'leadDay' => $leadDay, 'realToday' => $realToday,
            'version' => DashboardVersion::current(), 'pancakeSyncedAt' => PancakeSync::lastSync($realToday),
        ]);
    }
}
