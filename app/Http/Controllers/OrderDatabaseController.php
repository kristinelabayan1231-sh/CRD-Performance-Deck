<?php

namespace App\Http\Controllers;

use App\Models\PancakeEngagement;
use App\Services\CraPerformance;
use App\Support\DashboardRange;
use App\Support\PancakeAccounts;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Order Database: Pancake POS orders sold by the CRD accounts (Settings → Pancake Accounts).
 * CRA's Performance tab: per seller account, orders per status, qty and total sales, opening to its products,
 * for a day, a week (Monday–Sunday), a month or a From–To range. A CRA sees their own account.
 */
class OrderDatabaseController extends Controller
{
    public function craPerformance(Request $request, CraPerformance $craPerformance): View
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
        ]);

        $period = $filters['period'] ?? 'month';
        $anchor = CarbonImmutable::parse($filters['date'] ?? $filters['from'] ?? $today->toDateString())->min($today);
        [$from, $to] = match ($period) {
            'day' => [$anchor, $anchor],
            'week' => [$anchor->startOfWeek(CarbonImmutable::MONDAY), $anchor->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()->min($today)],
            'month' => [$month = CarbonImmutable::parse(($filters['month'] ?? $anchor->format('Y-m')).'-01'), $month->endOfMonth()->startOfDay()->min($today)],
            'range' => [($range = DashboardRange::fromFilters($filters, $today))->from, $range->to],
        };

        // Staff key => the name as entered in Settings; a CRA without "every CRA" sees only their own account.
        $accounts = collect(PancakeAccounts::crd())->mapWithKeys(fn (string $name) => [PancakeEngagement::staffKey($name) => $name]);
        $canViewAll = $user->can('order_database.view_all');
        $ownKey = $user->pancake_name ? PancakeEngagement::staffKey($user->pancake_name) : null;
        $seller = $canViewAll ? ($filters['seller'] ?? null) : null;
        $shown = match (true) {
            ! $canViewAll => $ownKey ? [$ownKey => $accounts[$ownKey] ?? $user->pancake_name] : [],
            filled($seller) => $accounts->only([$seller])->all(),
            default => $accounts->all(),
        };

        $rows = $craPerformance->rows($shown, $from, $to);
        $total = CraPerformance::total($rows);
        $labels = collect(config('customers.pos_statuses'))->map(fn (array $status) => $status[0]);

        return view('order-database.cra-performance', [
            'rows' => $rows,
            'total' => $total,
            // Only the statuses these orders have, in the order Pancake moves through them.
            'statuses' => $labels->only(array_keys($total['statuses']))->union(
                collect($total['statuses'])->keys()->diff($labels->keys())
                    ->mapWithKeys(fn (int $status) => [$status => $status === -1 ? 'No status' : "Status {$status}"])
            ),
            'open' => filled($seller) || ! $canViewAll,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'label' => (new DashboardRange($from, $to, $from->startOfMonth(), true))->label(),
            'today' => $today,
            'filters' => $filters,
            'accounts' => $accounts,
            'canViewAll' => $canViewAll,
        ]);
    }
}
