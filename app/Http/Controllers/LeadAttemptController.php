<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadAttempt;
use App\Support\WorkingDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A cold lead's Attempt button: logs today's contact attempt (one per day) or takes it back. Attempts stay with the
 * lead, so a CRA it moves to sees how many are left and who made them.
 */
class LeadAttemptController extends Controller
{
    public function store(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        abort_unless($lead->isCold(), 422, 'Attempts are only logged on cold leads.');
        abort_if($lead->attemptsLeft() === 0, 422, 'All of this lead\'s attempts are used up.');

        $today = WorkingDate::realToday();

        if (! $lead->attempts()->whereDate('attempted_on', $today)->exists()) {
            $lead->attempts()->create(['user_id' => $request->user()->id, 'attempted_on' => $today]);
        }

        return $this->state($lead);
    }

    /**
     * Takes back today's attempt (a misclick). Earlier days' attempts stay.
     */
    public function destroy(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $lead->attempts()->whereDate('attempted_on', WorkingDate::realToday())->delete();

        return $this->state($lead);
    }

    private function authorizeLead(Request $request, Lead $lead): void
    {
        $user = $request->user();

        abort_unless($lead->assigned_to === $user->id || $user->can('segmentation.manage'), 403, 'You can only update leads assigned to you.');
    }

    private function state(Lead $lead): JsonResponse
    {
        $lead->load('attempts.user');

        return response()->json([
            'left' => $lead->attemptsLeft(),
            'today' => $lead->attempts->contains(fn (LeadAttempt $attempt) => $attempt->attempted_on->isSameDay(WorkingDate::realToday())),
            'history' => self::history($lead),
        ]);
    }

    /**
     * Who made each attempt and when: "Lhea · Oct 20, Mika · Oct 22".
     */
    public static function history(Lead $lead): string
    {
        return $lead->attempts
            ->map(fn (LeadAttempt $attempt) => ($attempt->user?->displayName() ?? 'Former user').' · '.$attempt->attempted_on->format('M j'))
            ->join(', ');
    }
}
