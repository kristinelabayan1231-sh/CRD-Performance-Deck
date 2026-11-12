<?php

namespace App\Http\Controllers;

use App\Models\ActualIncomeInput;
use App\Models\ActualIncomeStatement;
use App\Services\ActualIncome;
use App\Services\LeadGenerator;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Actual Income: the month's overall CRD card and each CRA's card. Anyone with actual_income.manage types the amounts
 * on the cards; they save as they change. A month taken from the CRD Sales Report sheet is shown as it is.
 */
class ActualIncomeController extends Controller
{
    public function index(Request $request, ActualIncome $income): View
    {
        $filters = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::parse(($filters['month'] ?? WorkingDate::realToday()->format('Y-m')).'-01');
        $cras = LeadGenerator::cras();

        // The cards alone, re-drawn after an amount is saved.
        $view = $request->header('X-Actual-Income-Cards') ? 'actual-income._cards' : 'actual-income.index';

        $data = $income->month($cras, $month);

        return view($view, [
            'month' => $month,
            'cras' => $data['shown'],
            'data' => $data,
            // A month taken from the CRD Sales Report sheet can't be typed in.
            'canEdit' => $request->user()->can('actual_income.manage') && ! $data['fromSheet'],
        ]);
    }

    /**
     * Save one typed-in amount: a CRA card's (cra set) or the CRD card's. Empty clears it.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'cra' => ['nullable', 'integer', Rule::in(LeadGenerator::cras()->pluck('id'))],
            'field' => ['required', 'string'],
            'value' => ['present', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        abort_if(ActualIncomeStatement::whereDate('month', $data['month'].'-01')->exists(), 422, 'This month\'s figures come from the CRD Sales Report sheet.');

        $isDepartment = blank($data['cra'] ?? null);
        abort_unless(array_key_exists($data['field'], ActualIncome::inputs($isDepartment)), 422, $isDepartment
            ? 'This amount is typed in on the CRA cards.' : 'This amount is typed in on the CRD card.');

        $key = ['month' => $data['month'].'-01', 'user_id' => $isDepartment ? null : (int) $data['cra'], 'field' => $data['field']];
        $existing = ActualIncomeInput::whereDate('month', $key['month'])->where('field', $key['field'])
            ->when($isDepartment, fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', $key['user_id']));

        if ($data['value'] === null) {
            $existing->delete();
        } else {
            ($existing->first() ?? new ActualIncomeInput($key))
                ->fill(['amount' => $data['value'], 'updated_by' => $request->user()->id])->save();
        }

        return response()->json(['ok' => true]);
    }
}
