<?php

namespace App\Http\Controllers;

use App\Services\DailyIncomeStatement;
use App\Services\LeadGenerator;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Daily I.S.: the department's and each CRA's income statement per product for a day. Anyone with
 * daily_is.view_all sees the department and every CRA, or one CRA they pick; otherwise a CRA sees their own.
 */
class DailyIncomeStatementController extends Controller
{
    public function __invoke(Request $request, DailyIncomeStatement $statement): View
    {
        $user = $request->user();
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'cra' => ['nullable', 'integer'],
        ]);
        $day = CarbonImmutable::parse($filters['date'] ?? $today->toDateString());

        $canViewAll = $user->can('daily_is.view_all');
        $allCras = LeadGenerator::cras();
        $picked = $canViewAll ? $allCras->firstWhere('id', (int) ($filters['cra'] ?? 0)) : null;
        $cras = match (true) {
            ! $canViewAll => $allCras->where('id', $user->id)->values(),
            $picked !== null => collect([$picked]),
            default => $allCras,
        };

        return view('daily-is.index', [
            'day' => $day,
            'today' => $today,
            'cras' => $cras,
            'allCras' => $canViewAll ? $allCras : null,
            'craId' => $picked?->id,
            'showDepartment' => $canViewAll && $picked === null,
            'data' => $statement->day($cras, $day),
            'lines' => DailyIncomeStatement::LINES,
        ]);
    }
}
