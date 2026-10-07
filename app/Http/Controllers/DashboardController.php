<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\ConversionBreakdown;
use App\Services\LeadGenerator;
use App\Services\LogisticsRetention;
use App\Services\PancakeSync;
use App\Services\SalesGoalProgress;
use App\Services\SegmentationStats;
use App\Support\WorkingDate;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LeadGenerator $generator, SegmentationStats $stats, SalesGoalProgress $goals, PancakeSync $pancake, ConversionBreakdown $breakdown, LogisticsRetention $logistics): View
    {
        $user = $request->user();
        $segmentation = null;
        $logisticsPeriods = null;
        $logisticsFetchedAt = null;

        if ($user->can('segmentation.view')) {
            // Same freshness rule as the tracker, after the page is sent.
            $generator->syncLater(Lead::today());

            // Supervisors see every CRA; a CRA sees only their own numbers.
            $cras = $user->can('segmentation.view_all') ? LeadGenerator::cras() : collect([$user]);
            $segmentation = $stats->periods($cras);

            // Company-wide retention from the logistics report; the lead sync above usually just refreshed it.
            $logistics->ensureFresh();
            $logisticsPeriods = LogisticsRetention::periods(WorkingDate::realToday());
            $logisticsFetchedAt = LogisticsRetention::fetchedAt();
        }

        $salesGoals = null;
        $conversion = null;

        if ($user->can('conversion.view')) {
            // Today's sales come from Pancake; refresh them after the page is sent when over an hour old.
            // Sales and conversion are on real days; only the Segmentation panel uses the working date.
            $realToday = WorkingDate::realToday();
            if (PancakeSync::isStale($realToday) && ! PancakeSync::isRunning($realToday)) {
                $pancake->syncLater($realToday);
            }

            $cras = $user->can('conversion.view_all') ? LeadGenerator::cras() : collect([$user]);
            $salesGoals = $goals->for($cras, $realToday);
            $conversion = $breakdown->periods($cras, $realToday);
        }

        // Managers/supervisors: FSD leads whose quantity Pancake didn't have (qty 1 assumed).
        $qtyUnknown = $user->can('segmentation.view_all')
            ? Lead::where('qty_unknown', true)->whereDate('est_out_of_stock_date', '<=', Lead::today())
                ->with('assignee')->orderByDesc('est_out_of_stock_date')->orderBy('customer_name')->get()
            : collect();

        return view('dashboard', ['qtyUnknown' => $qtyUnknown, 'segmentation' => $segmentation, 'salesGoals' => $salesGoals, 'conversion' => $conversion,
            'logistics' => $user->can('segmentation.view'), 'logisticsPeriods' => $logisticsPeriods, 'logisticsFetchedAt' => $logisticsFetchedAt]);
    }
}
