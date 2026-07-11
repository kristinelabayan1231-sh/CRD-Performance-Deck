<?php

namespace App\Http\Controllers;

use App\Models\Cra;
use App\Models\CraCallStat;
use App\Support\DashboardPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SegmentationReportController extends Controller
{
    public function index(Request $request)
    {
        $period = in_array($request->get('period'), ['day', 'week', 'month']) ? $request->get('period') : 'day';
        $anchor = Carbon::parse($request->query('date', now()->toDateString()));
        $cras = Cra::orderBy('name')->get();
        $viewerCra = Cra::where('email', $request->user()->email)->first();

        if ($period === 'day') {
            $stats = CraCallStat::where('date', $anchor->toDateString())->get()->keyBy('cra_id');

            $rows = $cras->map(fn (Cra $cra) => $this->rowFromStat($cra, $stats->get($cra->id)));
            $daysReportedLabel = null;
        } else {
            [$start, $end] = DashboardPeriod::bounds($period, $anchor);
            $effectiveEnd = $end->isFuture() ? now()->startOfDay() : $end;
            $daysElapsed = max(1, $start->diffInDays($effectiveEnd) + 1);

            $statsByCra = CraCallStat::whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->get()
                ->groupBy('cra_id');

            $rows = $cras->map(fn (Cra $cra) => $this->rowFromStats($cra, $statsByCra->get($cra->id, collect()), $daysElapsed));
            $daysReportedLabel = "of {$daysElapsed} day" . ($daysElapsed === 1 ? '' : 's');
        }

        $totals = [
            'total_calls' => $rows->sum('total_calls'),
            'answered_calls' => $rows->sum('answered_calls'),
        ];
        $totals['pick_up_rate'] = $totals['total_calls'] > 0 ? $totals['answered_calls'] / $totals['total_calls'] : 0.0;

        return view('segmentation.index', [
            'period' => $period,
            'date' => $anchor,
            'periodLabel' => DashboardPeriod::label($period, $anchor),
            'rows' => $rows,
            'totals' => $totals,
            'reportedCount' => $rows->where('reported', true)->count(),
            'daysReportedLabel' => $daysReportedLabel,
            'prevDate' => $this->shift($period, $anchor, -1)->toDateString(),
            'nextDate' => $this->shift($period, $anchor, 1)->toDateString(),
            'nextIsFuture' => $this->shift($period, $anchor, 1)->startOfDay()->gt(now()->startOfDay()),
            'viewerCra' => $viewerCra,
        ]);
    }

    protected function rowFromStat(Cra $cra, ?CraCallStat $stat): array
    {
        return [
            'cra' => $cra,
            'stat_id' => $stat->id ?? null,
            'total_calls' => $stat->total_calls ?? 0,
            'answered_calls' => $stat->answered_calls ?? 0,
            'pick_up_rate' => $stat ? $stat->pickUpRate() : 0.0,
            'reported' => $stat !== null,
            'days_reported' => null,
        ];
    }

    protected function rowFromStats(Cra $cra, $stats, int $daysElapsed): array
    {
        $totalCalls = $stats->sum('total_calls');
        $answeredCalls = $stats->sum('answered_calls');

        return [
            'cra' => $cra,
            'total_calls' => $totalCalls,
            'answered_calls' => $answeredCalls,
            'pick_up_rate' => $totalCalls > 0 ? $answeredCalls / $totalCalls : 0.0,
            'reported' => $stats->isNotEmpty(),
            'days_reported' => $stats->count(),
        ];
    }

    protected function shift(string $period, Carbon $anchor, int $direction): Carbon
    {
        return match ($period) {
            'week' => $anchor->copy()->addDays(7 * $direction),
            'month' => $direction > 0 ? $anchor->copy()->addMonthNoOverflow() : $anchor->copy()->subMonthNoOverflow(),
            default => $anchor->copy()->addDays($direction),
        };
    }
}
