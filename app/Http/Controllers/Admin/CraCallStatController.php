<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CraCallStat;
use App\Services\CraCallStatService;
use Illuminate\Http\Request;

class CraCallStatController extends Controller
{
    /**
     * Admin override: unlike the self-service endpoint, this trusts the
     * submitted cra_id — used for correcting any CRA's numbers, not just
     * your own.
     */
    public function store(Request $request, CraCallStatService $stats)
    {
        $validated = $request->validate([
            'cra_id' => 'required|exists:cras,id',
            'date' => 'required|date',
            'total_calls' => 'required|integer|min:0',
            'answered_calls' => 'required|integer|min:0|lte:total_calls',
        ]);

        $stats->saveDailyStats($validated['cra_id'], $validated['date'], $validated['total_calls'], $validated['answered_calls']);

        return back()->with('status', 'Call stats updated.');
    }

    public function destroy(CraCallStat $craCallStat)
    {
        $craCallStat->delete();

        return back()->with('status', 'Call stats entry removed.');
    }
}
