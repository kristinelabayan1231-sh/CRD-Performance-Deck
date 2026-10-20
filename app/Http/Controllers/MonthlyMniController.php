<?php

namespace App\Http\Controllers;

use App\Models\MniDepartment;
use App\Models\MniInput;
use App\Services\LeadGenerator;
use App\Services\MonthlyMni;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Monthly MNI: the month's targets per CRA (inputs grid) and the statement worked out from them (ledger). Anyone with
 * mni.manage types the inputs; they save as they change.
 */
class MonthlyMniController extends Controller
{
    public function index(Request $request, MonthlyMni $mni): View
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'plan' => ['nullable', Rule::in(array_keys(MniDepartment::PLANS))],
        ]);
        $month = CarbonImmutable::parse(($filters['month'] ?? WorkingDate::realToday()->format('Y-m')).'-01');
        $plan = $filters['plan'] ?? '1m';
        $cras = LeadGenerator::cras();

        // The ledger alone, re-drawn after an input is saved.
        $view = $request->header('X-Mni-Ledger') ? 'mni._ledger' : 'mni.index';

        return view($view, [
            'month' => $month,
            'plan' => $plan,
            'cras' => $cras,
            'data' => $mni->for($cras, $month, $plan),
            'canEdit' => $request->user()->can('mni.manage'),
            'lines' => MonthlyMni::LINES,
        ]);
    }

    /**
     * Save one input: a CRA's (cra set) or the department's Tax Allocation / Advertising Cost. Empty clears it.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'plan' => ['required', Rule::in(array_keys(MniDepartment::PLANS))],
            'cra' => ['nullable', 'integer', 'exists:users,id'],
            'field' => ['required', Rule::in(array_keys([...MniInput::FIELDS, ...MniDepartment::FIELDS]))],
            'value' => ['present', 'nullable', 'numeric', 'min:0', 'max:100000000'],
        ]);

        $isDepartment = array_key_exists($data['field'], MniDepartment::FIELDS);
        if ($isDepartment === filled($data['cra'] ?? null)) {
            abort(422, $isDepartment ? 'Tax Allocation and Advertising Cost are the department\'s.' : 'Pick the CRA this input is for.');
        }
        if (str_ends_with($data['field'], '_pct') || $data['field'] === 'conversion_rate') {
            $request->validate(['value' => ['nullable', 'numeric', 'max:100']]);
        }

        $key = ['month' => $data['month'].'-01', 'plan' => $data['plan']];
        $model = $isDepartment
            ? MniDepartment::where('plan', $data['plan'])->whereDate('month', $key['month'])->firstOrNew([], $key)
            : MniInput::where('plan', $data['plan'])->whereDate('month', $key['month'])->where('user_id', $data['cra'])->firstOrNew([], [...$key, 'user_id' => $data['cra']]);
        $model->fill([$data['field'] => $data['value'], 'updated_by' => $request->user()->id])->save();

        return response()->json(['ok' => true]);
    }
}
