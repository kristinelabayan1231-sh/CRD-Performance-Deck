<?php

namespace App\Http\Controllers;

use App\Models\Cra;
use App\Models\CraDayFinalization;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CraDayFinalizationController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'cra_id' => 'required|exists:cras,id',
            'date' => 'required|date',
        ]);

        $cra = Cra::findOrFail($validated['cra_id']);
        $this->authorizeFor($request, $cra);

        CraDayFinalization::firstOrCreate(
            ['cra_id' => $cra->id, 'date' => Carbon::parse($validated['date'])->toDateString()],
            ['finalized_by' => $request->user()->id],
        );

        return back()->with('status', "Finalized {$cra->name}'s numbers for ".Carbon::parse($validated['date'])->format('M j, Y').' — syncing will no longer touch this day.');
    }

    public function destroy(Request $request, CraDayFinalization $craDayFinalization)
    {
        $this->authorizeFor($request, $craDayFinalization->cra);

        $craDayFinalization->delete();

        return back()->with('status', 'Reopened '.Carbon::parse($craDayFinalization->date)->format('M j, Y').' — it will refresh again on the next hourly sync.');
    }

    /**
     * Only the CRA who owns the numbers (matched by login email, same rule
     * as the self-service cohort save) or an admin may finalize/reopen.
     */
    protected function authorizeFor(Request $request, Cra $cra): void
    {
        $user = $request->user();

        abort_unless(
            $user->is_admin || ($cra->email && strcasecmp($user->email, $cra->email) === 0),
            403,
        );
    }
}
