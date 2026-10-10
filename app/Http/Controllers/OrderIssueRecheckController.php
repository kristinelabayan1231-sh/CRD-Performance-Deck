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
            ['flagged' => $flagged, 'found' => $found, 'failed' => $failed, 'updated' => $updated] = $pancake->recheckIssuesReport();
        } catch (Throwable $e) {
            Log::warning('Pancake order issues re-check failed', ['message' => $e->getMessage()]);

            return back()->with('status', 'Couldn\'t reach Pancake to re-check the order issues: '.$e->getMessage());
        }

        // Say what happened, so a lookup Pancake refuses isn't mistaken for "not fixed yet".
        $notFound = $flagged - $found - $failed;

        return back()->with('status', match (true) {
            $flagged === 0 => 'No flagged orders to re-check.',
            $failed > 0 && $found === 0 => "Pancake didn't answer the lookup for any of the {$flagged} flagged orders, so nothing could be re-checked. The error is in the app log (Pancake order lookup failed).",
            default => "Re-checked {$flagged} flagged ".str('order')->plural($flagged)." in Pancake: {$updated} updated"
                .($failed ? ", {$failed} couldn't be looked up" : '')
                .($notFound > 0 ? ", {$notFound} not found" : '')
                .($updated === 0 && $failed === 0 && $notFound === 0 ? '. Pancake still shows no CRD tag on them.' : '.'),
        });
    }
}
