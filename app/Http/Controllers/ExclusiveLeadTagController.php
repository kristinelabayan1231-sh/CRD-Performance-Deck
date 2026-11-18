<?php

namespace App\Http\Controllers;

use App\Models\ExclusiveListLead;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Services\ExclusiveLeadTagging;
use App\Services\PancakeClient;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
    public function __invoke(Request $request, Lead $lead, PancakeClient $pancake, ExclusiveLeadTagging $tagging): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->can('exclusive_leads.manage') || $lead->assigned_to === $user->id || ExclusiveReservedLead::where('lead_id', $lead->id)->where('user_id', $user->id)->exists(),
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

        // From now on the website looks after the tag: it comes off if the lead leaves her.
        $cra = $tagging->owner(Lead::today());
        if ($cra !== null && ($lead->assigned_to === $cra->id || ExclusiveReservedLead::where('lead_id', $lead->id)->where('user_id', $cra->id)->exists())) {
            $tagging->note($cra, $lead, ['tagged_at' => now(), 'failed_at' => null, 'last_error' => null]);
        }

        return response()->json(['order_tag' => config('exclusive_leads.order_tag.name')]);
    }

    /**
     * The Tag column's Remove (supervisors): the tag comes off the lead's order in Pancake and the lead off the exclusive
     * CRA for good; another lead takes its place on today's list so the month still reaches its target.
     */
    public function destroy(Request $request, Lead $lead, ExclusiveLeadTagging $tagging): RedirectResponse
    {
        $cra = $tagging->owner(Lead::today());
        abort_if($cra === null, 404);
        $name = $lead->customer_name ?: 'The customer';

        try {
            $tagging->remove($lead, $cra, $request->user());
        } catch (DomainException $e) {
            return back()->with('status', $e->getMessage());
        } catch (RuntimeException $e) {
            Log::warning('Exclusive lead not taken off', ['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'message' => $e->getMessage()]);

            return back()->with('status', "{$name}'s lead stays with {$cra->displayName()}: the tag couldn't come off its order. {$e->getMessage()}");
        }

        return back()->with('status', "{$name}'s lead is off {$cra->displayName()}'s list and the ".config('exclusive_leads.order_tag.name').' tag is off its order. Another lead took its place.');
    }

    private function isOnExclusiveTracker(Lead $lead): bool
    {
        return $lead->assignee?->exclusive_from !== null
            || ExclusiveListLead::where('lead_id', $lead->id)->exists()
            || ExclusiveReservedLead::where('lead_id', $lead->id)->exists();
    }
}
