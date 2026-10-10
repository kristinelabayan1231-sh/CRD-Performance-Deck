<?php

namespace App\Http\Controllers;

use App\Services\CraIssues;
use App\Services\PancakeSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderIssueRecheckController extends Controller
{
    /**
     * Look up the header's flagged orders in Pancake now (seconds), so tags fixed there clear right away.
     */
    public function __invoke(Request $request, PancakeSync $pancake): RedirectResponse
    {
        abort_if(CraIssues::crasFor($request->user())->isEmpty(), 403);

        try {
            $updated = $pancake->recheckIssues();
        } catch (Throwable $e) {
            Log::warning('Pancake order issues re-check failed', ['message' => $e->getMessage()]);

            return back()->with('status', 'Couldn\'t reach Pancake to re-check the order issues. Try again in a minute.');
        }

        return back()->with('status', $updated
            ? "Re-checked in Pancake: {$updated} ".str('order')->plural($updated).' updated.'
            : 'Re-checked in Pancake: no changes yet. Fix the tags there, then check again.');
    }
}
