<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AllowedEmail;
use App\Models\Cra;
use App\Models\CraPcAssignment;
use App\Models\Pc;
use App\Models\WeeklyConversationTag;
use App\Services\CraCohortService;
use App\Support\WeekBlocks;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CraController extends Controller
{
    public function index()
    {
        $cras = Cra::with('pcAssignments.pc.facebookPage')->orderBy('name')->get();
        $pcs = Pc::with('facebookPage')->whereNotNull('facebook_page_id')->orderBy('label')->get();
        $currentWeeklyTag = WeeklyConversationTag::forWeek(now());
        $weeklyTags = WeeklyConversationTag::orderByDesc('week_start')->limit(8)->get();

        return view('admin.cras.index', compact('cras', 'pcs', 'currentWeeklyTag', 'weeklyTags'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:cras,email',
        ]);

        $cra = Cra::create($validated);

        // A CRA needs to already be on the login allow-list to sign in at
        // all — set it here so giving them an email is enough by itself.
        if ($cra->email) {
            AllowedEmail::firstOrCreate(['email' => $cra->email]);
        }

        return back()->with('status', 'CRA added.');
    }

    public function destroy(Cra $cra)
    {
        $cra->delete();

        return back()->with('status', 'CRA removed.');
    }

    /**
     * Save a CRA's cohort for every PC in one submission, for whichever
     * 7-day block the given date falls in. Shared by the admin form and
     * the CRA's own "set your cohort" prompt.
     */
    public function storeWeeklyCohorts(Request $request, CraCohortService $cohorts)
    {
        $validated = $request->validate([
            'cra_id' => 'required|exists:cras,id',
            'week_start' => 'required|date',
            'pcs' => 'required|array|min:1',
            'pcs.*.pc_id' => 'required|exists:pcs,id',
            'pcs.*.no_cohort' => 'sometimes|in:1',
            'pcs.*.cohort_from_month' => 'required_unless:pcs.*.no_cohort,1|integer|min:1|max:12',
            'pcs.*.cohort_from_year' => 'required_unless:pcs.*.no_cohort,1|integer|min:2000|max:2100',
            'pcs.*.cohort_to_month' => 'required_unless:pcs.*.no_cohort,1|integer|min:1|max:12',
            'pcs.*.cohort_to_year' => 'required_unless:pcs.*.no_cohort,1|integer|min:2000|max:2100',
        ]);

        $saved = $cohorts->saveWeeklyCohorts($validated['cra_id'], $validated['week_start'], $validated['pcs']);
        $label = WeekBlocks::startOf(Carbon::parse($validated['week_start']))->format('M j, Y');

        return back()->with('status', "Saved {$saved} cohort(s) for the week of {$label}.");
    }

    public function destroyAssignment(CraPcAssignment $craPcAssignment)
    {
        $craPcAssignment->delete();

        return back()->with('status', 'Cohort entry removed.');
    }
}
