<?php

namespace App\Http\Controllers;

use App\Models\CraGrossSale;
use App\Models\User;
use App\Services\CraGrossSales;
use App\Services\LeadGenerator;
use App\Support\SalesGoals;
use App\Support\WorkingDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * CRA Gross Sales & IBSC: the year so far per CRA (Gross, Net Income, running orders and AOV, ACB) and the months, one
 * figure at a time (?show: Gross above Net Income, POS orders or AOV). The page opens read-only; anyone
 * with cra_gross_sales.manage (CRA Supervisor, Team Lead) clicks Edit (?edit=1) to type the figures, which save as they
 * change.
 */
class CraGrossSalesController extends Controller
{
    /** What the months show: key => tab label. */
    public const SHOWS = ['sales' => 'Gross & NI', 'orders' => 'Orders', 'aov' => 'AOV'];

    public function index(Request $request, CraGrossSales $sales): View
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'show' => ['nullable', Rule::in(array_keys(self::SHOWS))],
        ]);
        $year = (int) ($filters['year'] ?? WorkingDate::realToday()->year);
        $data = $sales->year(LeadGenerator::cras(), $year);
        $canManage = $request->user()->can('cra_gross_sales.manage');

        // The table alone, re-drawn after a figure is saved.
        $view = $request->header('X-Cra-Gross-Sales-Table') ? 'cra-gross-sales._table' : 'cra-gross-sales.index';

        return view($view, [
            'year' => $year,
            'cras' => $data['rows'],
            'data' => $data,
            'canManage' => $canManage,
            'canEdit' => $canManage && $request->boolean('edit'),
            // The months show Gross above Net Income, the POS orders or the AOV.
            'show' => $filters['show'] ?? 'sales',
            'shows' => self::SHOWS,
            // Each CRA's monthly Net Income goal: a month under it shows red.
            'goal' => SalesGoals::craNetIncomeMonthly(),
        ]);
    }

    /**
     * Save one CRA's Gross or Net for a month. Empty clears it; a month with both cleared shows —.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'cra' => ['required', 'integer', Rule::exists(User::class, 'id')],
            'field' => ['required', Rule::in(['gross', 'net'])],
            'value' => ['present', 'nullable', 'numeric', 'min:-1000000000', 'max:1000000000'],
        ]);

        $sale = CraGrossSale::whereDate('month', $data['month'].'-01')->where('user_id', $data['cra'])->first()
            ?? new CraGrossSale(['month' => $data['month'].'-01', 'user_id' => (int) $data['cra']]);
        $sale->fill([$data['field'] => $data['value'], 'updated_by' => $request->user()->id]);

        if ($sale->gross === null && $sale->net === null) {
            if ($sale->exists) {
                $sale->delete();
            }
        } else {
            $sale->save();
        }

        return response()->json(['ok' => true]);
    }
}
