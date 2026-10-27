<?php

namespace App\Http\Controllers;

use App\Services\PancakeSync;
use App\Services\ProductTargetMarket;
use App\Support\OrderPeriod;
use App\Support\WorkingDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Product Target Market: the ages of each product's customers (noted in their POS orders, "Age: 17") against the product's
 * target bracket in Settings → Age Bracket, for a month, the last 3 months or a From–To range.
 */
class ProductTargetMarketController extends Controller
{
    public function index(Request $request, ProductTargetMarket $targetMarket): View
    {
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'period' => ['nullable', 'in:month,quarter,range'],
            'month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.$today->format('Y-m')],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $period = $filters['period'] ?? 'month';
        [$from, $to] = OrderPeriod::resolve($period, $filters, $today);

        return view('product-target-market.index', [
            ...$targetMarket->build($from, $to),
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'label' => OrderPeriod::label($from, $to),
            'agesFetchedAt' => PancakeSync::agesFetchedAt(),
            'agesRunning' => PancakeSync::agesRunning(),
            'canEditTargets' => $request->user()->can('product_consumption.view'),
        ]);
    }

    /**
     * Fetch the last 3 months' orders from Pancake again for the ages noted on them, after the page is sent.
     */
    public function fetchAges(PancakeSync $sync): RedirectResponse
    {
        if (PancakeSync::agesRunning()) {
            return back()->with('status', 'Ages are already being fetched from Pancake.');
        }

        $sync->syncAgesLater(WorkingDate::realToday());

        return back()->with('status', 'Fetching the last 3 months of noted ages from Pancake. This takes a few minutes; refresh the page to see them.');
    }
}
