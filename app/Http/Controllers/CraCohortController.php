<?php

namespace App\Http\Controllers;

use App\Models\Cra;
use App\Services\CraCohortService;
use App\Support\WeekBlocks;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CraCohortController extends Controller
{
    /**
     * Self-service save for a CRA's own weekly cohorts. The CRA is resolved
     * from the logged-in user's email — never trust a submitted cra_id here,
     * or any CRA could edit any other CRA's cohorts.
     */
    public function store(Request $request, CraCohortService $cohorts)
    {
        $cra = Cra::where('email', $request->user()->email)->firstOrFail();

        $validated = $request->validate([
            'week_start' => 'required|date',
            'pcs' => 'required|array|min:1',
            'pcs.*.pc_id' => 'required|exists:pcs,id',
            'pcs.*.no_cohort' => 'sometimes|in:1',
            'pcs.*.cohort_from_month' => 'required_unless:pcs.*.no_cohort,1|integer|min:1|max:12',
            'pcs.*.cohort_from_year' => 'required_unless:pcs.*.no_cohort,1|integer|min:2000|max:2100',
            'pcs.*.cohort_to_month' => 'required_unless:pcs.*.no_cohort,1|integer|min:1|max:12',
            'pcs.*.cohort_to_year' => 'required_unless:pcs.*.no_cohort,1|integer|min:2000|max:2100',
        ]);

        $saved = $cohorts->saveWeeklyCohorts($cra->id, $validated['week_start'], $validated['pcs']);
        $label = WeekBlocks::startOf(Carbon::parse($validated['week_start']))->format('M j, Y');

        return back()->with('status', "Thanks! Saved {$saved} cohort(s) for the week of {$label}.");
    }
}
