<?php

namespace App\Http\Controllers;

use App\Models\PcReportRow;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\PcInquiries;
use App\Support\ProductGroups;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\Process\PhpExecutableFinder;

class PcReportController extends Controller
{
    /** A split page's rows and the months each starts with: Dormant May 2024 – Dec 2025, Active 2026 onwards. */
    private const SPLIT = [
        PcReportRow::DORMANT => ['2024-05', '2025-12'],
        PcReportRow::ACTIVE => ['2026-01', null],
    ];

    /**
     * One set per CRA for the picked product and day: every page in the product's group, a split
     * page as its Dormant and Active rows, with Total Inquiries once the Creation/Update Time is set.
     */
    public function index(Request $request, PcInquiries $inquiries): View
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
        $pages = PcInquiries::pages();

        $sets = $cras->mapWithKeys(fn (User $cra) => [$cra->id => collect($group['pages'])->flatMap(function (string $page) use ($cra, $saved, $pages, $inquiries, $day, $today) {
            $rows = $saved->get($cra->id.'|'.$page, collect())->keyBy('category');
            $categories = $rows->has(PcReportRow::DORMANT) ? array_keys(self::SPLIT) : [''];
            $pancakePage = $pages->get(strtoupper($page));

            return array_map(function (string $category) use ($page, $rows, $pancakePage, $inquiries, $day, $today) {
                $row = $rows->get($category);
                $from = $row?->from_month;
                $to = $row?->to_month ?? ($from ? $today : null);
                $days = $from && $pancakePage ? PcInquiries::days($row) : [];
                // Counts show once the row was synced; days added since (an "onwards" row grows daily) show as not synced.
                $synced = $row?->synced_at !== null;

                return [
                    'page' => $page,
                    'category' => $category,
                    'rowId' => $row?->id,
                    'tagging' => $row?->tagging,
                    'from' => $from?->format('Y-m') ?? '',
                    'to' => $row?->to_month?->format('Y-m') ?? '',
                    'canSync' => $days !== [],
                    'inquiries' => $synced ? $inquiries->count($pancakePage, $from, $to, $day) : null,
                    'engagements' => $synced ? $row->engagements : null,
                    'unsyncedDays' => $days === [] ? 0 : count(PcInquiries::missingDays($pancakePage, $days)),
                    'syncedAt' => $row?->synced_at,
                    'progress' => $row ? $this->progress($row) : null,
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
            'category' => ['present', 'nullable', Rule::in(array_keys(self::SPLIT))],
            'tagging' => ['sometimes', 'nullable', 'string', 'max:50'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m', 'after_or_equal:from'],
        ]);

        $user = $request->user();
        $setsTime = $request->hasAny(['from', 'to']);
        abort_unless($setsTime ? $user->can('pc_report.manage') : ((int) $data['cra'] === $user->id || $user->can('pc_report.view_all')), 403);

        // New months: the engagements and last sync were for the old ones.
        $values = $setsTime
            ? ['from_month' => isset($data['from']) ? $data['from'].'-01' : null, 'to_month' => isset($data['to']) ? $data['to'].'-01' : null, 'engagements' => null, 'synced_at' => null]
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

        foreach (self::SPLIT as $category => [$from, $to]) {
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

        PcReportRow::where('user_id', $data['cra'])->where('page', $data['page'])->whereIn('category', array_keys(self::SPLIT))->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Start a row's Sync (its inquiries and engagements) in the background; the page then asks for its progress.
     */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([...$this->rowRules(), 'category' => ['present', 'nullable', Rule::in(array_keys(self::SPLIT))]]);
        $user = $request->user();
        abort_unless((int) $data['cra'] === $user->id || $user->can('pc_report.view_all'), 403);

        $row = PcReportRow::where(['user_id' => $data['cra'], 'page' => $data['page'], 'category' => $data['category'] ?? ''])->first();
        $page = PcInquiries::pages()->get(strtoupper($data['page']));
        abort_if(! $row || $row->from_month === null || ! $page, 422, 'Set the Creation/Update Time first.');

        $busy = Cache::get(PcReportRow::pageBusyKey($page->id));
        if ($busy !== null && $busy !== $row->id) {
            return response()->json(['status' => 'failed', 'message' => 'Another row of this page is syncing; try again when it finishes.'], 409);
        }

        if ($busy === null) {
            $row->putProgress(['status' => 'starting', 'done' => 0, 'total' => count(PcInquiries::days($row)), 'at' => now()->getTimestamp()]);
            // Its own process, so the fetching neither holds up this page nor stops when the page is closed.
            // PHP by its full path: a web request's PATH may not include it.
            $php = escapeshellarg((new PhpExecutableFinder)->find(false) ?: 'php');
            Process::path(base_path())->run("nohup {$php} artisan pc-report:sync ".(int) $row->id.' >> storage/logs/pc-report-sync.log 2>&1 &');
        }

        return response()->json($this->progress($row));
    }

    /**
     * A row's Sync progress, for its progress bar.
     */
    public function syncProgress(Request $request, PcReportRow $row): JsonResponse
    {
        $user = $request->user();
        abort_unless($row->user_id === $user->id || $user->can('pc_report.view_all'), 403);

        return response()->json($this->progress($row) ?? ['status' => 'idle']);
    }

    /**
     * The row's progress; a sync that stopped reporting (cut off by a restart) shows as stopped.
     *
     * @return array{status: string, done?: int, total?: int, message?: string}|null
     */
    private function progress(PcReportRow $row): ?array
    {
        $progress = $row->progress();
        $page = PcInquiries::pages()->get(strtoupper($row->page));
        $running = in_array($progress['status'] ?? null, ['starting', 'running'], true);
        $starting = ($progress['status'] ?? null) === 'starting' && now()->getTimestamp() - ($progress['at'] ?? 0) < 60;

        if ($running && ! $starting && $page && Cache::get(PcReportRow::pageBusyKey($page->id)) === null) {
            return ['status' => 'stopped', 'message' => 'The sync stopped before finishing; click Sync to carry on.'];
        }

        return $progress;
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
