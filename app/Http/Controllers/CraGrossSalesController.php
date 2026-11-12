<?php

namespace App\Http\Controllers;

use App\Services\CraGrossSales;
use App\Services\LeadGenerator;
use App\Support\SalesGoals;
use App\Support\WorkingDate;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRA Gross Sales & IBSC: each CRA's Gross Sales and NET INCOME per month of a year, from the Actual Income cards.
 */
class CraGrossSalesController extends Controller
{
    public function index(Request $request, CraGrossSales $sales): View
    {
        $filters = $request->validate(['year' => ['nullable', 'integer', 'min:2020', 'max:2100']]);
        $year = (int) ($filters['year'] ?? WorkingDate::realToday()->year);
        $data = $sales->year(LeadGenerator::cras(), $year);

        return view('cra-gross-sales.index', [
            'year' => $year,
            'cras' => $data['rows'],
            'data' => $data,
            // Each CRA's monthly Net Income goal: a month under it shows red.
            'goal' => SalesGoals::craNetIncomeMonthly(),
        ]);
    }
}
