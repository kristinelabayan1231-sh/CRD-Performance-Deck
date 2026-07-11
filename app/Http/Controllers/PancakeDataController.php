<?php

namespace App\Http\Controllers;

use App\Models\Cra;
use App\Models\CraPcDayStat;
use App\Models\FacebookPage;
use App\Models\PcDayStat;
use App\Services\PancakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PancakeDataController extends Controller
{
    public function index(Request $request, PancakeService $pancake)
    {
        $pages = FacebookPage::orderBy('page_name')->get();
        $cras = Cra::with('pcAssignments.pc.facebookPage')->orderBy('name')->get();

        if ($pages->isEmpty()) {
            return view('pancake.index', [
                'pages' => $pages,
                'cras' => $cras,
                'view' => 'page',
                'activePage' => null,
                'activeCra' => null,
                'startDate' => null,
                'endDate' => null,
                'engagement' => null,
                'previousEngagement' => null,
                'change' => null,
                'compareRangeLabel' => null,
                'dayTables' => null,
                'error' => null,
            ]);
        }

        $view = $request->query('view') === 'cra' ? 'cra' : 'page';

        return $view === 'cra'
            ? $this->indexByCra($request, $pages, $cras)
            : $this->indexByPage($request, $pancake, $pages, $cras);
    }

    protected function indexByPage(Request $request, PancakeService $pancake, $pages, $cras)
    {
        $activePageId = $request->query('page', $pages->first()->page_id);
        $activePage = $pages->firstWhere('page_id', $activePageId) ?? $pages->first();

        $startDate = $request->query('start_date', now()->subDays(6)->format('Y-m-d'));
        $endDate = $request->query('end_date', now()->format('Y-m-d'));

        // Compare against the immediately preceding period of equal length.
        $periodLength = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $previousEnd = Carbon::parse($startDate)->subDay();
        $previousStart = $previousEnd->copy()->subDays($periodLength - 1);
        $compareRangeLabel = $previousStart->format('m/d/Y') . ' - ' . $previousEnd->format('m/d/Y');

        $error = null;
        $engagement = null;
        $previousEngagement = null;
        $change = null;

        try {
            $engagement = $pancake->getEngagement($activePage, $startDate, $endDate);
            $previousEngagement = $pancake->getEngagement($activePage, $previousStart->toDateString(), $previousEnd->toDateString());

            if ($engagement && $previousEngagement) {
                $change = $this->computeChange($engagement, $previousEngagement);
            }
        } catch (\Throwable $e) {
            $error = 'Could not load engagement data from Pancake: ' . $e->getMessage();
        }

        return view('pancake.index', [
            'pages' => $pages,
            'cras' => $cras,
            'view' => 'page',
            'activePage' => $activePage,
            'activeCra' => null,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'engagement' => $engagement,
            'previousEngagement' => $previousEngagement,
            'change' => $change,
            'compareRangeLabel' => $compareRangeLabel,
            'dayTables' => null,
            'error' => $error,
        ]);
    }

    protected function indexByCra(Request $request, $pages, $cras)
    {
        $startDate = $request->query('start_date', now()->subDays(6)->format('Y-m-d'));
        $endDate = $request->query('end_date', now()->format('Y-m-d'));

        $error = null;
        $activeCra = null;
        $dayTables = [];

        if ($cras->isEmpty()) {
            $error = 'No CRAs configured yet. Add one under Admin → CRAs.';
        } else {
            $activeCraId = (int) $request->query('cra', $cras->first()->id);
            $activeCra = $cras->firstWhere('id', $activeCraId) ?? $cras->first();

            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->startOfDay();

            if ($end->lt($start)) {
                [$start, $end] = [$end, $start];
            }

            // Keep the day-by-day layout manageable.
            if ($start->diffInDays($end) > 30) {
                $start = $end->copy()->subDays(30);
            }

            if ($activeCra->pcAssignments->isEmpty()) {
                $error = 'This CRA has no cohorts set yet. Set one under Admin → CRAs.';
            } else {
                $pcIds = $activeCra->pcAssignments->pluck('pc_id')->unique();

                $pcStats = PcDayStat::whereIn('pc_id', $pcIds)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->get()
                    ->keyBy(fn ($stat) => $stat->pc_id . ':' . $stat->date);

                $craStats = CraPcDayStat::where('cra_id', $activeCra->id)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->get()
                    ->keyBy(fn ($stat) => $stat->pc_id . ':' . $stat->date);

                $cursor = $start->copy();
                while ($cursor->lte($end)) {
                    $assignments = $activeCra->assignmentsForWeek($cursor);

                    $rows = [];
                    $totals = ['inquiries' => 0, 'engagement' => 0, 'orders' => 0, 'amount' => 0.0];

                    foreach ($assignments as $assignment) {
                        $key = $assignment->pc_id . ':' . $cursor->toDateString();
                        $pcStat = $pcStats->get($key);
                        $craStat = $craStats->get($key);

                        // Engagement: manual override wins over the synced
                        // PC-level number (Pancake's UI metric differs from
                        // what its public API exposes).
                        $engagement = $craStat?->engagement ?? $pcStat?->engagement;

                        $rows[] = [
                            'assignment' => $assignment,
                            'inquiries' => $craStat?->inquiries,
                            'engagement' => $engagement,
                            'orders' => $pcStat?->orders,
                            'amount' => $craStat?->amount,
                            'tagging' => $craStat?->tagging,
                        ];

                        $totals['inquiries'] += $craStat?->inquiries ?? 0;
                        $totals['engagement'] += $engagement ?? 0;
                        $totals['orders'] += $pcStat?->orders ?? 0;
                        $totals['amount'] += $craStat?->amount ?? 0.0;
                    }

                    if ($rows !== []) {
                        $dayTables[] = [
                            'date' => $cursor->copy(),
                            'rows' => $rows,
                            'totals' => $totals,
                        ];
                    }

                    $cursor->addDay();
                }
            }
        }

        return view('pancake.index', [
            'pages' => $pages,
            'cras' => $cras,
            'view' => 'cra',
            'activePage' => null,
            'activeCra' => $activeCra,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'engagement' => null,
            'previousEngagement' => null,
            'change' => null,
            'compareRangeLabel' => null,
            'dayTables' => $dayTables,
            'error' => $error,
        ]);
    }

    public function updateEntry(Request $request)
    {
        $validated = $request->validate([
            'cra_id' => 'required|exists:cras,id',
            'pc_id' => 'required|exists:pcs,id',
            'date' => 'required|date',
            'engagement' => 'nullable|integer|min:0',
            'amount' => 'nullable|numeric|min:0',
            'tagging' => 'nullable|string|max:255',
        ]);

        // Only touch the manual fields — inquiries belongs to the sync.
        CraPcDayStat::updateOrCreate(
            [
                'cra_id' => $validated['cra_id'],
                'pc_id' => $validated['pc_id'],
                'date' => $validated['date'],
            ],
            [
                'engagement' => $validated['engagement'] ?? null,
                'amount' => $validated['amount'] !== null && $validated['amount'] !== '' ? $validated['amount'] : null,
                'tagging' => $validated['tagging'] ?: null,
            ],
        );

        return back()->with('status', 'Entry saved.');
    }

    protected function computeChange(array $current, array $previous): array
    {
        $pctChange = function (float $current, float $previous): float {
            if ($previous == 0.0) {
                return $current == 0.0 ? 0.0 : 100.0;
            }

            return round((($current - $previous) / $previous) * 100, 2);
        };

        return [
            'total' => $pctChange($current['total'], $previous['total']),
            'inbox' => $pctChange($current['inbox'], $previous['inbox']),
            'comment' => $pctChange($current['comment'], $previous['comment']),
        ];
    }
}
