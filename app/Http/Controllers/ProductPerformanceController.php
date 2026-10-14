<?php

namespace App\Http\Controllers;

use App\Models\PancakeEngagement;
use App\Services\ProductPerformance;
use App\Support\OrderPeriod;
use App\Support\PancakeAccounts;
use App\Support\WorkingDate;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Product Performance: every CRD account's (Settings → Pancake Accounts) Pancake POS orders per product for a day,
 * a week, a month, the last 3 months or a From–To range, against the period of the same length before it.
 */
class ProductPerformanceController extends Controller
{
    public function index(Request $request, ProductPerformance $productPerformance): View
    {
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'period' => ['nullable', 'in:day,week,month,quarter,range'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.$today->format('Y-m')],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $period = $filters['period'] ?? 'month';
        [$from, $to] = OrderPeriod::resolve($period, $filters, $today);
        $sellers = collect(PancakeAccounts::crd())->mapWithKeys(fn (string $name) => [PancakeEngagement::staffKey($name) => $name])->all();

        return view('product-performance.index', [
            ...$productPerformance->build($sellers, $from, $to, $today),
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'label' => OrderPeriod::label($from, $to),
            // A card opens the product in Order Database → Product Report, which shows every CRD account only to these.
            'canOpenReport' => $request->user()->can('order_database.view_all'),
        ]);
    }
}
