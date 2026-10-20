<?php

namespace App\Http\Controllers;

use App\Models\PancakeEngagement;
use App\Models\Product;
use App\Models\User;
use App\Services\CraPerformance;
use App\Services\ProductReport;
use App\Support\OrderPeriod;
use App\Support\PancakeAccounts;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Order Database: Pancake POS orders sold by the CRD accounts (Settings → Pancake Accounts).
 * CRA's Performance tab: per seller account, orders per status, qty and total sales, opening to its products,
 * for a day, a week (Monday–Sunday), a month or a From–To range. A CRA sees their own account.
 * Product Report tab: the same orders per product (pieces, sales, cost and delivery rates), opening to its sellers.
 */
class OrderDatabaseController extends Controller
{
    public function craPerformance(Request $request, CraPerformance $craPerformance): View
    {
        $scope = $this->scope($request);
        $rows = $craPerformance->rows($scope['shown'], $scope['from'], $scope['to']);
        $total = CraPerformance::total($rows);

        return view('order-database.cra-performance', [
            ...$scope,
            'rows' => $rows,
            'total' => $total,
            'statuses' => self::statuses($total['statuses']),
            'open' => ! $scope['canViewAll'] || filled($scope['filters']['seller'] ?? null),
        ]);
    }

    public function productReport(Request $request, ProductReport $productReport): View
    {
        $scope = $this->scope($request, ['product' => ['nullable', 'string', 'max:255']]);
        $rows = $productReport->rows($scope['shown'], $scope['from'], $scope['to'], $scope['filters']['product'] ?? null);
        $total = ProductReport::total($rows);

        return view('order-database.product-report', [
            ...$scope,
            'rows' => $rows,
            'total' => $total,
            'statuses' => self::statuses($total['statuses']),
            'products' => Product::orderBy('name')->pluck('name')->push(CraPerformance::OTHER_PRODUCTS),
        ]);
    }

    /**
     * The dates and seller accounts both tabs show: a day, a week (Monday–Sunday), a month or a From–To range, and
     * every CRD account, the CRA filtered on, or a CRA's own account.
     *
     * @param  array<string, list<string>>  $rules  the tab's own filters
     * @return array{shown: array<string, string>, period: string, from: CarbonImmutable, to: CarbonImmutable, label: string, today: CarbonImmutable, filters: array<string, string>, accounts: Collection<string, string>, canViewAll: bool}
     */
    private function scope(Request $request, array $rules = []): array
    {
        $user = $request->user();
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'period' => ['nullable', 'in:day,week,month,range'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.$today->format('Y-m')],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'seller' => ['nullable', 'string', 'max:255'],
            ...$rules,
        ]);

        $period = $filters['period'] ?? 'month';
        [$from, $to] = OrderPeriod::resolve($period, $filters, $today);

        // Staff key => the name as entered in Settings, a CRA's account in the CRA order (User::scopeInCraOrder()), then
        // the other accounts as entered; a CRA without "every CRA" sees only their own account.
        $craRank = PancakeAccounts::craUsers()->values()->mapWithKeys(fn (User $cra, int $rank) => [PancakeEngagement::staffKey($cra->pancake_name) => $rank]);
        $accounts = collect(PancakeAccounts::crd())
            ->mapWithKeys(fn (string $name) => [PancakeEngagement::staffKey($name) => $name])
            ->sortBy(fn (string $name, string $key) => $craRank->get($key, PHP_INT_MAX), SORT_NUMERIC);
        $canViewAll = $user->can('order_database.view_all');
        $ownKey = $user->pancake_name ? PancakeEngagement::staffKey($user->pancake_name) : null;
        $seller = $canViewAll ? ($filters['seller'] ?? null) : null;

        return [
            'shown' => match (true) {
                ! $canViewAll => $ownKey ? collect(PancakeAccounts::keysOf($user))->mapWithKeys(fn (string $key) => [$key => $accounts[$key] ?? ($key === $ownKey ? $user->pancake_name : $key)])->all() : [],
                filled($seller) => $accounts->only([$seller])->all(),
                default => $accounts->all(),
            },
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'label' => OrderPeriod::label($from, $to),
            'today' => $today,
            'filters' => $filters,
            'accounts' => $accounts,
            'canViewAll' => $canViewAll,
        ];
    }

    /**
     * Only the statuses these orders have, in the order Pancake moves through them.
     *
     * @param  array<int, int>  $counts
     * @return Collection<int, string>
     */
    private static function statuses(array $counts): Collection
    {
        $labels = collect(config('customers.pos_statuses'))->map(fn (array $status) => $status[0]);

        return $labels->only(array_keys($counts))->union(
            collect($counts)->keys()->diff($labels->keys())
                ->mapWithKeys(fn (int $status) => [$status => $status === -1 ? 'No status' : "Status {$status}"])
        );
    }
}
