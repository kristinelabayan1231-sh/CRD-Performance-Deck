<?php

namespace App\Http\Controllers;

use App\Models\ExclusiveListLead;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Services\PancakeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class ExclusiveLeadTagController extends Controller
{
    /**
     * The Tag column's dropdown: add the exclusive POS tag to the lead's own order in Pancake.
     */
    public function __invoke(Request $request, Lead $lead, PancakeClient $pancake): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->can('exclusive_leads.view_all') || $lead->assigned_to === $user->id || ExclusiveReservedLead::where('lead_id', $lead->id)->where('user_id', $user->id)->exists(),
            403,
            'You can only tag your own leads.',
        );
        abort_unless($this->isOnExclusiveTracker($lead), 404);

        $tagId = (int) config('exclusive_leads.order_tag.id');
        $request->validate(['order_tag' => ['required', Rule::in([(string) $tagId])]], [
            'order_tag.required' => 'Pick the tag to add.',
            'order_tag.in' => "That tag isn't available.",
        ]);

        $orderNumber = Str::after($lead->order_id, Lead::POS_PREFIX);

        try {
            $tags = $pancake->addOrderTag($orderNumber, $tagId);
        } catch (RuntimeException $e) {
            Log::warning('Exclusive tag not added', ['lead_id' => $lead->id, 'order' => $orderNumber, 'user_id' => $user->id, 'message' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        Log::info('Exclusive tag added', ['lead_id' => $lead->id, 'order' => $orderNumber, 'user_id' => $user->id, 'tags_before' => $tags['before'], 'tags_after' => $tags['after']]);

        PancakeOrder::where('pancake_order_id', $orderNumber)->first()?->update([
            'tags' => $tags['after'],
            'conversion_type' => PancakeOrder::conversionTypeFor($tags['after']),
        ]);

        return response()->json(['order_tag' => config('exclusive_leads.order_tag.name')]);
    }

    private function isOnExclusiveTracker(Lead $lead): bool
    {
        return $lead->assignee?->exclusive_from !== null
            || ExclusiveListLead::where('lead_id', $lead->id)->exists()
            || ExclusiveReservedLead::where('lead_id', $lead->id)->exists();
    }
}
