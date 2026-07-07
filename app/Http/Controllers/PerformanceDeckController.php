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
        $error = null;

        try {
            $rows = $posReport->loadSellerRows();
            $sellers = $posReport->sellerNames($rows);

            if ($selectedSeller !== null && ! in_array($selectedSeller, $sellers, true)) {
                $selectedSeller = null;
            }

            $filteredRows = $posReport->filterRows(
                $rows,
                Carbon::parse($startDate),
                Carbon::parse($endDate),
                $selectedSeller,
            );

            $report = $posReport->buildSellerReport($filteredRows);
            $productBreakdown = $posReport->aggregateBy($filteredRows, 'PRODUCT NAME');
            $regionBreakdown = $posReport->aggregateBy($filteredRows, 'By region');
            $statusBreakdown = $posReport->aggregateBy($filteredRows, 'Status');
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
            'error' => $error,
        ]);
    }
}
