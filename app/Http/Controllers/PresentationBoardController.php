<?php

namespace App\Http\Controllers;

use App\Services\PancakePageBackfill;
use App\Services\PresentationBoard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PresentationBoardController extends Controller
{
    public function __invoke(Request $request, PresentationBoard $board, PancakePageBackfill $backfill): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m', 'after_or_equal:from'],
            'period' => ['nullable', Rule::in(array_keys(PresentationBoard::PERIODS))],
        ], ['to.after_or_equal' => 'The "to" month must be on or after the "from" month.']);

        $snapshot = $board->snapshot();
        $period = $filters['period'] ?? 'weekly';

        if (! $snapshot) {
            return view('presentation-board.index', ['snapshot' => null, 'progress' => $backfill->progress(), 'period' => $period]);
        }

        $months = array_keys($snapshot['months']);
        $from = max($filters['from'] ?? $months[0], $months[0]);
        $to = min($filters['to'] ?? end($months), end($months));

        return view('presentation-board.index', [
            ...$board->range($snapshot, $from, $to, $period),
            'snapshot' => $snapshot,
            'monthOptions' => collect($months)->mapWithKeys(fn (string $month) => [$month => CarbonImmutable::parse($month.'-01')->format('M Y')])->all(),
            'from' => $from,
            'to' => $to,
            'period' => $period,
            'progress' => $backfill->progress(),
        ]);
    }
}
