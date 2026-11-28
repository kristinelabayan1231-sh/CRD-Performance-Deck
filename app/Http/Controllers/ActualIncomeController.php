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
use Illuminate\View\View;

/**
 * Actual Income: the month's overall CRD card, each CRA's card and the staff cards (config actual_income.staff). Every month opens read-only; anyone with
 * actual_income.manage (CRA Supervisor, Team Lead) clicks Edit (?edit=1) to type the amounts, which save as they change.
 * A month taken from the CRD Sales Report sheet is shown as it is, and in edit mode its lines can be typed over.
 */
class ActualIncomeController extends Controller
{
    public function index(Request $request, ActualIncome $income): View
    {
        $filters = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::parse(($filters['month'] ?? WorkingDate::realToday()->format('Y-m')).'-01');
        $cras = LeadGenerator::crasOfMonth($month);

        // The cards alone, re-drawn after an amount is saved.
        $view = $request->header('X-Actual-Income-Cards') ? 'actual-income._cards' : 'actual-income.index';

        $data = $income->month($cras, $month);
        $canManage = $request->user()->can('actual_income.manage');

        return view($view, [
            'month' => $month,
            'cras' => $data['shown'],
            'data' => $data,
            'canManage' => $canManage,
            'canEdit' => $canManage && $request->boolean('edit'),
            'sheetInputs' => ActualIncome::sheetInputs(),
        ]);
    }

    /**
     * Save one typed-in amount: a CRA card's (cra set), a staff card's (staff: its CRD - MC row) or the CRD card's.
     * Empty clears it (a line filled from the CRA's orders goes back to that).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'cra' => ['nullable', 'integer'],
            'staff' => ['nullable', 'integer'],
            'field' => ['required', 'string'],
            'value' => ['present', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);
        $month = CarbonImmutable::parse($data['month'].'-01');

        if (ActualIncomeStatement::whereDate('month', $month)->exists()) {
            return $this->updateSheetLine($data);
        }

        $craId = blank($data['cra'] ?? null) ? null : (int) $data['cra'];
        $staffId = blank($data['staff'] ?? null) ? null : (int) $data['staff'];
        abort_if($craId !== null && $staffId !== null, 422, 'Pick one card.');
        abort_unless($craId === null || LeadGenerator::crasOfMonth($month)->contains('id', $craId), 422, 'Amounts can only be typed in for the month\'s CRAs.');
        abort_unless($staffId === null || ActualIncome::staffOf($month)->contains('id', $staffId), 422, 'This card isn\'t on this month.');

        $isDepartment = $craId === null && $staffId === null;
        abort_unless(array_key_exists($data['field'], ActualIncome::inputs($isDepartment)), 422, $isDepartment
            ? 'This amount is typed in on the CRA cards.' : 'This amount is typed in on the CRD card.');

        $key = ['month' => $month->toDateString(), 'user_id' => $craId, 'monthly_cost_row_id' => $staffId, 'field' => $data['field']];
        $existing = ActualIncomeInput::whereDate('month', $key['month'])->where('field', $key['field'])
            ->when($craId, fn ($query) => $query->where('user_id', $craId), fn ($query) => $query->whereNull('user_id'))
            ->when($staffId, fn ($query) => $query->where('monthly_cost_row_id', $staffId), fn ($query) => $query->whereNull('monthly_cost_row_id'));

        if ($data['value'] === null) {
            $existing->delete();
        } else {
            ($existing->first() ?? new ActualIncomeInput($key))
                ->fill(['amount' => $data['value'], 'updated_by' => $request->user()->id])->save();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Type over one line of a card in a month taken from the sheet (empty = 0); the card's totals move with it.
     *
     * @param  array{month: string, cra?: int|string|null, field: string, value: float|int|string|null}  $data
     */
    private function updateSheetLine(array $data): JsonResponse
    {
        abort_unless(in_array($data['field'], ActualIncome::sheetInputs(), true), 422, 'This line is worked out from the others.');

        $card = ActualIncomeStatement::whereDate('month', $data['month'].'-01')
            ->when(blank($data['cra'] ?? null), fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', (int) $data['cra']))
            ->first();
        abort_if($card === null, 422, 'This card isn\'t on the sheet for this month.');

        $card->update(['figures' => ActualIncome::editSheetLine($card->figures ?? [], $data['field'], (float) ($data['value'] ?? 0))]);

        return response()->json(['ok' => true]);
    }
}
