<?php

namespace App\Http\Controllers;

use App\Models\MonthlyCostRow;
use App\Models\Setting;
use App\Support\MonthlyCost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRD - MC: the Customer Retention Dept.'s positions, salaries and monthly cost (managers and supervisors only).
 */
class MonthlyCostController extends Controller
{
    public function index(MonthlyCost $cost): View
    {
        $rows = MonthlyCostRow::orderBy('sort_order')->orderBy('id')->get();
        $lastChange = collect([
            MonthlyCostRow::with('editor')->latest('updated_at')->first(),
            Setting::where('key', 'like', MonthlyCost::PREFIX.'%')->with('editor')->latest('updated_at')->first(),
        ])->filter()->sortByDesc('updated_at')->first();

        return view('monthly-cost.index', [
            'rows' => $rows,
            'cost' => $cost,
            'totals' => $cost->totals($rows),
            'shared' => $cost->shared(),
            'lastChange' => $lastChange,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        MonthlyCostRow::create([
            ...$this->validatedRow($request),
            'sort_order' => (int) MonthlyCostRow::max('sort_order') + 1,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Row added.');
    }

    public function update(Request $request, MonthlyCostRow $row): RedirectResponse
    {
        $row->update([...$this->validatedRow($request), 'updated_by' => $request->user()->id]);

        return back()->with('status', 'Row saved.');
    }

    public function destroy(MonthlyCostRow $row): RedirectResponse
    {
        $row->delete();

        return back()->with('status', 'Row deleted.');
    }

    /**
     * Save the shared salaries, government benefits, daily rates and incentives. Only the settings sent
     * are saved, so the Gov. benefits card can save its three on their own.
     */
    public function settings(Request $request): RedirectResponse
    {
        $rules = collect(config('monthly_cost.defaults'))->map(fn ($default, string $key) => match (true) {
            str_ends_with($key, '_share') => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            $key === 'working_days' => ['sometimes', 'required', 'numeric', 'min:1', 'max:31'],
            default => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999'],
        })->all();

        foreach ($request->validate($rules) as $key => $value) {
            Setting::put(MonthlyCost::PREFIX.$key, $value, $request->user());
        }

        return back()->with('status', 'Settings saved.');
    }

    /**
     * Position, name (blank = vacant) and the monthly salary.
     *
     * @return array{position: string, name: ?string, pay_type: string, salary: string}
     */
    private function validatedRow(Request $request): array
    {
        $data = $request->validate([
            'position' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'salary' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ], [
            'salary.required' => 'Enter the monthly salary.',
        ]);

        return [
            'position' => $data['position'],
            'name' => $data['name'] ?? null,
            'pay_type' => MonthlyCostRow::FIXED,
            'salary' => $data['salary'],
        ];
    }
}
