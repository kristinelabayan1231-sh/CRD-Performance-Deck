<?php

namespace App\Http\Controllers;

use App\Models\PancakeEngagement;
use App\Models\Product;
use App\Services\PresentationBoard;
use App\Services\ProductPerformance;
use App\Support\OrderPeriod;
use App\Support\PancakeAccounts;
use App\Support\UpsellBreakdown;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Product Performance: every CRD account's (Settings → Pancake Accounts) Pancake POS orders per product for a day,
 * a week, a month, the last 3 months or a From–To range, against the period of the same length before it. The Products
 * tab is the overall; the CRD, TSD and Testing tabs are the same page for only the CRD, TSD upsell or testing products.
 */
class ProductPerformanceController extends Controller
{
    /**
     * The graph of the past days, weeks and months reads a year of orders. Its result stays fresh for the first number of
     * seconds; after that, up to the second, it still shows at once while a new one is worked out behind the scenes.
     */
    private const TREND_CACHE_SECONDS = [600, 86400];

    private const TABS = ['index', 'crd', 'tsd', 'testing'];

    /** Main vs upsell comes from the Presentation Board's snapshot, taken every two hours; its split is kept this long. */
    private const UPSELL_CACHE_SECONDS = 600;

    public function index(Request $request, ProductPerformance $productPerformance, PresentationBoard $board): View
    {
        return $this->page($request, $productPerformance, 'index', $board);
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
     * A tab's graph of the past days, weeks and months up to ?to, loaded after the page opens. One read of the orders
     * fills every tab's, so switching tabs doesn't wait again.
     */
    public function trend(Request $request, ProductPerformance $productPerformance): JsonResponse
    {
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'tab' => ['required', Rule::in(self::TABS)],
            'to' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
        ]);

        $trends = Cache::flexible("product-performance.trends.{$filters['to']}", self::TREND_CACHE_SECONDS, function () use ($productPerformance, $filters, $today) {
            set_time_limit(0);
            $tabs = collect(self::TABS)->mapWithKeys(fn (string $tab) => [$tab => $this->tab($tab)['only']])->all();

            return $productPerformance->trend($this->sellers(), CarbonImmutable::parse($filters['to']), $today, $tabs);
        });

        return response()->json($trends[$filters['tab']]);
    }

    private function page(Request $request, ProductPerformance $productPerformance, string $tab, ?PresentationBoard $board = null): View
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
            'upsell' => $board ? $this->upsell($board, $period, $from, $to) : null,
            // A card opens the product in Order Database → Product Report, which shows every CRD account only to these.
            'canOpenReport' => $request->user()->can('order_database.view_all'),
        ]);
    }

    /**
     * Products tab: main vs upsell for the dates shown, and for the days, weeks or months around them, from the
     * Presentation Board's Top products figures split by UpsellBreakdown. Null before its first snapshot or outside its
     * year.
     *
     * @return array{split: array<string, mixed>, products: list<array<string, mixed>>, others: ?array<string, mixed>, slots: list<array<string, mixed>>, unit: string}|null
     */
    private function upsell(PresentationBoard $board, string $period, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $key = "product-performance.upsell.{$period}.{$from->toDateString()}.{$to->toDateString()}";

        return Cache::remember($key, self::UPSELL_CACHE_SECONDS, function () use ($board, $period, $from, $to) {
            $snapshot = $board->snapshot();
            $first = $snapshot ? array_key_first($snapshot['days']) : null;
            if (! $snapshot || $to->toDateString() < $first || $from->toDateString() > $snapshot['today']) {
                return null;
            }

            $tsdNames = Product::where('is_tsd', true)->pluck('name')->all();
            $top = $board->products($snapshot, $from->toDateString(), $to->toDateString());
            ['unit' => $unit, 'slots' => $slots] = $this->upsellSlots($period, $from, $to);
            $slots = collect($slots)
                ->filter(fn (array $slot) => $slot['to'] >= $first && $slot['from'] <= $snapshot['today'])
                ->map(fn (array $slot) => [
                    ...$slot,
                    ...collect(UpsellBreakdown::totals($board->products($snapshot, $slot['from'], $slot['to']), $tsdNames))->except('tsd_products')->all(),
                    'partial' => $slot['to'] > $snapshot['today'],
                ])
                ->values()->all();

            return [
                'split' => UpsellBreakdown::totals($top, $tsdNames),
                'products' => UpsellBreakdown::listed($top['products'], $tsdNames),
                'others' => collect($top['products'])->firstWhere('name', PresentationBoard::OTHERS),
                'slots' => $slots,
                'unit' => $unit,
            ];
        });
    }

    /**
     * The bars of main vs upsell: a day shows the 14 days up to it, a week the 8 weeks up to it, a month the 6 months up
     * to it; the last 3 months and a range show their own days, weeks or months by length. current = the dates shown.
     *
     * @return array{unit: string, slots: list<array{from: string, to: string, label: string, title: string, current: bool}>}
     */
    private function upsellSlots(string $period, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $from->diffInDays($to) + 1;
        $unit = match ($period) {
            'day', 'week', 'month' => $period,
            default => $days <= 31 ? 'day' : ($days <= 112 ? 'week' : 'month'),
        };
        $starts = match (true) {
            $period === 'day' => collect(range(13, 0))->map(fn (int $back) => $from->subDays($back)),
            $period === 'week' => collect(range(7, 0))->map(fn (int $back) => $from->subWeeks($back)),
            $period === 'month' => collect(range(5, 0))->map(fn (int $back) => $from->startOfMonth()->subMonths($back)),
            default => collect(CarbonPeriod::create(match ($unit) {
                'day' => $from,
                'week' => $from->startOfWeek(),
                default => $from->startOfMonth(),
            }, "1 {$unit}", $to))->map(fn ($start) => CarbonImmutable::parse($start)),
        };
        $inRange = ! in_array($period, ['day', 'week', 'month'], true);

        return ['unit' => $unit, 'slots' => $starts->map(function (CarbonImmutable $start) use ($unit, $from, $to, $inRange) {
            $end = match ($unit) {
                'day' => $start,
                'week' => $start->addDays(6),
                default => $start->endOfMonth(),
            };
            // A range's first and last week or month keep to its days.
            [$start, $end] = $inRange ? [$start->max($from), $end->min($to)] : [$start, $end];

            return [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'label' => $start->format($unit === 'month' ? 'M' : 'M j'),
                'title' => match ($unit) {
                    'day' => $start->format('D, M j, Y'),
                    'week' => OrderPeriod::label($start, $end),
                    default => $start->format('F Y'),
                },
                'current' => ! $inRange && $start->equalTo($from),
            ];
        })->values()->all()];
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
