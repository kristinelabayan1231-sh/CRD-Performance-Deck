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
            'seller' => ['nullable', 'array'],
            'seller.*' => ['string'],
        ]);

        $startDate = $validated['start_date'] ?? now()->toDateString();
        $endDate = $validated['end_date'] ?? now()->toDateString();
        
        $selectedSellers = $validated['seller'] ?? [];

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

            $selectedSellers = array_values(array_intersect($selectedSellers, $sellers));

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

            $isComparisonMode = count($days) >= 2 && count($days) <= 3;

            $filteredRows = $posReport->filterRows($rows, $start, $end, $selectedSellers ?: null);
            $report = $posReport->buildSellerReport($filteredRows);

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

        // Per-seller totals — used for the multi-seller comparison cards
        // when 2+ sellers are picked outside day-comparison mode.
        $sellerTotals = [];

        foreach ($report as $sellerName => $products) {
            $sellerSales = array_sum(array_column($products, 'sales_value'));
            $sellerQty = array_sum(array_column($products, 'parcel_qty'));
            $sellerCost = array_sum(array_column($products, 'product_cost'));

            $sellerTotals[$sellerName] = [
                'sales_value' => $sellerSales,
                'parcel_qty' => $sellerQty,
                'product_cost' => $sellerCost,
            ];

            $totals['sales_value'] += $sellerSales;
            $totals['parcel_qty'] += $sellerQty;
            $totals['product_cost'] += $sellerCost;
        }

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
            'selectedSellers' => $selectedSellers,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'totals' => $totals,
            'sellerTotals' => $sellerTotals,
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