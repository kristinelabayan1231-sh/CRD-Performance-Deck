<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\CustomerChurn;
use App\Services\CustomerDatabase;
use App\Services\LeadGenerator;
use App\Services\LogisticsRetention;
use App\Services\PancakeSync;
use App\Services\SalesGoalProgress;
use App\Services\SegmentationStats;
use App\Support\DashboardRange;
use App\Support\DashboardVersion;
use App\Support\WorkingDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LeadGenerator $generator, SegmentationStats $stats, SalesGoalProgress $goals, PancakeSync $pancake, LogisticsRetention $logistics, CustomerChurn $churn, CustomerDatabase $customerDatabase): View
    {
        $user = $request->user();
        $realToday = WorkingDate::realToday();

        // One month (to date by default) or From–To range for the whole dashboard. Results and
        // logistics use it as picked; the Segmentation panel uses the paired lead days (Settings → Working Date).
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.$realToday->format('Y-m')],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$realToday->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'The "to" date must be on or after the "from" date.',
        ]);
        $range = DashboardRange::fromFilters($filters, $realToday);
        $leadFrom = WorkingDate::leadDayFor($range->from);
        $leadTo = WorkingDate::leadDayFor($range->to);

        $segmentation = null;
        $logisticsPeriod = null;
        $logisticsFetchedAt = null;

        if ($user->can('segmentation.view')) {
            // Same freshness rule as the tracker, after the page is sent.
            $generator->syncLater(Lead::today());

            // Supervisors see every CRA; a CRA sees only their own numbers.
            $cras = $user->can('segmentation.view_all') ? LeadGenerator::cras() : collect([$user]);
            $segmentation = $stats->range($cras, $leadFrom, $leadTo);

            // Company-wide retention from the logistics report; the lead sync above usually just refreshed it.
            $logistics->ensureFresh();
            $logisticsPeriod = LogisticsRetention::range($range->from, $range->to, $range->label());
            $logisticsFetchedAt = LogisticsRetention::fetchedAt();
        }

        $results = null;
        $churnRate = null;
        $customers = null;

        if ($user->can('conversion.view')) {
            // Today's sales come from Pancake; refresh them after the page is sent when over ten minutes old.
            if (PancakeSync::isStale($realToday) && ! PancakeSync::isRunning($realToday)) {
                $pancake->syncLater($realToday);
            }

            $cras = $user->can('conversion.view_all') ? LeadGenerator::cras() : collect([$user]);
            $results = $goals->for($cras, $range);
            // Company-wide; a few thousand deliveries to look through, so kept for a few minutes (as the Customer Database's counts).
            $churnRate = Cache::remember("dashboard.churn.v3.{$range->from->toDateString()}.{$range->to->toDateString()}", now()->addMinutes(CustomerDatabase::CACHE_MINUTES),
                fn () => $churn->for($range->from, $range->to));

            // Retained and Repeat Customers as in the Customer Database (delivered in the dates picked), kept for a few minutes.
            if ($user->can('customers.view')) {
                $customers = $customerDatabase->cachedCounts(['from' => $range->from, 'to' => $range->to, 'search' => null]);
            }
        }

        // Managers/supervisors: FSD leads whose quantity Pancake didn't have (qty 1 assumed).
        $qtyUnknown = $user->can('segmentation.view_all')
            ? Lead::where('qty_unknown', true)->whereDate('est_out_of_stock_date', '<=', Lead::today())
                ->with('assignee')->orderByDesc('est_out_of_stock_date')->orderBy('customer_name')->get()
            : collect();

        return view('dashboard', [
            'qtyUnknown' => $qtyUnknown, 'segmentation' => $segmentation, 'results' => $results, 'churn' => $churnRate, 'customers' => $customers,
            'logistics' => $user->can('segmentation.view'), 'logisticsPeriod' => $logisticsPeriod, 'logisticsFetchedAt' => $logisticsFetchedAt,
            'range' => $range, 'filters' => $filters, 'leadFrom' => $leadFrom, 'leadTo' => $leadTo, 'realToday' => $realToday,
            'version' => DashboardVersion::current(), 'pancakeSyncedAt' => PancakeSync::lastSync($realToday),
        ]);
    }
}
