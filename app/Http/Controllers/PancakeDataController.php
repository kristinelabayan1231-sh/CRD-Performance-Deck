<?php

namespace App\Http\Controllers;

use App\Models\Cra;
use App\Models\FacebookPage;
use App\Services\PancakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PancakeDataController extends Controller
{
    public function index(Request $request, PancakeService $pancake)
    {
        $pages = FacebookPage::orderBy('page_name')->get();
        $cras = Cra::with('assignments.facebookPage')->orderBy('name')->get();

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
                'dailyRows' => null,
                'error' => null,
            ]);
        }

        $view = $request->query('view') === 'cra' ? 'cra' : 'page';

        return $view === 'cra'
            ? $this->indexByCra($request, $pancake, $pages, $cras)
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
            'dailyRows' => null,
            'error' => $error,
        ]);
    }

    protected function indexByCra(Request $request, PancakeService $pancake, $pages, $cras)
    {
        $error = null;
        $dailyRows = null;
        $activeCra = null;

        if ($cras->isEmpty()) {
            $error = 'No CRAs configured yet. Add one under Admin → CRAs.';
        } else {
            $activeCraId = (int) $request->query('cra', $cras->first()->id);
            $activeCra = $cras->firstWhere('id', $activeCraId) ?? $cras->first();

            if ($activeCra->assignments->isEmpty()) {
                $error = 'This CRA has no assigned Facebook pages / months yet.';
            } else {
                // Data is attributed to the whole calendar month, not just the
                // assigned week — so multiple week-rows for the same page/month
                // must collapse into a single fetch, or totals would multiply.
                $uniqueMonths = $activeCra->assignments
                    ->unique(fn ($assignment) => "{$assignment->facebook_page_id}:{$assignment->year}:{$assignment->month}");

                $byDate = [];
                $hadSuccess = false;

                try {
                    foreach ($uniqueMonths as $assignment) {
                        $monthEngagement = $pancake->getEngagement(
                            $assignment->facebookPage,
                            $assignment->monthStart()->toDateString(),
                            $assignment->monthEnd()->toDateString(),
                        );

                        if (! $monthEngagement) {
                            continue;
                        }

                        $hadSuccess = true;

                        $series = collect($monthEngagement['series'] ?? [])->keyBy('name');
                        $inboxData = $series['inbox']['data'] ?? [];
                        $totalData = $series['total']['data'] ?? [];
                        $orderData = $series['order_count']['data'] ?? [];

                        foreach ($monthEngagement['categories'] ?? [] as $i => $dateLabel) {
                            $date = Carbon::createFromFormat('d/m/Y', $dateLabel)->startOfDay();
                            $key = $date->toDateString();

                            $byDate[$key] ??= ['date' => $date, 'inquiries' => 0, 'total_engagement' => 0, 'orders' => 0];
                            $byDate[$key]['inquiries'] += $inboxData[$i] ?? 0;
                            $byDate[$key]['total_engagement'] += $totalData[$i] ?? 0;
                            $byDate[$key]['orders'] += $orderData[$i] ?? 0;
                        }
                    }

                    if ($hadSuccess) {
                        ksort($byDate);
                        $dailyRows = array_values($byDate);
                    }
                } catch (\Throwable $e) {
                    $error = 'Could not load engagement data from Pancake: ' . $e->getMessage();
                }
            }
        }

        return view('pancake.index', [
            'pages' => $pages,
            'cras' => $cras,
            'view' => 'cra',
            'activePage' => null,
            'activeCra' => $activeCra,
            'startDate' => null,
            'endDate' => null,
            'engagement' => null,
            'previousEngagement' => null,
            'change' => null,
            'compareRangeLabel' => null,
            'dailyRows' => $dailyRows,
            'error' => $error,
        ]);
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
