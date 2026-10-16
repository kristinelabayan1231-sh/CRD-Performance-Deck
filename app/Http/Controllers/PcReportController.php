<?php

namespace App\Http\Controllers;

use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\PcReportEntry;
use App\Models\PcReportRow;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\PancakeSync;
use App\Support\ProductGroups;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PcReportController extends Controller
{
    /**
     * One set per CRA for the picked product and day: every page in the product's group, a split
     * page as its Dormant and Active rows, with the day's typed-in Total Inquiries and Total Engagement and the
     * # of Orders the CRA's Pancake account took on that page that day (by order date, not Creation/Update Time).
     */
    public function index(Request $request, PancakeSync $pancake): View
    {
        $today = WorkingDate::realToday();

        $filters = $request->validate([
            'product' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cra' => ['nullable', 'integer'],
        ]);

        $groups = ProductGroups::all();
        $group = collect($groups)->firstWhere('slug', $filters['product'] ?? null) ?? $groups[0];
        $day = CarbonImmutable::parse($filters['date'] ?? $today->toDateString())->min($today);

        $user = $request->user();
        $allCras = $user->can('pc_report.view_all') ? LeadGenerator::cras() : null;
        $cras = $allCras === null ? collect([$user]) : $allCras->when(
            $filters['cra'] ?? null,
            fn ($cras, $id) => $cras->where('id', (int) $id)->values(),
        );

        $saved = PcReportRow::whereIn('user_id', $cras->pluck('id'))->whereIn('page', $group['pages'])->get()
            ->groupBy(fn (PcReportRow $row) => $row->user_id.'|'.$row->page);
        $entries = PcReportEntry::whereIn('user_id', $cras->pluck('id'))->whereIn('page', $group['pages'])->whereDate('date', $day)->get()
            ->keyBy(fn (PcReportEntry $entry) => $entry->user_id.'|'.$entry->page.'|'.$entry->category);

        $orders = $this->orderCounts($cras, $group['pages'], $day, $pancake);

        $sets = $cras->mapWithKeys(fn (User $cra) => [$cra->id => collect($group['pages'])->flatMap(function (string $page) use ($cra, $saved, $entries, $orders) {
            $rows = $saved->get($cra->id.'|'.$page, collect())->keyBy('category');
            $categories = $rows->has(PcReportRow::DORMANT) ? array_keys(PcReportRow::SPLIT) : [''];
            // Orders only know their page, not when the conversation was created: a split page's Dormant and Active
            // rows share one # of Orders (shown once, across both rows).
            $pageOrders = $cra->pancake_name ? $orders['counts']->get(PancakeEngagement::staffKey($cra->pancake_name).'|'.$page, 0) : null;

            return array_map(function (string $category) use ($cra, $page, $rows, $entries, $pageOrders) {
                $row = $rows->get($category);
                $entry = $entries->get($cra->id.'|'.$page.'|'.$category);

                return [
                    'page' => $page,
                    'category' => $category,
                    'tagging' => $row?->tagging,
                    // Set per day: a day nobody set it for shows "Not set", whatever earlier days had.
                    'from' => $entry?->from_month?->format('Y-m') ?? '',
                    'to' => $entry?->to_month?->format('Y-m') ?? '',
                    'inquiries' => $entry?->inquiries,
                    'engagements' => $entry?->engagements,
                    'orders' => $category === PcReportRow::ACTIVE ? null : $pageOrders,
                    'orders_rows' => match ($category) {
                        PcReportRow::DORMANT => 2,
                        PcReportRow::ACTIVE => 0,
                        default => 1,
                    },
                ];
            }, $categories);
        })->all()]);

        return view('pc-report.index', [
            'groups' => $groups,
            'group' => $group,
            'day' => $day,
            'today' => $today,
            'allCras' => $allCras,
            'cras' => $cras,
            'sets' => $sets,
            'craId' => $filters['cra'] ?? null,
            'canEdit' => $user->can('pc_report.manage'),
            'ordersMissingPage' => $orders['missingPage'],
        ]);
    }

    /**
     * Save a row's Tagging (the CRA or anyone who sees every CRA), or its Creation/Update Time for one day (CRA
     * Supervisor).
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $setsTime = $request->hasAny(['from', 'to']);
        abort_unless($setsTime ? $user->can('pc_report.manage') : ((int) $request->input('cra') === $user->id || $user->can('pc_report.view_all')), 403);

        $data = $request->validate([
            ...$this->rowRules(),
            'category' => ['present', 'nullable', Rule::in(array_keys(PcReportRow::SPLIT))],
            'tagging' => ['sometimes', 'nullable', 'string', 'max:50'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m', 'after_or_equal:from'],
            'date' => [Rule::requiredIf($setsTime), 'date_format:Y-m-d', 'before_or_equal:'.WorkingDate::realToday()->toDateString()],
        ]);

        $key = ['user_id' => $data['cra'], 'page' => $data['page'], 'category' => $data['category'] ?? ''];

        if ($setsTime) {
            $this->entryFor($key, $data['date'])->fill([
                'from_month' => isset($data['from']) ? $data['from'].'-01' : null,
                'to_month' => isset($data['to']) ? $data['to'].'-01' : null,
            ])->save();
        } else {
            PcReportRow::updateOrCreate($key, ['tagging' => isset($data['tagging']) ? strtoupper(trim($data['tagging'])) : null]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Split a CRA's page into a Dormant and an Active row; the day it's split on gets their usual
     * Creation/Update Times (a day not set yet only), other days stay as they are.
     */
    public function split(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->rowRules(),
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.WorkingDate::realToday()->toDateString()],
        ]);

        foreach (PcReportRow::SPLIT as $category => [$from, $to]) {
            $key = ['user_id' => $data['cra'], 'page' => $data['page'], 'category' => $category];
            PcReportRow::firstOrCreate($key);

            $entry = $this->entryFor($key, $data['date']);
            if ($entry->from_month === null) {
                $entry->fill(['from_month' => $from.'-01', 'to_month' => $to ? $to.'-01' : null])->save();
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Put a split page back into one row.
     */
    public function merge(Request $request): JsonResponse
    {
        $data = $request->validate($this->rowRules());

        PcReportRow::where('user_id', $data['cra'])->where('page', $data['page'])->whereIn('category', array_keys(PcReportRow::SPLIT))->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Save a row's Total Inquiries or Total Engagement for a day, typed in by the CRA or anyone who sees every CRA.
     */
    public function entry(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->rowRules(),
            'category' => ['present', 'nullable', Rule::in(array_keys(PcReportRow::SPLIT))],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.WorkingDate::realToday()->toDateString()],
            'inquiries' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'engagements' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $user = $request->user();
        abort_unless((int) $data['cra'] === $user->id || $user->can('pc_report.view_all'), 403);

        $key = ['user_id' => $data['cra'], 'page' => $data['page'], 'category' => $data['category'] ?? ''];
        $this->entryFor($key, $data['date'])->fill(Arr::only($data, ['inquiries', 'engagements']))->save();

        return response()->json(['ok' => true]);
    }

    /**
     * The CRAs' orders on $day per seller and group page (canceled and deleted ones left out, like everywhere else),
     * keyed "SELLER|page", and whether some of them have no page yet: orders synced before pages were saved (Oct 14).
     * Such a day is fetched from Pancake again once, after the page is sent, so a reload fills them in.
     *
     * Matched on the Pancake page ID (Settings → Pancake Pages), since an order carries the page's name as Pancake
     * shows it, which can differ from Settings (e.g. "Sinuxyl Nasal Care PH" for "Sinuxyl Nasal Care"); by name,
     * ignoring case and spaces, only for a page Settings has no ID for.
     *
     * @param  Collection<int, User>  $cras
     * @param  list<string>  $pages
     * @return array{counts: Collection<string, int>, missingPage: bool}
     */
    private function orderCounts(Collection $cras, array $pages, CarbonImmutable $day, PancakeSync $pancake): array
    {
        $sellers = $cras->pluck('pancake_name')->filter()->map(PancakeEngagement::staffKey(...))->unique()->values();
        if ($sellers->isEmpty()) {
            return ['counts' => collect(), 'missingPage' => false];
        }

        $normalize = fn (?string $name) => Str::lower(Str::squish((string) $name));
        $byName = collect($pages)->keyBy($normalize);
        $byId = PancakePage::whereNotNull('page_id')->get(['name', 'page_id'])
            ->filter(fn (PancakePage $page) => $byName->has($normalize($page->name)))
            ->mapWithKeys(fn (PancakePage $page) => [(string) $page->page_id => $byName[$normalize($page->name)]]);

        $orders = PancakeOrder::crdProducts()->counted()
            ->whereDate('ordered_on', $day)
            ->whereIn('seller_name', $sellers)
            ->get(['seller_name', 'page_id', 'page_name']);

        $missingPage = $orders->contains(fn (PancakeOrder $order) => $order->page_id === null && $order->page_name === null);
        if ($missingPage && Cache::add('pc-report.page-refetch.'.$day->toDateString(), true, now()->addHour())) {
            $pancake->syncLater($day, force: true);
        }

        $counts = $orders
            ->map(fn (PancakeOrder $order) => [
                'seller' => PancakeEngagement::staffKey($order->seller_name),
                'page' => $byId->get((string) $order->page_id) ?? ($byId->contains($byName->get($normalize($order->page_name))) ? null : $byName->get($normalize($order->page_name))),
            ])
            ->filter(fn (array $order) => $order['page'] !== null)
            ->countBy(fn (array $order) => $order['seller'].'|'.$order['page']);

        return ['counts' => $counts, 'missingPage' => $missingPage];
    }

    /**
     * A row's saved day, or a new one for it.
     *
     * @param  array{user_id: int|string, page: string, category: string}  $key
     */
    private function entryFor(array $key, string $date): PcReportEntry
    {
        return PcReportEntry::where($key)->whereDate('date', $date)->firstOrNew([], [...$key, 'date' => $date]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rowRules(): array
    {
        return [
            'cra' => ['required', 'integer', 'exists:users,id'],
            'page' => ['required', 'string', Rule::in(collect(ProductGroups::all())->pluck('pages')->flatten()->all())],
        ];
    }
}
