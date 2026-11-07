<?php

namespace App\Http\Controllers;

use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings of the Exclusive Lead Tracker: transfer a CRA in from the Segmentation Tracker and back out. Their leads
 * stay assigned to them either way.
 */
class ExclusiveLeadSettingController extends Controller
{
    public function store(Request $request, User $user, ExclusiveLeads $exclusive): RedirectResponse|JsonResponse
    {
        abort_unless(LeadGenerator::cras()->contains('id', $user->id), 422, 'Only active CRAs can be transferred.');
        abort_if($user->exclusive_from !== null, 422, "{$user->displayName()} is already on the Exclusive Lead Tracker.");

        $data = $request->validate(['exclusive_from' => ['nullable', 'date_format:Y-m-d']]);

        $user->update(['exclusive_from' => $data['exclusive_from'] ?? Lead::today()->toDateString()]);

        // Their list is ready straight away; the hourly build retries if this fails.
        rescue(fn () => $exclusive->syncCra($user->fresh(), Lead::today()));

        return $this->saved($request, "{$user->displayName()} moved to the Exclusive Lead Tracker from ".CarbonImmutable::parse($user->exclusive_from)->format('M j, Y').'.');
    }

    public function destroy(Request $request, User $user, LeadGenerator $generator): RedirectResponse|JsonResponse
    {
        abort_if($user->exclusive_from === null, 422, "{$user->displayName()} is not on the Exclusive Lead Tracker.");

        $user->update(['exclusive_from' => null]);
        ExclusiveReservedLead::where('user_id', $user->id)->delete();

        // Back in the even split from today's unassigned leads.
        rescue(fn () => $generator->assign(Lead::today()));

        return $this->saved($request, "{$user->displayName()} moved back to the Segmentation Tracker. Their leads stay with them.");
    }

    private function saved(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['saved' => true, 'message' => $message]);
        }

        return back()->with('status', $message);
    }
}
