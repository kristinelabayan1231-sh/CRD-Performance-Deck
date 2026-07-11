<?php

namespace App\Http\Controllers;

use App\Models\Cra;
use App\Services\CraCallStatService;
use Illuminate\Http\Request;

class CraCallStatController extends Controller
{
    /**
     * Self-service save for a CRA's own daily call stats. The CRA is
     * resolved from the logged-in user's email — never trust a submitted
     * cra_id here, or any CRA could edit any other CRA's numbers.
     */
    public function store(Request $request, CraCallStatService $stats)
    {
        $cra = Cra::where('email', $request->user()->email)->firstOrFail();

        $validated = $request->validate([
            'date' => 'required|date',
            'total_calls' => 'required|integer|min:0',
            'answered_calls' => 'required|integer|min:0|lte:total_calls',
        ]);

        $stats->saveDailyStats($cra->id, $validated['date'], $validated['total_calls'], $validated['answered_calls']);

        return back()->with('status', 'Thanks! Your call stats for today are saved.');
    }
}
