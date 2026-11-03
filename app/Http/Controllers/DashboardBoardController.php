<?php

namespace App\Http\Controllers;

use App\Services\CrdBoard;
use App\Support\WorkingDate;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The CRD Board's contents, loaded when the board opens on the dashboard: the Daily I.S. figures, worked out fresh.
 * ?day=yesterday shows yesterday as a whole day instead of today's running figures.
 */
class DashboardBoardController extends Controller
{
    public function __invoke(Request $request, CrdBoard $board): View
    {
        $yesterday = ($request->validate(['day' => ['nullable', 'in:today,yesterday']])['day'] ?? null) === 'yesterday';
        $day = WorkingDate::realToday();

        return view('dashboard.board', [
            ...$board->for($yesterday ? $day->subDay() : $day, $yesterday),
            'asOf' => $yesterday ? null : now(config('segmentation.timezone')),
        ]);
    }
}
