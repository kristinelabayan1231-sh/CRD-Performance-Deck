<?php

namespace App\Http\Controllers;

use App\Models\PancakeEngagement;
use App\Models\Product;
use App\Services\ProductPerformance;
use App\Support\OrderPeriod;
use App\Support\PancakeAccounts;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Product Performance: every CRD account's (Settings → Pancake Accounts) Pancake POS orders per product for a day,
 * a week, a month, the last 3 months or a From–To range, against the period of the same length before it. The Products
 * tab is the overall; the CRD, TSD and Testing tabs are the same page for only the CRD, TSD upsell or testing products.
 */
class ProductPerformanceController extends Controller
{
    /** A tab's graph of the past days, weeks and months reads a year of orders, so it is kept this long. */
    private const TREND_CACHE_MINUTES = 10;

    public function index(Request $request, ProductPerformance $productPerformance): View
    {
        return $this->page($request, $productPerformance, 'index');
    }

    public function crd(Request $request, ProductPerformance $productPerformance): View
    {
        return $this->page($request, $productPerformance, 'crd');
    }

    public function tsd(Request $request, ProductPerformance $productPerformance): View
    {
        return $this->page($request, $productPerformance, 'tsd');
    }

    public function testing(Request $request, ProductPerformance $productPerformance): View
    {
        return $this->page($request, $productPerformance, 'testing');
    }

    /**
     * A tab's graph of the past days, weeks and months up to ?to, loaded after the page opens.
     */
    public function trend(Request $request, ProductPerformance $productPerformance): JsonResponse
    {
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'tab' => ['required', 'in:index,crd,tsd,testing'],
            'to' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
        ]);

        return response()->json(Cache::remember(
            "product-performance.trend.{$filters['tab']}.{$filters['to']}",
            now()->addMinutes(self::TREND_CACHE_MINUTES),
            fn () => $productPerformance->trend($this->sellers(), CarbonImmutable::parse($filters['to']), $today, $this->tab($filters['tab'])['only']),
        ));
    }

    private function page(Request $request, ProductPerformance $productPerformance, string $tab): View
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
        ['scope' => $scope, 'source' => $scopeSource, 'only' => $only] = $this->tab($tab);

        return view('product-performance.index', [
            ...$productPerformance->build($this->sellers(), $from, $to, $today, $only),
            'tab' => $tab,
            'scope' => $scope,
            'scopeSource' => $scopeSource,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'label' => OrderPeriod::label($from, $to),
            // A card opens the product in Order Database → Product Report, which shows every CRD account only to these.
            'canOpenReport' => $request->user()->can('order_database.view_all'),
        ]);
    }

    /**
     * @return array<string, string> staff key => name, the CRD accounts
     */
    private function sellers(): array
    {
        return collect(PancakeAccounts::crd())->mapWithKeys(fn (string $name) => [PancakeEngagement::staffKey($name) => $name])->all();
    }

    /**
     * What a tab counts: its kind of product, those products and where they are set (for the page's notes), and their
     * names; all null on the overall.
     *
     * @return array{scope: ?string, source: ?string, only: ?list<string>}
     */
    private function tab(string $tab): array
    {
        return match ($tab) {
            'crd' => ['scope' => 'CRD', 'source' => 'CRD products (Settings → Product Consumption)', 'only' => Product::crd()->pluck('name')->all()],
            'tsd' => ['scope' => 'TSD', 'source' => 'TSD upsell products (Settings → Product Consumption)', 'only' => Product::where('is_tsd', true)->pluck('name')->all()],
            'testing' => ['scope' => 'Testing', 'source' => 'testing products (Settings → Testing Products)', 'only' => Product::crd()->where('is_testing', true)->pluck('name')->all()],
            default => ['scope' => null, 'source' => null, 'only' => null],
        };
    }
}
