<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Services\LeadGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BacklogTransferController extends Controller
{
    /**
     * Move unprocessed (backlog) leads to another CRA. Only backlog can be
     * transferred; today's fresh leads and handled leads stay where they are.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1', 'max:500'],
            'lead_ids.*' => ['integer', 'distinct'],
            'to' => ['required', Rule::in(LeadGenerator::cras()->pluck('id'))],
        ], [
            'to.in' => 'Backlog can only be transferred to an active CRA.',
            'to.required' => 'Choose a CRA to transfer to.',
        ]);

        $leads = Lead::carryOver()->whereIn('id', $data['lead_ids'])->with('assignee')->get();

        if ($leads->count() !== count($data['lead_ids'])) {
            return back()->withErrors(['transfer' => 'Some of those leads no longer carry over (handled with another status, or not yet due). Refresh and try again.']);
        }

        $leads = $leads->reject(fn (Lead $lead) => $lead->assigned_to === (int) $data['to']);

        if ($leads->isEmpty()) {
            return back()->withErrors(['transfer' => 'Those leads are already assigned to that CRA.']);
        }

        $to = LeadGenerator::cras()->firstWhere('id', (int) $data['to']);
        $from = $leads->map(fn (Lead $lead) => $lead->assignee?->displayName() ?? 'Unassigned')->unique()->join(', ');

        DB::transaction(function () use ($leads, $to, $request) {
            foreach ($leads as $lead) {
                LeadTransfer::create([
                    'lead_id' => $lead->id,
                    'from_user_id' => $lead->assigned_to,
                    'to_user_id' => $to->id,
                    'transferred_by' => $request->user()->id,
                ]);

                $lead->forceFill(['assigned_to' => $to->id, 'assigned_at' => now()])->save();
            }
        });

        $count = $leads->count();

        return back()->with('status', "Transferred {$count} backlog ".($count === 1 ? 'lead' : 'leads')." from {$from} to {$to->displayName()}.");
    }
}
