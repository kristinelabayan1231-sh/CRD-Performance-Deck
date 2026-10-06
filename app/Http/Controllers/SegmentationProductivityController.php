<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\PancakeSync;
use App\Services\SegmentationProductivity;
use App\Support\MonthWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

use function Illuminate\Support\defer;

class SegmentationProductivityController extends Controller
{
    /**
     * Categorical chart colours, one per CRA in a fixed order (validated dataviz palette, light surface).
     */
    public const COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

    public const METRICS = [
        'confirmed' => ['Total confirmed orders', 'count'],
        'answered' => ['Answered (calls & chat)', 'count'],
        'assigned' => ['Assigned transactions', 'count'],
        'alc' => ['Assigned lead conversion', 'count'],
        'pc' => ['Pancake conversion', 'count'],
        'conversion_rate' => ['Conversion rate', 'percent'],
        'pickup_rate' => ['Pick-up rate', 'percent'],
        'gross' => ['Gross sales', 'money'],
        'aov' => ['AOV', 'money'],
    ];

    public function index(Request $request, SegmentationProductivity $productivity, PancakeSync $pancake): View
    {
        $user = $request->user();
        $canViewAll = $user->can('productivity.view_all');
        $today = Lead::today();

        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['day', 'week'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'week' => ['nullable', 'integer', 'min:1', 'max:5'],
            'compare' => ['nullable', 'string', 'max:20'],
            'metric' => ['nullable', Rule::in(array_keys(self::METRICS))],
            'sales' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'cras' => ['nullable', 'array'],
            'cras.*' => ['integer'],
        ]);

        $view = $filters['view'] ?? 'day';
        $metric = $filters['metric'] ?? 'confirmed';
        [$period, $compare, $month] = $view === 'day'
            ? $this->dayPeriods($filters, $today)
            : $this->weekPeriods($filters, $today);

        $allCras = LeadGenerator::cras();
        $colors = $allCras->values()->mapWithKeys(fn (User $cra, int $i) => [$cra->id => self::COLORS[$i] ?? '#8a8f98']);
        $selected = collect($filters['cras'] ?? [])->map(fn ($id) => (int) $id);
        $shown = match (true) {
            ! $canViewAll => $allCras->where('id', $user->id)->values(),
            $selected->isNotEmpty() => $allCras->whereIn('id', $selected)->values(),
            default => $allCras,
        };

        // Keep today's Pancake numbers fresh when today is on screen. A full
        // day takes minutes, so it runs after the page has been sent.
        if ($period['from']->lessThanOrEqualTo($today) && $period['to']->greaterThanOrEqualTo($today)
            && PancakeSync::isStale($today) && ! PancakeSync::isRunning($today)) {
            $this->syncLater($pancake, $today);
        }
        $syncDay = $period['to']->min($today);

        // One read covering the month on screen; the compare period is read separately when it falls outside it.
        $monthEnd = $month->endOfMonth()->startOfDay()->min($today);
        $monthDays = $productivity->days($shown, $month, $monthEnd->max($month));
        $compareDays = $compare['from']->greaterThanOrEqualTo($month) && $compare['to']->lessThanOrEqualTo($monthEnd)
            ? $monthDays
            : $productivity->days($shown, $compare['from'], $compare['to']->min($today)->max($compare['from']));

        $slice = fn (array $days, array $range) => array_filter($days, fn (string $day) => $day >= $range['from']->toDateString() && $day <= $range['to']->toDateString(), ARRAY_FILTER_USE_KEY);

        // Trend points: every day of the month so far, or every week of the month.
        $points = $view === 'day'
            ? collect(array_keys($monthDays[$shown->first()?->id] ?? []))->map(fn (string $day) => [
                'from' => CarbonImmutable::parse($day), 'to' => CarbonImmutable::parse($day), 'label' => CarbonImmutable::parse($day)->format('M j'),
            ])
            : collect(MonthWeeks::for($month))->filter(fn (array $w) => $w['start']->lessThanOrEqualTo($today))->map(fn (array $w) => [
                'from' => $w['start'], 'to' => $w['end'], 'label' => 'Wk '.$w['number'],
            ])->values();

        $rows = $shown->map(function (User $cra) use ($monthDays, $compareDays, $period, $compare, $points, $slice, $colors, $metric) {
            $days = $monthDays[$cra->id] ?? [];

            return [
                'cra' => $cra,
                'color' => $colors[$cra->id],
                'now' => SegmentationProductivity::sum($slice($days, $period)),
                'before' => SegmentationProductivity::sum($slice($compareDays[$cra->id] ?? [], $compare)),
                'spark' => $points->map(fn (array $p) => [
                    'label' => $p['label'],
                    'value' => SegmentationProductivity::sum($slice($days, $p))[$metric],
                    'selected' => $p['from']->equalTo($period['from']),
                ])->all(),
            ];
        })->values();

        $weekly = collect(MonthWeeks::for($month))->filter(fn (array $w) => $w['start']->lessThanOrEqualTo($today))->map(fn (array $w) => [
            'label' => 'Wk '.$w['number'],
            'range' => $w['label'],
            'selected' => $view === 'week' && $w['start']->equalTo($period['from']),
            'stack' => $rows->map(fn (array $r) => [
                'name' => $r['cra']->displayName(),
                'color' => $r['color'],
                'value' => SegmentationProductivity::sum($slice($monthDays[$r['cra']->id] ?? [], ['from' => $w['start'], 'to' => $w['end']]))['confirmed'],
            ])->all(),
        ])->values();

        // Sales per CRA by day, week or month, to show the top seller of each.
        $salesBy = $filters['sales'] ?? $view;
        $salesDays = $monthDays;
        $buckets = match ($salesBy) {
            'day' => collect(array_keys($monthDays[$shown->first()?->id] ?? []))->map(fn (string $day) => [
                'from' => CarbonImmutable::parse($day), 'to' => CarbonImmutable::parse($day),
                'label' => CarbonImmutable::parse($day)->format('M j'), 'long' => CarbonImmutable::parse($day)->format('D, M j'),
            ]),
            'week' => collect(MonthWeeks::for($month))->filter(fn (array $w) => $w['start']->lessThanOrEqualTo($today))->map(fn (array $w) => [
                'from' => $w['start'], 'to' => $w['end'], 'label' => 'Wk '.$w['number'], 'long' => $this->weekPeriod($w)['label'],
            ])->values(),
            'month' => collect(range(5, 0))->map(fn (int $back) => $month->subMonths($back))->map(fn (CarbonImmutable $m) => [
                'from' => $m, 'to' => $m->endOfMonth()->startOfDay(), 'label' => $m->format('M'), 'long' => $m->format('F Y'),
            ]),
        };
        if ($salesBy === 'month') {
            $salesDays = $productivity->days($shown, $month->subMonths(5), $monthEnd->max($month));
        }

        $sales = $buckets->map(function (array $bucket) use ($rows, $salesDays, $slice, $period) {
            $stack = $rows->map(fn (array $r) => [
                'name' => $r['cra']->displayName(),
                'color' => $r['color'],
                'value' => (float) SegmentationProductivity::sum($slice($salesDays[$r['cra']->id] ?? [], $bucket))['gross'],
            ]);
            $top = $stack->sortByDesc('value')->first();

            return [
                ...$bucket,
                // The bar holding the day or week picked in the filters.
                'selected' => $period['from']->betweenIncluded($bucket['from'], $bucket['to']),
                'stack' => $stack->all(),
                'total' => $stack->sum('value'),
                'top' => $top && $top['value'] > 0 ? $top : null,
            ];
        })->values();

        return view('productivity.index', [
            'view' => $view,
            'metric' => $metric,
            'metrics' => self::METRICS,
            'period' => $period,
            'compare' => $compare,
            'month' => $month,
            'weeks' => $this->weekOptions($month),
            'rows' => $rows,
            'total' => SegmentationProductivity::sum($rows->pluck('now')),
            'totalBefore' => SegmentationProductivity::sum($rows->pluck('before')),
            'weekly' => $weekly,
            'sales' => $sales,
            'salesBy' => $salesBy,
            'allCras' => $allCras,
            'colors' => $colors,
            'selected' => $canViewAll ? $shown->pluck('id')->all() : [],
            'canViewAll' => $canViewAll,
            'canSync' => $user->can('productivity.view_all'),
            'missingAccounts' => $shown->filter(fn (User $cra) => ! $cra->pancake_name)->values(),
            'filters' => [...$filters, 'view' => $view, 'metric' => $metric],
            'today' => $today,
            'syncDay' => $syncDay,
            'lastSync' => PancakeSync::lastSync($syncDay),
            'syncing' => PancakeSync::isRunning($syncDay),
            'base' => config('segmentation.leads_per_cra'),
        ]);
    }

    /**
     * Pull one day's Pancake engagements and orders now.
     */
    public function sync(Request $request, PancakeSync $pancake): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $day = CarbonImmutable::parse($data['date']);

        if (PancakeSync::isRunning($day)) {
            return back()->with('status', "Pancake is already syncing {$day->format('M j, Y')}. Refresh in a few minutes.");
        }

        $this->syncLater($pancake, $day, force: true);

        return back()->with('status', "Syncing Pancake for {$day->format('M j, Y')}. It takes a few minutes; refresh to see the new numbers.");
    }

    /**
     * Sync after the response is sent; failures are logged, and the page shows the last good sync time.
     */
    private function syncLater(PancakeSync $pancake, CarbonImmutable $day, bool $force = false): void
    {
        defer(function () use ($pancake, $day, $force) {
            try {
                $pancake->syncIfStale($day, $force ? 0 : 60);
            } catch (Throwable $e) {
                Log::warning('Pancake sync failed', ['date' => $day->toDateString(), 'message' => $e->getMessage()]);
            }
        }, 'pancake-sync-'.$day->toDateString());
    }

    /**
     * Day view: the chosen day and the day to compare it with (default: the day before).
     *
     * @return array{0: array{from: CarbonImmutable, to: CarbonImmutable, label: string, key: string}, 1: array{from: CarbonImmutable, to: CarbonImmutable, label: string, key: string}, 2: CarbonImmutable}
     */
    private function dayPeriods(array $filters, CarbonImmutable $today): array
    {
        $day = CarbonImmutable::parse($filters['date'] ?? $today->toDateString())->min($today);
        $other = isset($filters['compare']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['compare'])
            ? CarbonImmutable::parse($filters['compare'])->min($today)
            : $day->subDay();

        $make = fn (CarbonImmutable $d) => ['from' => $d, 'to' => $d, 'label' => $d->format('D, M j'), 'key' => $d->toDateString()];

        return [$make($day), $make($other), $day->startOfMonth()];
    }

    /**
     * Week view: weeks run 1–7, 8–14… from the 1st, as in Weekly Segmentation.
     * Compare keys look like 2026-09/2 (month/week); the default is the week before.
     */
    private function weekPeriods(array $filters, CarbonImmutable $today): array
    {
        $month = CarbonImmutable::parse(($filters['month'] ?? $today->format('Y-m')).'-01');
        $weeks = MonthWeeks::for($month);
        $number = min((int) ($filters['week'] ?? MonthWeeks::containing($month, $today)), count($weeks));
        $week = $weeks[$number - 1];

        if (isset($filters['compare']) && preg_match('/^(\d{4}-\d{2})\/(\d)$/', $filters['compare'], $m)) {
            $otherWeeks = MonthWeeks::for(CarbonImmutable::parse($m[1].'-01'));
            $other = $otherWeeks[min((int) $m[2], count($otherWeeks)) - 1];
        } elseif ($number > 1) {
            $other = $weeks[$number - 2];
        } else {
            $other = collect(MonthWeeks::for($month->subMonth()))->last();
        }

        return [$this->weekPeriod($week), $this->weekPeriod($other), $month];
    }

    private function weekPeriod(array $week): array
    {
        $ordinal = ['1st', '2nd', '3rd', '4th', '5th'][$week['number'] - 1];

        return [
            'from' => $week['start'],
            'to' => $week['end'],
            'label' => "{$ordinal} week · {$week['start']->format('M j')}–".($week['start']->isSameMonth($week['end']) ? $week['end']->format('j') : $week['end']->format('M j')),
            'key' => $week['start']->format('Y-m').'/'.$week['number'],
            'number' => $week['number'],
        ];
    }

    /**
     * Weeks of this month and last month, for the Compare with list.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function weekOptions(CarbonImmutable $month): Collection
    {
        return collect([...MonthWeeks::for($month), ...MonthWeeks::for($month->subMonth())])
            ->map(fn (array $week) => $this->weekPeriod($week));
    }
}
