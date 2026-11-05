<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\CustomerOwners;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Right-click a Pending row → Transfer to the CRA who handled the customer's latest order (CustomerOwners::latestHandler),
 * when that's another CRA. For the lead's own CRA (or a manager). The lead moves like a backlog transfer (Lead::transferTo).
 */
class LeadHandoverController extends Controller
{
    /**
     * The CRA the lead can go to, for the menu item; null when there's none.
     */
    public function show(Request $request, Lead $lead, CustomerOwners $owners): JsonResponse
    {
        $handler = $this->handler($request, $lead, $owners);

        return response()->json(['cra' => $handler === null ? null : [
            'name' => $handler['cra']->displayName(),
            'ordered_on' => $handler['ordered_on']->format('M j'),
        ]]);
    }

    public function store(Request $request, Lead $lead, CustomerOwners $owners): JsonResponse
    {
        $handler = $this->handler($request, $lead, $owners);
        abort_if($handler === null, 422, 'This lead has no other CRA to go to: it was worked already, or nobody else handled the customer\'s latest order.');

        DB::transaction(fn () => $lead->transferTo($handler['cra'], $request->user(), Lead::today()));

        return response()->json(['message' => "Transferred {$lead->customer_name} to {$handler['cra']->displayName()}."]);
    }

    /**
     * @return array{cra: User, ordered_on: CarbonImmutable}|null
     */
    private function handler(Request $request, Lead $lead, CustomerOwners $owners): ?array
    {
        abort_unless($request->user()->can('segmentation.manage') || $lead->assigned_to === $request->user()->id, 403, 'You can only transfer leads assigned to you.');

        if ($lead->isProcessed()) {
            return null;
        }

        $handler = $owners->latestHandler(LeadGenerator::normalizePhone((string) $lead->phone_number));

        return $handler !== null && $handler['cra']->id !== $lead->assigned_to ? $handler : null;
    }
}
