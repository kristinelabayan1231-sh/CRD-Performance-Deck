<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Services\LeadGenerator;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Exclusive Leads Dashboard, for supervisors, managers and leads: an exclusive CRA's leads (the month's 7,000 assigned to
 * them) by product and bottles in the delivered order that made them a lead, for today, this month or the year so far,
 * with the customers of the cell picked, their est. out-of-stock date beside them, and their callbacks and Reactivation.
 * A search (name, contact number or order #) lists the period's customers that match instead of the cell's, and the list
 * opens full size in a pop-up (full=1).
 */
class ExclusiveLeadDashboardController extends Controller
{
    private const PER_PAGE = 25;

    /** Rows per page in the list's full-size pop-up. */
    private const FULL_PER_PAGE = 100;

    /** Bottle buckets: 0 = qty unknown, 4 = four or more. */
    public const BUCKETS = [1 => '1 bottle', 2 => '2 bottles', 3 => '3 bottles', 4 => '4+ bottles', 0 => 'Qty unknown'];

    public const PERIODS = ['today' => 'Today', 'month' => 'This month', 'year' => 'Year to date'];

    /** Days to the est. out-of-stock date that count as nearing: 2 or fewer is urgent, up to 7 is soon. */
    public const URGENT_DAYS = 2;

    public const SOON_DAYS = 7;

    private const BUCKET_SQL = 'case when qty_unknown = true or qty is null or qty < 1 then 0 when qty >= 4 then 4 else qty end';

    public function index(Request $request, ExclusiveLeads $exclusive): View
    {
        ['cra' => $cra, 'cras' => $cras, 'period' => $period, 'grid' => $grid, 'picked' => $picked, 'search' => $search, 'cellLeads' => $cellLeads] = $this->resolve($request, $exclusive);
        $full = $request->boolean('full');

        if (! $cra) {
            return view('exclusive-leads.dashboard', ['cra' => null, 'cras' => $cras]);
        }

        $listFilters = ['cra' => $cra->id, 'period' => $period, 'product' => $picked['product'] ?? null, 'qty' => $picked['qty'] ?? null, 'q' => $search];
        $leads = $cellLeads?->paginate($full ? self::FULL_PER_PAGE : self::PER_PAGE)->withQueryString();
        $list = [
            'cra' => $cra,
            'period' => $period,
            'picked' => $picked,
            'search' => $search,
            'leads' => $leads,
            'leadToday' => Lead::today(),
            'listFilters' => array_filter($listFilters, fn (mixed $value) => $value !== null),
            'phonesUrl' => $cellLeads ? route('exclusive-leads.dashboard.phones', array_filter($listFilters, fn (mixed $value) => $value !== null)) : null,
        ];

        if ($full) {
            return view('exclusive-leads.dashboard-full', $list);
        }

        $today = WorkingDate::realToday();
        $craLeads = Lead::query()->where('assigned_to', $cra->id);

        return view('exclusive-leads.dashboard', $list + [
            'cra' => $cra,
            'cras' => $cras,
            'period' => $period,
            'periodRange' => $this->periodRange($period, $today, $cra->exclusive_from->addDays(WorkingDate::lagDays())),
            'grid' => $grid,
            'callbacks' => (clone $craLeads)->callbackDue($today)->count(),
            'reactivation' => (clone $craLeads)->reactivation(Lead::today(), $today)->count(),
            'callbacksUrl' => route('exclusive-leads.index', ['cra' => $cra->id, 'show' => 'unprocessed', 'full' => 1, 'list' => 'callback']),
        ]);
    }

    /**
     * Every contact number of the picked cell's customers (or the search's), each customer once, for pasting into Pancake POS's search.
     */
    public function phones(Request $request, ExclusiveLeads $exclusive): JsonResponse
    {
        $cellLeads = $this->resolve($request, $exclusive)['cellLeads'];
        abort_unless($cellLeads, 404);

        $phones = $cellLeads->pluck('phone_number')
            ->filter()
            ->unique(fn (string $phone) => LeadGenerator::normalizePhone($phone))
            ->values();

        return response()->json(['phones' => $phones]);
    }

    /**
     * The CRA, period, grid and picked cell asked for, and the customers listed in list order: the picked cell's, or with a
     * search every customer of the period that matches; not yet out of stock first, soonest first, then those already
     * out, the latest first.
     *
     * @return array{cra: ?User, cras: Collection<int, User>, period: string, grid: ?array<string, mixed>, picked: ?array{product: string, qty: int, count: int}, search: ?string, cellLeads: ?Builder<Lead>}
     */
    private function resolve(Request $request, ExclusiveLeads $exclusive): array
    {
        $filters = $request->validate([
            'cra' => ['nullable', 'integer'],
            'period' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'product' => ['nullable', 'string', 'max:255'],
            'qty' => ['nullable', 'integer', Rule::in(array_keys(self::BUCKETS))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($filters['q'] ?? '')) ?: null;

        $cras = $exclusive->cras();
        $cra = $cras->firstWhere('id', (int) ($filters['cra'] ?? 0)) ?? $cras->first();
        $period = $filters['period'] ?? 'month';

        if (! $cra) {
            return ['cra' => null, 'cras' => $cras, 'period' => $period, 'grid' => null, 'picked' => null, 'search' => $search, 'cellLeads' => null];
        }

        $today = WorkingDate::realToday();
        $scope = fn (): Builder => match ($period) {
            'today' => $exclusive->leadsBetween($cra, $today, $today),
            'month' => $exclusive->monthLeads($cra, $today),
            'year' => $exclusive->yearLeads($cra, $today),
        };

        $grid = $this->grid($scope());
        $picked = $this->pickedCell($grid, $filters);
        $leadToday = Lead::today()->toDateString();

        $listed = match (true) {
            $search !== null => $scope()->search($search),
            $picked !== null => $scope()->where('product_name', $picked['product'])->whereRaw(self::BUCKET_SQL.' = ?', [$picked['qty']]),
            default => null,
        };

        $cellLeads = $listed?->orderByRaw('case when est_out_of_stock_date >= ? then 0 else 1 end', [$leadToday])
            ->orderByRaw('case when est_out_of_stock_date >= ? then est_out_of_stock_date end', [$leadToday])
            ->orderByDesc('est_out_of_stock_date')
            ->orderBy('customer_name')
            ->orderBy('id');

        return ['cra' => $cra, 'cras' => $cras, 'period' => $period, 'grid' => $grid, 'picked' => $picked, 'search' => $search, 'cellLeads' => $cellLeads];
    }

    /**
     * Leads per product and bottle bucket, the products with the most leads first.
     *
     * @param  Builder<Lead>  $leads
     * @return array{products: Collection<int, array{product: string, cells: array<int, int>, total: int}>, buckets: list<int>, max: int, leads: int, bottles: int}
     */
    private function grid(Builder $leads): array
    {
        $rows = $leads->toBase()
            ->selectRaw('product_name, '.self::BUCKET_SQL.' as bucket, count(*) as leads, sum(case when qty_unknown = true then 0 else coalesce(qty, 0) end) as bottles')
            ->groupBy('product_name', 'bucket')
            ->get();

        $products = $rows->groupBy('product_name')
            ->map(function (Collection $cells, string $product) {
                $counts = $cells->mapWithKeys(fn (object $cell) => [(int) $cell->bucket => (int) $cell->leads])->all();

                return ['product' => $product, 'cells' => $counts, 'total' => array_sum($counts)];
            })
            ->sortBy([['total', 'desc'], ['product', 'asc']])
            ->values();

        // The unknown column shows only when some lead has it.
        $buckets = $rows->contains(fn (object $cell) => (int) $cell->bucket === 0) ? [1, 2, 3, 4, 0] : [1, 2, 3, 4];

        return [
            'products' => $products,
            'buckets' => $buckets,
            'max' => (int) $rows->max('leads'),
            'leads' => (int) $rows->sum('leads'),
            'bottles' => (int) $rows->sum('bottles'),
        ];
    }

    /**
     * The cell whose customers are listed: the one asked for, else the one with the most leads.
     *
     * @param  array{products: Collection<int, array{product: string, cells: array<int, int>, total: int}>}  $grid
     * @param  array<string, mixed>  $filters
     * @return array{product: string, qty: int, count: int}|null
     */
    private function pickedCell(array $grid, array $filters): ?array
    {
        $asked = $grid['products']->firstWhere('product', $filters['product'] ?? null);
        $qty = isset($filters['qty']) ? (int) $filters['qty'] : null;

        if ($asked && $qty !== null) {
            return ['product' => $asked['product'], 'qty' => $qty, 'count' => $asked['cells'][$qty] ?? 0];
        }

        $best = null;
        foreach ($grid['products'] as $row) {
            foreach ($row['cells'] as $bucket => $count) {
                if ($best === null || $count > $best['count']) {
                    $best = ['product' => $row['product'], 'qty' => $bucket, 'count' => $count];
                }
            }
        }

        return $best;
    }

    /**
     * "Mon, Nov 9" / "Nov 1–9" / "Jan 1 – Nov 9", never before the CRA's first exclusive day.
     */
    private function periodRange(string $period, CarbonImmutable $today, CarbonImmutable $firstDay): string
    {
        $from = match ($period) {
            'today' => $today,
            'month' => $today->startOfMonth(),
            'year' => $today->startOfYear(),
        };
        $from = $from->max($firstDay->startOfDay())->min($today);

        return match (true) {
            $from->isSameDay($today) => $today->format('D, M j'),
            $from->isSameMonth($today) => $from->format('M j').'–'.$today->format('j'),
            default => $from->format('M j').' – '.$today->format('M j'),
        };
    }
}
