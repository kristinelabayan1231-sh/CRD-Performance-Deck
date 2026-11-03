<?php

namespace App\Http\Controllers;

use App\Services\CrdBoard;
use App\Support\WorkingDate;
use Illuminate\View\View;

/**
 * The CRD Board's contents, loaded when the board opens on the dashboard: the Daily I.S. figures, worked out fresh.
 */
class DashboardBoardController extends Controller
{
    public function __invoke(CrdBoard $board): View
    {
        return view('dashboard.board', [
            ...$board->for(WorkingDate::realToday()),
            'asOf' => now(config('segmentation.timezone')),
        ]);
    }
}
