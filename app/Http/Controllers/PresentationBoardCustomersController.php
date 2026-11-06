<?php

namespace App\Http\Controllers;

use App\Services\PresentationBoard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The customers behind a Presentation Board figure, the same rows the figure counts.
 */
class PresentationBoardCustomersController extends Controller
{
    private const PER_PAGE = 50;

    public function __invoke(Request $request, PresentationBoard $board): View
    {
        $filters = $request->validate([
            'metric' => ['required', Rule::in(array_keys(PresentationBoard::LISTS))],
            'from' => ['required', 'date_format:Y-m'],
            'to' => ['required', 'date_format:Y-m', 'after_or_equal:from'],
        ]);

        $rows = $board->listed($filters['metric'], $filters['from'], $filters['to']);
        $page = Paginator::resolveCurrentPage();
        $shown = $board->details(array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE));
        [$title, $dateLabel] = PresentationBoard::LISTS[$filters['metric']];
        $month = fn (string $key) => CarbonImmutable::parse($key.'-01')->format('M Y');

        return view('presentation-board.customers', [
            'customers' => (new LengthAwarePaginator($shown, count($rows), self::PER_PAGE, $page, ['path' => Paginator::resolveCurrentPath()]))->withQueryString(),
            'title' => $title,
            'dateLabel' => $dateLabel,
            'rangeLabel' => $filters['from'] === $filters['to'] ? $month($filters['from']) : $month($filters['from']).' – '.$month($filters['to']),
            'from' => $filters['from'],
            'to' => $filters['to'],
        ]);
    }
}
