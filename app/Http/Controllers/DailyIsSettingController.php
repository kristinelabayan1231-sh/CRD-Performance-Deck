<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\DailyIncomeStatement;
use App\Support\ProductGroups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DailyIsSettingController extends Controller
{
    /**
     * Every product group, in the Daily I.S. order, with whether it is deactivated.
     */
    public function index(): View
    {
        $deactivated = DailyIncomeStatement::deactivated();

        return view('settings.daily-is', [
            'products' => array_map(fn (array $group) => [
                'group' => $group['name'],
                'name' => DailyIncomeStatement::cardName($group['name']),
                'deactivated' => in_array(Str::lower($group['name']), $deactivated, true),
            ], DailyIncomeStatement::inProductOrder(ProductGroups::all())),
            'lastChange' => Setting::where('key', DailyIncomeStatement::DEACTIVATED_KEY)->with('editor')->first(),
        ]);
    }

    /**
     * Save which products are deactivated: shown in the Daily I.S. only on a day they have sales, with a label.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'deactivated' => ['nullable', 'array'],
            'deactivated.*' => ['string', Rule::in(array_column(ProductGroups::all(), 'name'))],
        ]);

        DailyIncomeStatement::saveDeactivated($data['deactivated'] ?? [], $request->user());

        return back()->with('status', 'Daily I.S. products saved.');
    }
}
