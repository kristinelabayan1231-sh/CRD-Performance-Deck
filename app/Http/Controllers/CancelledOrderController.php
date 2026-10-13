<?php

namespace App\Http\Controllers;

use App\Models\PancakeOrder;
use App\Services\CancelledOrders;
use App\Services\ConversionBreakdown;
use App\Services\LeadGenerator;
use App\Support\DashboardRange;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The cancelled / waiting orders behind the dashboard's tile (no menu link): per day and listed, for a day,
 * a week (Monday–Sunday), a month or a From–To range. Opens with the dashboard's dates. A CRA sees their own.
 */
class CancelledOrderController extends Controller
{
    public function index(Request $request, CancelledOrders $cancelledOrders): View
    {
        $user = $request->user();
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'period' => ['nullable', 'in:day,week,month,range'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.$today->format('Y-m')],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:cancelled,waiting'],
            'cra' => ['nullable', 'integer'],
        ]);

        // From the dashboard: its From–To range, else its month.
        $period = $filters['period'] ?? (filled($filters['from'] ?? null) || filled($filters['to'] ?? null) ? 'range' : 'month');
        $anchor = CarbonImmutable::parse($filters['date'] ?? $filters['from'] ?? $today->toDateString())->min($today);
        [$from, $to] = match ($period) {
            'day' => [$anchor, $anchor],
            'week' => [$anchor->startOfWeek(CarbonImmutable::MONDAY), $anchor->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()->min($today)],
            'month' => [$month = CarbonImmutable::parse(($filters['month'] ?? $anchor->format('Y-m')).'-01'), $month->endOfMonth()->startOfDay()->min($today)],
            'range' => [($range = DashboardRange::fromFilters($filters, $today))->from, $range->to],
        };

        $allCras = LeadGenerator::cras();
        $shown = match (true) {
            ! $user->can('conversion.view_all') => $allCras->where('id', $user->id),
            filled($filters['cra'] ?? null) => $allCras->where('id', (int) $filters['cra']),
            default => $allCras,
        };
        $accounts = ConversionBreakdown::accounts($shown);
        $byId = $allCras->keyBy('id');
        $status = $filters['status'] ?? null;
        // All CRAs shown: before the CRD tags, every CRD account's orders (former CRAs too).
        $everyCrdAccount = $user->can('conversion.view_all') && blank($filters['cra'] ?? null);
        $days = $cancelledOrders->perDay($shown, $from, $to, $status, $everyCrdAccount);

        return view('cancelled-orders.index', [
            'orders' => $cancelledOrders->query($shown, $from, $to, $status, $everyCrdAccount)
                ->orderByDesc('ordered_on')->orderByDesc('ordered_at')->orderByDesc('id')->paginate(50)->withQueryString(),
            'days' => $days,
            'totals' => [
                'cancelled' => $days->sum('cancelled'),
                'waiting' => $days->sum('waiting'),
                'total' => $days->sum('total'),
                'amount' => $days->sum('amount'),
            ],
            'craFor' => fn (PancakeOrder $order) => $byId[$accounts[$order->seller_name] ?? 0] ?? null,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'label' => (new DashboardRange($from, $to, $from->startOfMonth(), true))->label(),
            'dashboardQuery' => $period === 'month' && $from->day === 1 ? ['month' => $from->format('Y-m')] : ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'today' => $today,
            'filters' => $filters,
            'allCras' => $allCras,
            'canViewAll' => $user->can('conversion.view_all'),
            'statuses' => config('customers.pos_statuses'),
        ]);
    }
}
