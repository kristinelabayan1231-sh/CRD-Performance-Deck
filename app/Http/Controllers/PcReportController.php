<?php

namespace App\Http\Controllers;

use App\Models\PcReportEntry;
use App\Models\PcReportRow;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Support\ProductGroups;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PcReportController extends Controller
{
    /**
     * One set per CRA for the picked product and day: every page in the product's group, a split
     * page as its Dormant and Active rows, with the day's typed-in Total Inquiries and Total Engagement.
     */
    public function index(Request $request): View
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

        $sets = $cras->mapWithKeys(fn (User $cra) => [$cra->id => collect($group['pages'])->flatMap(function (string $page) use ($cra, $saved, $entries) {
            $rows = $saved->get($cra->id.'|'.$page, collect())->keyBy('category');
            $categories = $rows->has(PcReportRow::DORMANT) ? array_keys(PcReportRow::SPLIT) : [''];

            return array_map(function (string $category) use ($cra, $page, $rows, $entries) {
                $row = $rows->get($category);
                $entry = $entries->get($cra->id.'|'.$page.'|'.$category);

                return [
                    'page' => $page,
                    'category' => $category,
                    'tagging' => $row?->tagging,
                    'from' => $row?->from_month?->format('Y-m') ?? '',
                    'to' => $row?->to_month?->format('Y-m') ?? '',
                    'inquiries' => $entry?->inquiries,
                    'engagements' => $entry?->engagements,
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
        ]);
    }

    /**
     * Save a row's Tagging (the CRA or anyone who sees every CRA) or its Creation/Update Time (CRA Supervisor).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->rowRules(),
            'category' => ['present', 'nullable', Rule::in(array_keys(PcReportRow::SPLIT))],
            'tagging' => ['sometimes', 'nullable', 'string', 'max:50'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m', 'after_or_equal:from'],
        ]);

        $user = $request->user();
        $setsTime = $request->hasAny(['from', 'to']);
        abort_unless($setsTime ? $user->can('pc_report.manage') : ((int) $data['cra'] === $user->id || $user->can('pc_report.view_all')), 403);

        $values = $setsTime
            ? ['from_month' => isset($data['from']) ? $data['from'].'-01' : null, 'to_month' => isset($data['to']) ? $data['to'].'-01' : null]
            : ['tagging' => isset($data['tagging']) ? strtoupper(trim($data['tagging'])) : null];

        PcReportRow::updateOrCreate(['user_id' => $data['cra'], 'page' => $data['page'], 'category' => $data['category'] ?? ''], $values);

        return response()->json(['ok' => true]);
    }

    /**
     * Split a CRA's page into a Dormant and an Active row with their usual Creation/Update Times.
     */
    public function split(Request $request): JsonResponse
    {
        $data = $request->validate($this->rowRules());

        foreach (PcReportRow::SPLIT as $category => [$from, $to]) {
            PcReportRow::firstOrCreate(
                ['user_id' => $data['cra'], 'page' => $data['page'], 'category' => $category],
                ['from_month' => $from.'-01', 'to_month' => $to ? $to.'-01' : null],
            );
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
        PcReportEntry::where($key)->whereDate('date', $data['date'])->firstOrNew([], [...$key, 'date' => $data['date']])
            ->fill(Arr::only($data, ['inquiries', 'engagements']))
            ->save();

        return response()->json(['ok' => true]);
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
