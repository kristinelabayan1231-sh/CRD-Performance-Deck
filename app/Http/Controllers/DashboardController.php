<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\CancelledOrders;
use App\Services\CustomerChurn;
use App\Services\LeadGenerator;
use App\Services\LogisticsRetention;
use App\Services\PancakeSync;
use App\Services\SalesGoalProgress;
use App\Services\SegmentationStats;
use App\Support\DashboardRange;
use App\Support\DashboardVersion;
use App\Support\SalesGoals;
use App\Support\WorkingDate;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LeadGenerator $generator, SegmentationStats $stats, SalesGoalProgress $goals, PancakeSync $pancake, LogisticsRetention $logistics, CustomerChurn $churn, CancelledOrders $cancelledOrders): View
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
            // Orders the CRD accounts sold that were canceled or are waiting for goods (opens Cancelled / Waiting Orders):
            // supervisors get every account in Settings → Pancake Accounts, a CRA their own.
            $everyCrdAccount = $user->can('conversion.view_all');
            $results['cancelled'] = $cancelledOrders->totals($cras, $range->from, $range->to, $everyCrdAccount);
            $results['cancelled']['days'] = $cancelledOrders->perDay($cras, $range->from, $range->to, everyCrdAccount: $everyCrdAccount);
            // Company-wide; tens of thousands of deliveries to look through, so kept for a few minutes (as the Customer
            // Database's counts). This month's is worked out ahead every 2 minutes by customers:warm-cache.
            $churnRate = $churn->cached($range->from, $range->to);

            // Retained and Repeat Customers from the Segmentation Tracker: Repeat Purchase = Yes bought in the dates picked,
            // FSD Leads = Retained out of the FSD leads, CRD Leads = Repeat out of the CRD leads, on the paired lead days.
            if ($user->can('segmentation.view')) {
                $trackerCras = $user->can('segmentation.view_all') ? LeadGenerator::cras() : collect([$user]);
                $customers = $stats->customers($trackerCras, $range->from, $range->to, $leadFrom, $leadTo);
                // Monthly goals (Settings → Sales Goals), as a percent of those leads.
                $customers['goals'] = ['retained' => SalesGoals::retainedRate(), 'repeat' => SalesGoals::repeatRate()];
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
