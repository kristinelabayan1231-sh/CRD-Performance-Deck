<?php

namespace App\Http\Controllers;

use App\Services\PosReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PerformanceDeckController extends Controller
{
    public function index(Request $request, PosReportService $posReport): View
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'seller' => ['nullable', 'string'],
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->toDateString();
        $selectedSeller = $validated['seller'] ?? null;

        $report = [];
        $sellers = [];
        $productBreakdown = [];
        $regionBreakdown = [];
        $statusBreakdown = [];
        $productTrend = [];
        $regionTrend = [];
        $statusTrend = [];
        $sellerTrend = [];
        $days = [];
        $isComparisonMode = false;
        $error = null;

        try {
            $rows = $posReport->loadSellerRows();
            $sellers = $posReport->sellerNames($rows);

            if ($selectedSeller !== null && ! in_array($selectedSeller, $sellers, true)) {
                $selectedSeller = null;
            }

            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);

            if ($end->lt($start)) {
                [$start, $end] = [$end, $start];
            }

            $days = [];
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $days[] = $cursor->copy();
                $cursor->addDay();
            }

            // A short range (2–3 days) switches Department Overview and
            // Seller Performance into a day-by-day comparison view instead
            // of blending everything into one combined total.
            $isComparisonMode = count($days) >= 2 && count($days) <= 3;

            $filteredRows = $posReport->filterRows($rows, $start, $end, $selectedSeller);
            $report = $posReport->buildSellerReport($filteredRows);

            // Breakdowns are the department-wide picture for the date range —
            // they ignore the seller dropdown on purpose.
            $dateFilteredRows = $posReport->filterRows($rows, $start, $end);

            if ($isComparisonMode) {
                $productTrend = $posReport->aggregateByDay($dateFilteredRows, $days, 'PRODUCT NAME');
                $regionTrend = $posReport->aggregateByDay($dateFilteredRows, $days, 'By region');
                $statusTrend = $posReport->aggregateByDay($dateFilteredRows, $days, 'Status');
                $sellerTrend = $posReport->aggregateByDay($filteredRows, $days, 'Assigning seller');
            } else {
                $productBreakdown = $posReport->aggregateBy($dateFilteredRows, 'PRODUCT NAME');
                $regionBreakdown = $posReport->aggregateBy($dateFilteredRows, 'By region');
                $statusBreakdown = $posReport->aggregateBy($dateFilteredRows, 'Status');
            }
        } catch (\Throwable $e) {
            $error = 'Could not load POS data: '.$e->getMessage();
        }

        $totals = [
            'sales_value' => 0.0,
            'parcel_qty' => 0,
            'product_cost' => 0.0,
        ];

        foreach ($report as $products) {
            foreach ($products as $product) {
                $totals['sales_value'] += $product['sales_value'];
                $totals['parcel_qty'] += $product['parcel_qty'];
                $totals['product_cost'] += $product['product_cost'];
            }
        }

        // Per-day KPI totals for the comparison sparkline cards — summed
        // across sellers (or the single selected seller) at each day index.
        $kpiByDay = [];
        if ($isComparisonMode) {
            $kpiByDay = array_fill(0, count($days), ['sales_value' => 0.0, 'parcel_qty' => 0, 'product_cost' => 0.0]);

            foreach ($sellerTrend as $series) {
                foreach ($series['points'] as $i => $point) {
                    $kpiByDay[$i]['sales_value'] += $point['sales_value'];
                    $kpiByDay[$i]['parcel_qty'] += $point['parcel_qty'];
                    $kpiByDay[$i]['product_cost'] += $point['product_cost'];
                }
            }
        }

        return view('deck', [
            'report' => $report,
            'sellers' => $sellers,
            'selectedSeller' => $selectedSeller,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'totals' => $totals,
            'productBreakdown' => $productBreakdown,
            'regionBreakdown' => $regionBreakdown,
            'statusBreakdown' => $statusBreakdown,
            'isComparisonMode' => $isComparisonMode,
            'days' => $days,
            'productTrend' => $productTrend,
            'regionTrend' => $regionTrend,
            'statusTrend' => $statusTrend,
            'sellerTrend' => $sellerTrend,
            'kpiByDay' => $kpiByDay,
            'error' => $error,
        ]);
    }
}
