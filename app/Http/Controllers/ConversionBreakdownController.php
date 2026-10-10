<?php

namespace App\Http\Controllers;

use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Services\ConversionBreakdown;
use App\Services\LeadGenerator;
use App\Services\PancakeSync;
use App\Support\DashboardRange;
use App\Support\MonthWeeks;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConversionBreakdownController extends Controller
{
    /**
     * Columns the CRA table can be sorted by: key => [label, format].
     */
    public const SORTS = [
        'engagements' => ['Engagements', 'count'],
        'bc_orders' => ['Orders BC', 'count'],
        'bc_rate' => ['BC conv %', 'percent'],
        'leads' => ['Leads', 'count'],
        'sc_orders' => ['Orders SC', 'count'],
        'sc_rate' => ['SC conv %', 'percent'],
        'total_rate' => ['Total conv %', 'percent'],
        'bc_gross' => ['Gross BC', 'money'],
        'sc_gross' => ['Gross SC', 'money'],
        'gross' => ['Gross sales', 'money'],
    ];

    public function index(Request $request, ConversionBreakdown $breakdown, PancakeSync $pancake): View
    {
        $user = $request->user();
        $canViewAll = $user->can('conversion.view_all');
        // Results are on real days (the tracker alone runs on the working date).
        $today = WorkingDate::realToday();

        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'week' => ['nullable', 'integer', 'min:1', 'max:5'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'cras' => ['nullable', 'array'],
            'cras.*' => ['integer'],
        ]);

        $view = $filters['view'] ?? 'day';
        $sort = $filters['sort'] ?? 'total_rate';
        [$period, $compare] = $this->periods($view, $filters, $today);

        $allCras = LeadGenerator::cras();
        $selected = collect($filters['cras'] ?? [])->map(fn ($id) => (int) $id);
        $shown = match (true) {
            ! $canViewAll => $allCras->where('id', $user->id)->values(),
            $selected->isNotEmpty() => $allCras->whereIn('id', $selected)->values(),
            default => $allCras,
        };

        // Keep today's Pancake numbers fresh when today is on screen; it runs after the page has been sent.
        if ($period['from']->lessThanOrEqualTo($today) && $period['to']->greaterThanOrEqualTo($today)
            && PancakeSync::isStale($today) && ! PancakeSync::isRunning($today)) {
            $pancake->syncLater($today);
        }

        // Days after today have no data yet; keep every range at least one day long.
        $clip = fn (array $range) => [$range['from'], $range['to']->min($today)->max($range['from'])];
        $now = $breakdown->days($shown, ...$clip($period));
        $before = $breakdown->days($shown, ...$clip($compare));

        $rows = $shown->map(fn (User $cra) => [
            'cra' => $cra,
            'now' => ConversionBreakdown::sum($now[$cra->id] ?? []),
            'before' => ConversionBreakdown::sum($before[$cra->id] ?? []),
        ])->sortByDesc(fn (array $row) => $row['now'][$sort] ?? -1)->values();

        $syncDay = $period['to']->min($today);

        return view('conversion.index', [
            'view' => $view,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'period' => $period,
            'compare' => $compare,
            'weeks' => MonthWeeks::for($period['month']),
            'rows' => $rows,
            'total' => ConversionBreakdown::sum($rows->pluck('now')),
            'totalBefore' => ConversionBreakdown::sum($rows->pluck('before')),
            'allCras' => $allCras,
            'selected' => $canViewAll ? $shown->pluck('id')->all() : [],
            'canViewAll' => $canViewAll,
            'canSync' => $canViewAll,
            'missingAccounts' => $shown->filter(fn (User $cra) => ! $cra->pancake_name)->values(),
            'filters' => [...$filters, 'view' => $view, 'sort' => $sort],
            'today' => $today,
            'syncDay' => $syncDay,
            'lastSync' => PancakeSync::lastSync($syncDay),
            'syncing' => PancakeSync::isRunning($syncDay),
            'base' => config('segmentation.leads_per_cra'),
        ]);
    }

    /**
     * The confirmed orders behind the dashboard's tile: the CRAs' own orders tagged CRD - BROADCAST or
     * CRD - SEGMENTATION (not canceled or deleted) in the dashboard's month or range. A CRA sees their own.
     */
    public function orders(Request $request): View
    {
        $user = $request->user();
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.$today->format('Y-m')],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'cra' => ['nullable', 'integer'],
        ]);
        $range = DashboardRange::fromFilters($filters, $today);

        $allCras = LeadGenerator::cras();
        $shown = match (true) {
            ! $user->can('conversion.view_all') => $allCras->where('id', $user->id),
            filled($filters['cra'] ?? null) => $allCras->where('id', (int) $filters['cra']),
            default => $allCras,
        };
        $accounts = ConversionBreakdown::accounts($shown);
        $byId = $allCras->keyBy('id');

        $query = PancakeOrder::whereIn('seller_name', $accounts->keys())
            ->whereIn('conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION])
            ->whereDate('ordered_on', '>=', $range->from)->whereDate('ordered_on', '<=', $range->to);

        // The list and count are confirmed orders; gross sales counts every status, as on the dashboard.
        $all = (clone $query)->get(['status', 'total_price', 'shecom_sales']);
        $gross = $all->sum(fn (PancakeOrder $order) => $order->sales());
        $all = $all->filter(fn (PancakeOrder $order) => $order->isCounted());
        $query->counted();

        return view('conversion.orders', [
            'orders' => $query->orderByDesc('ordered_on')->orderByDesc('ordered_at')->orderByDesc('id')->paginate(50)->withQueryString(),
            'craFor' => fn (PancakeOrder $order) => $byId[$accounts[$order->seller_name] ?? 0] ?? null,
            'count' => $all->count(),
            'gross' => $gross,
            'aov' => $all->count() ? $gross / $all->count() : null,
            'range' => $range,
            'filters' => $filters,
            'allCras' => $allCras,
            'canViewAll' => $user->can('conversion.view_all'),
            'statuses' => config('customers.pos_statuses'),
        ]);
    }

    /**
     * The orders behind one CRA's gross sales for $from–$to (the per-CRA pop-up): their own Pancake orders
     * tagged CRD - BROADCAST or CRD - SEGMENTATION, every status, with the same amounts, so they add up
     * to the Gross BC, Gross SC and Gross sales shown.
     */
    public function craOrders(Request $request, User $cra): View
    {
        $user = $request->user();
        abort_unless($user->can('conversion.view_all') || $user->is($cra), 403);

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);

        $orders = $cra->pancake_name
            ? PancakeOrder::where('seller_name', PancakeEngagement::staffKey($cra->pancake_name))
                ->whereIn('conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION])
                ->whereDate('ordered_on', '>=', $from)->whereDate('ordered_on', '<=', $to)
                // First to most recent, by when the order was created in Pancake.
                ->orderBy('ordered_at')->orderBy('id')
                ->get(['pancake_order_id', 'ordered_on', 'ordered_at', 'customer_name', 'page_name', 'conversion_type', 'status', 'status_name', 'total_price', 'shecom_sales'])
            : collect();
        $gross = fn (string $type) => $orders->where('conversion_type', $type)->sum(fn (PancakeOrder $order) => $order->sales());

        return view('conversion._cra-orders', [
            'cra' => $cra,
            'orders' => $orders,
            'period' => $from->equalTo($to) ? $from->format('D, M j, Y') : $from->format('M j').' – '.$to->format('M j, Y'),
            'bcGross' => $gross(PancakeOrder::BROADCAST),
            'scGross' => $gross(PancakeOrder::SEGMENTATION),
            'statuses' => config('customers.pos_statuses'),
        ]);
    }

    /**
     * Pull one day's Pancake engagements and tagged orders now.
     */
    public function sync(Request $request, PancakeSync $pancake): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $day = CarbonImmutable::parse($data['date']);

        if (PancakeSync::isRunning($day)) {
            return back()->with('status', "Pancake is already syncing {$day->format('M j, Y')}. Refresh in a few minutes.");
        }

        $pancake->syncLater($day, force: true);

        return back()->with('status', "Syncing Pancake for {$day->format('M j, Y')}. It takes a few minutes; refresh to see the new numbers.");
    }

    /**
     * The chosen day, week (1–7, 8–14… from the 1st) or month, and the one before it to compare with.
     *
     * @return array{0: array{from: CarbonImmutable, to: CarbonImmutable, label: string, month: CarbonImmutable}, 1: array{from: CarbonImmutable, to: CarbonImmutable, label: string, month: CarbonImmutable}}
     */
    private function periods(string $view, array $filters, CarbonImmutable $today): array
    {
        if ($view === 'day') {
            $day = CarbonImmutable::parse($filters['date'] ?? $today->toDateString())->min($today);
            $make = fn (CarbonImmutable $d) => ['from' => $d, 'to' => $d, 'label' => $d->format('D, M j'), 'month' => $d->startOfMonth()];

            return [$make($day), $make($day->subDay())];
        }

        $month = CarbonImmutable::parse(($filters['month'] ?? $today->format('Y-m')).'-01')->min($today->startOfMonth());

        if ($view === 'month') {
            $make = fn (CarbonImmutable $m) => ['from' => $m, 'to' => $m->endOfMonth()->startOfDay(), 'label' => $m->format('F Y'), 'month' => $m];

            return [$make($month), $make($month->subMonth())];
        }

        $weeks = MonthWeeks::for($month);
        $number = min((int) ($filters['week'] ?? MonthWeeks::containing($month, $today)), count($weeks));
        $previous = $number > 1 ? $weeks[$number - 2] : collect(MonthWeeks::for($month->subMonth()))->last();
        $make = fn (array $w) => [
            'from' => $w['start'], 'to' => $w['end'], 'number' => $w['number'], 'month' => $w['start']->startOfMonth(),
            'label' => ['1st', '2nd', '3rd', '4th', '5th'][$w['number'] - 1].' week · '.$w['start']->format('M j').'–'.$w['end']->format('j'),
        ];

        return [$make($weeks[$number - 1]), $make($previous)];
    }
}
