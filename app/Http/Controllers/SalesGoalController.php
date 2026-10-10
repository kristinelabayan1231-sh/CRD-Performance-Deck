<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\LeadGenerator;
use App\Support\SalesGoals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesGoalController extends Controller
{
    public function index(): View
    {
        return view('settings.sales-goals', [
            'craDaily' => SalesGoals::craDaily(),
            'crdMonthly' => SalesGoals::crdMonthly(),
            'netIncomeMonthly' => SalesGoals::netIncomeMonthly(),
            'cras' => LeadGenerator::cras(),
            'lastChange' => Setting::whereIn('key', [SalesGoals::CRA_DAILY, SalesGoals::CRD_MONTHLY, SalesGoals::NET_INCOME_MONTHLY])->with('editor')->latest('updated_at')->first(),
        ]);
    }

    /**
     * Save the general goals, the net income goal (blank = none) and any CRA's own daily goal (blank = use the general one).
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cra_daily' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'crd_monthly' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'net_income_monthly' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'cra_goals' => ['nullable', 'array'],
            'cra_goals.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ], [], [
            'cra_daily' => 'CRA daily goal',
            'crd_monthly' => 'CRD monthly goal',
            'net_income_monthly' => 'net income goal',
            'cra_goals.*' => 'CRA daily goal',
        ]);

        Setting::put(SalesGoals::CRA_DAILY, $data['cra_daily'], $request->user());
        Setting::put(SalesGoals::CRD_MONTHLY, $data['crd_monthly'], $request->user());
        Setting::put(SalesGoals::NET_INCOME_MONTHLY, $data['net_income_monthly'] ?? null, $request->user());

        $cras = LeadGenerator::cras()->keyBy('id');
        foreach ($data['cra_goals'] ?? [] as $id => $goal) {
            $cras->get((int) $id)?->update(['daily_sales_goal' => $goal === null || $goal === '' ? null : $goal]);
        }

        return back()->with('status', 'Sales goals saved.');
    }
}
