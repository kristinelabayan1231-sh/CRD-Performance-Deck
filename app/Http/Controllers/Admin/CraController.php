<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cra;
use App\Models\CraAssignment;
use App\Models\FacebookPage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CraController extends Controller
{
    public function index()
    {
        $cras = Cra::with(['assignments' => function ($query) {
            $query->orderByDesc('year')->orderByDesc('month')->orderByDesc('week');
        }, 'assignments.facebookPage'])->orderBy('name')->get();

        $pages = FacebookPage::orderBy('page_name')->get();

        return view('admin.cras.index', compact('cras', 'pages'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Cra::create($validated);

        return back()->with('status', 'CRA added.');
    }

    public function destroy(Cra $cra)
    {
        $cra->delete();

        return back()->with('status', 'CRA removed.');
    }

    public function storeAssignment(Request $request)
    {
        $validated = $request->validate([
            'cra_id' => 'required|exists:cras,id',
            'facebook_page_id' => 'required|exists:facebook_pages,id',
            'from_month' => 'required|integer|min:1|max:12',
            'from_year' => 'required|integer|min:2000|max:2100',
            'to_month' => 'required|integer|min:1|max:12',
            'to_year' => 'required|integer|min:2000|max:2100',
            'week' => 'required|integer|min:1|max:5',
        ]);

        $fromDate = Carbon::create($validated['from_year'], $validated['from_month'], 1);
        $toDate = Carbon::create($validated['to_year'], $validated['to_month'], 1);

        if ($toDate->lt($fromDate)) {
            return back()
                ->withErrors('The "To" month must be on or after the "From" month.')
                ->withInput();
        }

        if ($fromDate->diffInMonths($toDate) > 24) {
            return back()
                ->withErrors('That range is too large (over 2 years). Please split it into smaller assignments.')
                ->withInput();
        }

        $created = 0;
        $skipped = [];
        $cursor = $fromDate->copy();

        while ($cursor->lte($toDate)) {
            // Week 5 only exists in months with 29+ days — skip months where
            // the selected week would roll into the next month.
            $periodStart = $cursor->copy()->addDays(($validated['week'] - 1) * 7);

            if ($periodStart->month === $cursor->month) {
                CraAssignment::firstOrCreate([
                    'cra_id' => $validated['cra_id'],
                    'facebook_page_id' => $validated['facebook_page_id'],
                    'year' => $cursor->year,
                    'month' => $cursor->month,
                    'week' => $validated['week'],
                ]);
                $created++;
            } else {
                $skipped[] = $cursor->format('M Y');
            }

            $cursor = $cursor->copy()->addMonth();
        }

        $message = $created === 1 ? 'Assigned 1 month.' : "Assigned {$created} months.";

        if ($skipped) {
            $message .= ' Skipped (that week doesn\'t exist in): ' . implode(', ', $skipped) . '.';
        }

        return back()->with('status', $message);
    }

    public function destroyAssignment(CraAssignment $craAssignment)
    {
        $craAssignment->delete();

        return back()->with('status', 'Assignment removed.');
    }
}
