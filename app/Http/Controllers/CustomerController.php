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
        ];

        if (! $posCredential) {
            return view('customers.index', $base);
        }

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

        // "day" is a small enough scan (a few hundred orders) to compute
        // live on every request — unlike week/month/year, which take from
        // several seconds to several minutes and are only ever served from
        // the pancake:sync-customer-dashboard cache.
        if ($period === 'day') {
            $anchor = Carbon::parse($selectedDate);
            $pageNames = \App\Models\FacebookPage::pluck('page_name', 'page_id')->all();
            $payload = $pancake->summarizeCustomerActivityInRange(
                $posCredential->shop_id, $posCredential->api_key, $anchor->toDateString(), $anchor->toDateString(), $pageNames, 3000,
            );

            $sorted = $this->sortCustomers(collect($payload['customers'] ?? []), $sort);

            return view('customers.index', array_merge($base, [
                'mode' => 'range',
                'customers' => $sorted->forPage($page, $this->pageSize)->values(),
                'page' => $page,
                'totalPages' => max(1, (int) ceil($sorted->count() / $this->pageSize)),
                'totalEntries' => $sorted->count(),
                'dashboard' => $payload,
                'periodLabel' => DashboardPeriod::label('day', $anchor),
                'computedAt' => now(),
            ]));
        }

        $anchor = Carbon::now();
        $snapshot = CustomerDashboardSnapshot::find($period, DashboardPeriod::key($period, $anchor));

        if (! $snapshot) {
            return view('customers.index', array_merge($base, [
                'mode' => 'pending',
                'periodLabel' => DashboardPeriod::label($period, $anchor),
            ]));
        }

        $payload = $snapshot->payload;
        $sorted = $this->sortCustomers(collect($payload['customers'] ?? []), $sort);

        return view('customers.index', array_merge($base, [
            'mode' => 'range',
            'customers' => $sorted->forPage($page, $this->pageSize)->values(),
            'page' => $page,
            'totalPages' => max(1, (int) ceil($sorted->count() / $this->pageSize)),
            'totalEntries' => $sorted->count(),
            'dashboard' => $payload,
            'periodLabel' => DashboardPeriod::label($period, $anchor),
            'computedAt' => $snapshot->computed_at,
        ]));
    }

    protected function sortCustomers($customers, string $sort)
    {
        return (match ($sort) {
            'ltv_asc' => $customers->sortBy(fn ($c) => $c['purchased_amount'] ?? 0),
            'orders_desc' => $customers->sortByDesc(fn ($c) => $c['order_count'] ?? 0),
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
