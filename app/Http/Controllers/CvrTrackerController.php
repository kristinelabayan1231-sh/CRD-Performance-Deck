<?php

namespace App\Http\Controllers;

use App\Models\CvrDayEntry;
use App\Models\CvrOrderEntry;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Services\CvrTracker;
use App\Services\LeadGenerator;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * CVR Tracker: each CRA's reorders for a day, with the CRA's Upsell, Type of Order, Point of Contact and Unconfirmed.
 * A CRA sees and fills in their own; anyone with cvr_tracker.view_all sees and fills in every CRA's.
 */
class CvrTrackerController extends Controller
{
    public function index(Request $request, CvrTracker $tracker): View
    {
        $user = $request->user();
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'cra' => ['nullable', 'integer'],
        ]);
        $day = CarbonImmutable::parse($filters['date'] ?? $today->toDateString());

        $cras = $user->can('cvr_tracker.view_all') ? LeadGenerator::cras() : LeadGenerator::cras()->where('id', $user->id)->values();
        $cra = $cras->firstWhere('id', (int) ($filters['cra'] ?? 0)) ?? $cras->first();

        return view('cvr-tracker.index', [
            'day' => $day,
            'today' => $today,
            'cras' => $cras,
            'cra' => $cra,
            'counts' => $tracker->orderCounts($cras, $day),
            'data' => $cra ? $tracker->day($cra, $day) : null,
        ]);
    }

    /**
     * Save one of an order's Upsell, Type of Order or Point of Contact (empty clears it). A Type of Order that matches
     * the order's CRD tag is stored as no change.
     */
    public function updateOrder(Request $request, string $orderId): JsonResponse
    {
        $order = PancakeOrder::where('pancake_order_id', $orderId)->firstOrFail(['pancake_order_id', 'seller_name', 'conversion_type']);
        abort_unless($this->canEdit($request->user(), $order), 403);

        $data = $request->validate([
            'upsell' => ['sometimes', 'nullable', Rule::in(array_keys(CvrOrderEntry::UPSELLS))],
            'order_type' => ['sometimes', 'nullable', Rule::in(array_keys(CvrOrderEntry::ORDER_TYPES))],
            'point_of_contact' => ['sometimes', 'nullable', Rule::in(array_keys(CvrOrderEntry::POINTS_OF_CONTACT))],
        ]);

        if (array_key_exists('order_type', $data) && $data['order_type'] === $order->conversion_type) {
            $data['order_type'] = null;
        }

        CvrOrderEntry::updateOrCreate(['pancake_order_id' => $order->pancake_order_id], [...$data, 'updated_by' => $request->user()->id]);

        return response()->json(['ok' => true]);
    }

    /**
     * Save a CRA's Unconfirmed for a day (empty clears it).
     */
    public function updateDay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cra' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.WorkingDate::realToday()->toDateString()],
            'unconfirmed' => ['present', 'nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $user = $request->user();
        abort_unless((int) $data['cra'] === $user->id || $user->can('cvr_tracker.view_all'), 403);

        CvrDayEntry::where('user_id', $data['cra'])->whereDate('date', $data['date'])
            ->firstOrNew([], ['user_id' => $data['cra'], 'date' => $data['date']])
            ->fill(['unconfirmed' => $data['unconfirmed']])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * The order's CRA (its seller, or the CRA it counts for when made under another account) or anyone who sees every CRA.
     */
    private function canEdit(User $user, PancakeOrder $order): bool
    {
        if ($user->can('cvr_tracker.view_all')) {
            return true;
        }

        $countsFor = CvrOrderEntry::where('pancake_order_id', $order->pancake_order_id)->value('user_id');

        return $countsFor !== null
            ? $countsFor === $user->id
            : $user->pancake_name !== null && $order->seller_name === PancakeEngagement::staffKey($user->pancake_name);
    }
}
