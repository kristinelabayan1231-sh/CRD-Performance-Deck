<?php

namespace App\Http\Controllers;

use App\Services\DailyIncomeStatement;
use App\Services\LeadGenerator;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Daily I.S.: the department's and each CRA's income statement per product for a day. Anyone with
 * daily_is.view_all sees the department and every CRA, or one CRA they pick, and sets each month's % Projected
 * Returns; otherwise a CRA sees their own.
 */
class DailyIncomeStatementController extends Controller
{
    public function index(Request $request, DailyIncomeStatement $statement): View
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
            'canSetReturns' => $canViewAll,
        ]);
    }

    /**
     * Set the month's % Projected Returns (for every product card that month); empty clears it.
     */
    public function updateReturns(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('daily_is.view_all'), 403);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'returns_pct' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cra' => ['nullable', 'integer'],
        ]);

        $month = CarbonImmutable::parse($data['month'].'-01');
        DailyIncomeStatement::saveReturnsPct($month, isset($data['returns_pct']) ? (float) $data['returns_pct'] : null, $request->user());

        return redirect()->route('daily-is.index', array_filter(['date' => $data['date'] ?? null, 'cra' => $data['cra'] ?? null]))
            ->with('status', '% Projected Returns for '.$month->format('F Y').' saved.');
    }
}
