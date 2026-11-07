<?php

namespace App\Http\Controllers;

use App\Models\ExclusiveLeadLimit;
use App\Models\Lead;
use App\Models\PancakeReservation;
use App\Models\Product;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Services\LeadFollowUps;
use App\Services\LeadGenerator;
use App\Support\ExclusiveLimits;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The Exclusive Lead Tracker page: an exclusive CRA's daily list (same table, filters and Pending full view as the
 * Segmentation Tracker) with the month's goals. Settings (transfer a CRA in or out) are in ExclusiveLeadSettingController.
 */
class ExclusiveLeadController extends Controller
{
    private const PER_PAGE = 30;

    private const FULL_PER_PAGE = 50;

    /** Why a lead is on the list, in the words the CRA uses. */
    public const SOURCE_LABELS = [
        ExclusiveLeads::LEFTOVER => 'Leftover',
        ExclusiveLeads::HIGH_VALUE => 'High AOV / VIP',
        ExclusiveLeads::HANDLED => 'Handled twice',
        ExclusiveLeads::RANDOM => 'Random',
        ExclusiveLeads::POS => 'POS sale',
    ];

    public function index(Request $request, ExclusiveLeads $exclusive): View
    {
        $user = $request->user();
        $today = WorkingDate::realToday();
        $canViewAll = $user->can('exclusive_leads.view_all');
        $canManageSettings = $user->can('exclusive_leads.manage');
        $canSetLimits = $user->can('exclusive_leads.limits');

        $exclusiveCras = User::query()->whereNotNull('exclusive_from')->where('is_active', true)
            ->get()->sortBy(fn (User $cra) => mb_strtolower($cra->displayName()))->values();
        $visibleCras = $canViewAll ? $exclusiveCras : $exclusiveCras->where('id', $user->id)->values();

        $filters = $request->validate([
            'cra' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            // A date range (both ends, any order): the real days from–to.
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'type' => ['nullable', Rule::in(array_keys(Lead::TYPES))],
            'product' => ['nullable', 'string', 'max:255'],
            'show' => ['nullable', Rule::in(['all', 'unprocessed', 'processed'])],
            'full' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', Rule::in(['day', 'month'])],
        ]);
        $show = $filters['show'] ?? 'all';
        $search = trim((string) ($filters['q'] ?? '')) ?: null;
        $wholeMonth = ! $search && ($filters['view'] ?? 'day') === 'month';

        $cra = $visibleCras->firstWhere('id', (int) ($filters['cra'] ?? 0)) ?? $visibleCras->first();

        $data = [
            'cra' => $cra,
            'cras' => $visibleCras,
            'canViewAll' => $canViewAll,
            'canManageSettings' => $canManageSettings,
            'settingsCras' => $canManageSettings ? $this->settingsCras($exclusiveCras) : null,
            'canSetLimits' => $canSetLimits,
            'limitsNow' => ExclusiveLimits::on($today),
            'limitChanges' => $canSetLimits
                ? ExclusiveLeadLimit::whereDate('effective_from', '>=', $today->startOfMonth())->with('setter')->orderBy('effective_from')->get()
                : collect(),
            'today' => $today,
        ];

        if (! $cra) {
            return view('exclusive-leads.index', $data + ['month' => null]);
        }

        // The page is by real date, when she worked; each day's leads are from its paired lead day (WorkingDate).
        // The month and day: a date picks its month; never past today, never before the transfer.
        $date = isset($filters['date']) ? CarbonImmutable::parse($filters['date']) : null;
        $range = null;
        if (! empty($filters['from']) && ! empty($filters['to'])) {
            [$rangeFrom, $rangeTo] = collect([CarbonImmutable::parse($filters['from']), CarbonImmutable::parse($filters['to'])])->sort()->values()->all();
            $date = $rangeFrom;
            $range = $rangeFrom->equalTo($rangeTo) ? null : [$rangeFrom, $rangeTo];
        }
        $month = $date ?? (isset($filters['month']) ? CarbonImmutable::parse($filters['month'].'-01') : $today);
        $lag = WorkingDate::lagDays();
        $first = $cra->exclusive_from->addDays($lag)->startOfDay();
        $month = $month->startOfMonth()->max($first->startOfMonth());
        $metrics = $exclusive->month($cra, $month);

        $lastDay = $month->endOfMonth()->startOfDay()->min($today);
        $day = ($date ?? ($month->isSameMonth($today) ? $today : $lastDay))->startOfDay()->min($lastDay);
        $day = $day->max($month->max($first));
        $leadDay = $day->subDays($lag);

        // A search looks through all of the CRA's days; a range or the whole month through those days' lists; otherwise the day picked.
        $wholeMonth = $wholeMonth && $range === null;
        $listedBetween = $range ?? ($wholeMonth ? [$month->startOfMonth(), $month->endOfMonth()->startOfDay()] : null);
        $listing = $search === null && $listedBetween !== null;
        $scope = match (true) {
            $search !== null => $exclusive->search($cra, $search),
            $listing => $exclusive->leadsBetween($cra, ...$listedBetween),
            default => $exclusive->leadsFor($cra, $leadDay),
        };
        $listDetails = $listing ? $exclusive->listDetailsBetween($cra, ...$listedBetween) : null;
        $this->narrowed($scope, $filters)->with(['assignee', 'notesAuthor', 'callRecordings', 'attempts.user'])
            ->orderByRaw('lead_type = ? desc', [Lead::TYPE_CRD])
            ->orderBy('customer_name')
            ->orderByDesc('listed_on');

        $full = $request->boolean('full') && $show !== 'all';
        $perPage = $full ? self::FULL_PER_PAGE : self::PER_PAGE;
        $unprocessed = in_array($show, ['all', 'unprocessed'], true) ? (clone $scope)->unprocessed()->paginate($perPage)->withQueryString() : null;
        $processed = in_array($show, ['all', 'processed'], true) ? (clone $scope)->processed()->paginate($perPage, ['*'], 'processed_page')->withQueryString() : null;

        $shared = $data + [
            'search' => $search,
            'wholeMonth' => $wholeMonth,
            'range' => $search === null ? $range : null,
            'filters' => [
                'q' => $search,
                'cra' => (string) $cra->id,
                'type' => $filters['type'] ?? null,
                'product' => $filters['product'] ?? null,
                'month' => $month->format('Y-m'),
                'date' => $range === null ? $day->toDateString() : null,
                'from' => $range === null ? null : $range[0]->toDateString(),
                'to' => $range === null ? null : $range[1]->toDateString(),
                'view' => $wholeMonth ? 'month' : null,
            ],
            'products' => Product::crd()->orderBy('name')->pluck('name'),
            'listedDays' => $listDetails['days'] ?? null,
            'sources' => $listDetails['sources'] ?? ($search ? $exclusive->latestSources($cra) : $exclusive->sourcesFor($cra, $leadDay)),
            'canManage' => $user->can('segmentation.manage'),
            'canTransfer' => false,
            'statuses' => config('segmentation.statuses'),
            'optionalColumns' => config('segmentation.optional_columns'),
            'day' => $day,
            'leadDay' => $lag > 0 ? $leadDay : null,
            'show' => $show,
        ];

        if ($full) {
            return view('exclusive-leads.full', $shared + [
                'leads' => $unprocessed ?? $processed,
                ...($show === 'unprocessed' ? $this->followUps($cra, $filters) : []),
            ]);
        }

        $dayMetrics = collect($metrics['days'])->firstWhere('date', $day->toDateString());

        return view('exclusive-leads.index', $shared + [
            'month' => $month,
            'metrics' => $metrics,
            'dayMetrics' => $dayMetrics,
            'quotaNote' => $this->quotaNote($metrics),
            'unprocessed' => $unprocessed,
            'processed' => $processed,
            'prevMonth' => $month->subMonth()->format('Y-m'),
            'nextMonth' => $month->addMonth()->startOfMonth()->lessThanOrEqualTo($today) ? $month->addMonth()->format('Y-m') : null,
            'canPrev' => $month->startOfMonth()->greaterThan($first->startOfMonth()),
        ]);
    }

    /**
     * Narrow leads or reservations to the Type and Product picked.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @param  array<string, mixed>  $filters
     * @return TQuery
     */
    private function narrowed(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('lead_type', $type))
            ->when($filters['product'] ?? null, fn (Builder $q, string $product) => $q->where('product_name', $product));
    }

    /**
     * The Pending full view's follow-up lists: the CRA's, from any day, including those from before their transfer.
     *
     * @param  array<string, mixed>  $filters
     * @return array{callbacks: LengthAwarePaginator, reactivation: LengthAwarePaginator, reserved: LengthAwarePaginator, pancakeReserved: LengthAwarePaginator}
     */
    private function followUps(User $cra, array $filters): array
    {
        return app(LeadFollowUps::class)->lists(
            $this->narrowed(Lead::query()->where('assigned_to', $cra->id), $filters),
            $this->narrowed(PancakeReservation::query()->where('assigned_to', $cra->id), $filters),
            self::FULL_PER_PAGE,
        );
    }

    /**
     * Active CRAs that can move in, and the ones already in.
     *
     * @param  Collection<int, User>  $exclusiveCras
     * @return array{available: Collection<int, User>, current: Collection<int, User>}
     */
    private function settingsCras(Collection $exclusiveCras): array
    {
        return [
            'available' => LeadGenerator::cras()->whereNull('exclusive_from')->values(),
            'current' => $exclusiveCras,
        ];
    }

    /**
     * The month target against what the days actually plan, in words: "7,000 a month, November plans 6,900: ...".
     *
     * @param  array<string, mixed>  $metrics
     */
    private function quotaNote(array $metrics): ?string
    {
        $standard = $metrics['limits']['daily_leads'];
        $short = collect($metrics['days'])->filter(fn (array $day) => $day['quota'] > 0 && $day['quota'] !== $standard);
        $planned = $metrics['planned'];

        if ($planned['short_of_target'] === 0 && $short->isEmpty()) {
            return null;
        }

        $month = CarbonImmutable::parse($metrics['days'][0]['date'])->format('F');
        $text = $month.' plans '.number_format($planned['leads']).' leads';

        if ($short->isNotEmpty()) {
            $dates = $short->map(fn (array $day) => CarbonImmutable::parse($day['date']))->values();
            $range = $dates->count() === 1 ? $dates[0]->format('M j') : $dates->first()->format('M j').'–'.$dates->last()->format('j');
            $text .= ': '.$range.' ran at '.$short->pluck('quota')->unique()->map(fn (int $quota) => number_format($quota))->implode('/').' leads a day, every other day is '.number_format($standard);
        }

        return $text.'.';
    }
}
