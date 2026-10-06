<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\ConversionBreakdown;
use App\Services\LeadGenerator;
use App\Services\LogisticsRetention;
use App\Services\PancakeSync;
use App\Services\SalesGoalProgress;
use App\Services\SegmentationStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LeadGenerator $generator, SegmentationStats $stats, SalesGoalProgress $goals, PancakeSync $pancake, ConversionBreakdown $breakdown, LogisticsRetention $logistics): View
    {
        $user = $request->user();
        $segmentation = null;
        $logisticsPeriods = null;
        $logisticsFetchedAt = null;

        if ($user->can('segmentation.view')) {
            // Same freshness rule as the tracker: sync today if it's over an hour old.
            try {
                $generator->syncIfStale(Lead::today());
            } catch (Throwable $e) {
                Log::warning('Dashboard lead sync failed', ['message' => $e->getMessage()]);
            }

            // Supervisors see every CRA; a CRA sees only their own numbers.
            $cras = $user->can('segmentation.view_all') ? LeadGenerator::cras() : collect([$user]);
            $segmentation = $stats->periods($cras);

            // Company-wide retention from the logistics report; the lead sync above usually just refreshed it.
            $logistics->ensureFresh();
            $logisticsPeriods = LogisticsRetention::periods(Lead::today());
            $logisticsFetchedAt = LogisticsRetention::fetchedAt();
        }

        $salesGoals = null;
        $conversion = null;

        if ($user->can('conversion.view')) {
            // Today's sales come from Pancake; refresh them after the page is sent when over an hour old.
            if (PancakeSync::isStale(Lead::today()) && ! PancakeSync::isRunning(Lead::today())) {
                $pancake->syncLater(Lead::today());
            }

            $cras = $user->can('conversion.view_all') ? LeadGenerator::cras() : collect([$user]);
            $salesGoals = $goals->for($cras, Lead::today());
            $conversion = $breakdown->periods($cras, Lead::today());
        }

        return view('dashboard', ['segmentation' => $segmentation, 'salesGoals' => $salesGoals, 'conversion' => $conversion,
            'logistics' => $user->can('segmentation.view'), 'logisticsPeriods' => $logisticsPeriods, 'logisticsFetchedAt' => $logisticsFetchedAt]);
    }
}
