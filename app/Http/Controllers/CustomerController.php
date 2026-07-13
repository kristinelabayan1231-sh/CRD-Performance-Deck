<?php

namespace App\Http\Controllers;

use App\Models\CustomerDashboardSnapshot;
use App\Models\PosCredential;
use App\Services\PancakeService;
use App\Support\DashboardPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CustomerController extends Controller
{
    protected int $pageSize = 25;

    public function index(Request $request, PancakeService $pancake)
    {
        $validated = $request->validate([
            'seller' => ['nullable', 'array'],
            'seller.*' => ['string'],
        ]);

        $posCredential = PosCredential::current();
        $search = $request->get('q');
        $sort = $request->get('sort', 'ltv_desc');
        $page = max(1, (int) $request->get('page', 1));
        $period = in_array($request->get('period'), ['day', 'week', 'month', 'year']) ? $request->get('period') : 'week';
        $selectedDate = $request->query('date', now()->subDay()->toDateString());

        $base = [
            'mode' => 'none',
            'posCredential' => $posCredential,
            'customers' => [],
            'search' => $search,
            'sort' => $sort,
            'period' => $period,
            'selectedDate' => $selectedDate,
            'page' => 1,
            'totalPages' => 1,
            'totalEntries' => 0,
            'dashboard' => null,
            'periodLabel' => null,
            'computedAt' => null,
            'sellers' => [],
            'selectedSellers' => [],
            'insights' => [],
        ];

        if (! $posCredential) {
            return view('customers.index', $base);
        }

        // The whole tab is scoped to the CRD sellers' POS accounts (the
        // "User" filter in the POS calendar UI). One canonical name can
        // cover several accounts, so filtering fans out to every id whose
        // aliased name matches.
        $crdSellers = $pancake->crdSellers($posCredential->shop_id, $posCredential->api_key);
        $sellerNames = collect($crdSellers)->pluck('name')->unique()->sort()->values()->all();
        $selectedSellers = array_values(array_intersect($validated['seller'] ?? [], $sellerNames));
        $crdSellerIds = collect($crdSellers)->pluck('id')->all();

        $base['sellers'] = $sellerNames;
        $base['selectedSellers'] = $selectedSellers;

        if ($search) {
            $result = $pancake->sortedCustomers($posCredential->shop_id, $posCredential->api_key, $search, $sort, $page, $this->pageSize);

            return view('customers.index', array_merge($base, [
                'mode' => 'search',
                'customers' => $result['data'],
                'page' => $result['page_number'],
                'totalPages' => $result['total_pages'],
                'totalEntries' => $result['total_entries'],
            ]));
        }

        // "day" is a small enough scan (a few hundred orders, far fewer
        // once filtered to CRD sellers) to compute live on every request —
        // unlike week/month/year, which take from several seconds to
        // several minutes and are only ever served from the
        // pancake:sync-customer-dashboard cache.
        if ($period === 'day') {
            $anchor = Carbon::parse($selectedDate);
            $pageNames = \App\Models\FacebookPage::pluck('page_name', 'page_id')->all();
            $summary = $pancake->summarizeCustomerActivityInRange(
                $posCredential->shop_id, $posCredential->api_key, $anchor->toDateString(), $anchor->toDateString(), $pageNames, 3000, $crdSellerIds,
            );

            return $this->renderSlice($pancake, $summary, $selectedSellers, array_merge($base, [
                'page' => $page,
                'periodLabel' => DashboardPeriod::label('day', $anchor),
                'computedAt' => now(),
            ]));
        }

        $anchor = Carbon::now();
        $snapshot = CustomerDashboardSnapshot::find($period, DashboardPeriod::key($period, $anchor));

        // Snapshots written before the CRD scoping shipped lack the
        // per-seller aggregates and can't be sliced — treat them as not
        // computed yet rather than showing shop-wide numbers as CRD data.
        if (! $snapshot || ! isset($snapshot->payload['sellerStats'])) {
            return view('customers.index', array_merge($base, [
                'mode' => 'pending',
                'periodLabel' => DashboardPeriod::label($period, $anchor),
                'building' => \Illuminate\Support\Facades\Cache::has(\App\Console\Commands\SyncCustomerDashboard::BUILD_FLAG),
            ]));
        }

        return $this->renderSlice($pancake, $snapshot->payload, $selectedSellers, array_merge($base, [
            'page' => $page,
            'periodLabel' => DashboardPeriod::label($period, $anchor),
            'computedAt' => $snapshot->computed_at,
        ]));
    }

    /**
     * Kick off pancake:sync-customer-dashboard as a detached background
     * process so the pending screen's "Build now" button works without a
     * shell. The command takes minutes (the year period scans ~15k
     * orders), far beyond any web timeout, so it can't run inline. A
     * cache flag prevents stacking builds and lets the pending screen
     * show an in-progress state; the command clears it when done, with
     * the TTL as a dead-man's switch if the process dies.
     */
    public function buildDashboard()
    {
        $flag = \App\Console\Commands\SyncCustomerDashboard::BUILD_FLAG;

        if (! \Illuminate\Support\Facades\Cache::add($flag, now()->toDateTimeString(), now()->addMinutes(20))) {
            return back()->with('status', 'A build is already running — refresh in a few minutes.');
        }

        $log = storage_path('logs/customer-dashboard-build.log');
        $command = sprintf(
            'nohup %s %s pancake:sync-customer-dashboard >> %s 2>&1 &',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($log),
        );
        exec($command);

        return back()->with('status', 'Build started — the weekly view fills in first (a minute or two); month and year take a few minutes more.');
    }

    protected function renderSlice(PancakeService $pancake, array $summary, array $selectedSellers, array $base)
    {
        $dashboard = $pancake->sliceSummary($summary, $selectedSellers);
        $sorted = $this->sortCustomers($dashboard['customers'], $base['sort']);
        $page = $base['page'];

        return view('customers.index', array_merge($base, [
            'mode' => 'range',
            'customers' => $sorted->forPage($page, $this->pageSize)->values(),
            'totalPages' => max(1, (int) ceil($sorted->count() / $this->pageSize)),
            'totalEntries' => $sorted->count(),
            'dashboard' => $dashboard,
            'insights' => $this->buildInsights($dashboard),
        ]));
    }

    /**
     * Plain-language observations derived from the sliced dashboard — the
     * concentrations and outliers a manager would otherwise have to spot
     * by cross-reading the charts. Each insight is skipped when its data
     * is missing or the denominator is zero.
     */
    protected function buildInsights(array $dashboard): array
    {
        $insights = [];
        $revenue = $dashboard['revenueTotal'];
        $breakdown = $dashboard['sellerBreakdown'];
        $customerCount = $dashboard['customerCount'];

        if ($revenue <= 0 || $breakdown->isEmpty()) {
            return [];
        }

        $peso = fn ($n) => '₱'.number_format($n);

        $leader = $breakdown->first();
        $insights[] = sprintf(
            '%s leads gross sales with %s — %d%% of the period total.',
            $leader['name'], $peso($leader['gross_sales']), round($leader['gross_sales'] / $revenue * 100),
        );

        if ($breakdown->count() > 1) {
            $trailer = $breakdown->last();
            $insights[] = sprintf(
                '%s trails at %s, %s behind the leader — worth reviewing call volume or assigned pages.',
                $trailer['name'], $peso($trailer['gross_sales']), $peso($leader['gross_sales'] - $trailer['gross_sales']),
            );

            $bestAov = $breakdown->sortByDesc('avg_order')->first();
            $overallAov = $dashboard['orderCount'] > 0 ? $revenue / $dashboard['orderCount'] : 0;

            if ($bestAov['avg_order'] > $overallAov) {
                $insights[] = sprintf(
                    '%s closes the biggest baskets — %s per order vs the %s team average.',
                    $bestAov['name'], $peso($bestAov['avg_order']), $peso($overallAov),
                );
            }
        }

        $repeat = $dashboard['customers']->filter(fn ($c) => ($c['order_count'] ?? 0) > 1)->count();

        if ($customerCount > 0) {
            $insights[] = sprintf(
                '%d%% of this period\'s buyers (%s of %s) are repeat customers.',
                round($repeat / $customerCount * 100), number_format($repeat), number_format($customerCount),
            );
        }

        $topPage = $dashboard['customersPerPage']->first();

        if ($topPage && $customerCount > 0) {
            $insights[] = sprintf(
                '%s is the busiest page — %s customers, %d%% of everyone active this period.',
                $topPage['page_name'], number_format($topPage['count']), round($topPage['count'] / $customerCount * 100),
            );
        }

        $topProduct = $dashboard['topProducts']->first();

        if ($topProduct && $topProduct['revenue'] > 0) {
            $insights[] = sprintf(
                '%s is the top earner — %s from %s units sold.',
                $topProduct['name'], $peso($topProduct['revenue']), number_format($topProduct['quantity']),
            );
        }

        $topSpend = $dashboard['topCustomers']->sum(fn ($c) => $c['period_revenue'] ?? 0);

        if ($topSpend > 0) {
            $insights[] = sprintf(
                'The top 5 spenders contributed %s — %d%% of gross sales came from just %d customers.',
                $peso($topSpend), round($topSpend / $revenue * 100), $dashboard['topCustomers']->count(),
            );
        }

        return $insights;
    }

    protected function sortCustomers($customers, string $sort)
    {
        return (match ($sort) {
            'ltv_asc' => $customers->sortBy(fn ($c) => $c['purchased_amount'] ?? 0),
            'orders_desc' => $customers->sortByDesc(fn ($c) => $c['order_count'] ?? 0),
            'period_desc' => $customers->sortByDesc(fn ($c) => $c['period_revenue'] ?? 0),
            default => $customers->sortByDesc(fn ($c) => $c['purchased_amount'] ?? 0),
        })->values();
    }

    public function show(string $customerId, PancakeService $pancake)
    {
        $posCredential = PosCredential::current();
        abort_if(! $posCredential, 404);

        $customer = $pancake->getCustomer($posCredential->shop_id, $posCredential->api_key, $customerId);
        abort_if(! $customer, 404);

        $orders = $pancake->customerOrders($posCredential->shop_id, $posCredential->api_key, $customerId);
        $topProduct = $pancake->topPurchasedProduct($orders);

        return view('customers.show', compact('customer', 'orders', 'topProduct'));
    }
}
