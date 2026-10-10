<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\CustomerLink;
use App\Services\HighValueCustomers;
use App\Services\LeadGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HighValueCustomerController extends Controller
{
    /**
     * Customer Database → High AOV CVR & VIP: per customer or per order.
     */
    public function index(Request $request, HighValueCustomers $highValue): View
    {
        $filters = $request->validate([
            'list' => ['nullable', Rule::in(array_keys(HighValueCustomers::LISTS))],
            'view' => ['nullable', Rule::in(array_keys(HighValueCustomers::VIEWS))],
            'cra' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $filters = [...$filters, 'list' => $filters['list'] ?? 'all', 'view' => $filters['view'] ?? 'customers'];
        $customers = $highValue->filtered($filters, $request->user()->id);

        return view('customers.high-value', [
            'rows' => $filters['view'] === 'orders' ? $highValue->orderPage($customers) : $highValue->customerPage($customers),
            'customerCount' => $customers->count(),
            'counts' => $highValue->counts(),
            'filters' => $filters,
            'cras' => LeadGenerator::cras(),
        ]);
    }

    /**
     * Save one field of a listed customer: their CRA, point of contact, PU/Reseller or remarks.
     * Without customers.high_value.assign a CRA can only claim a customer nobody has, or let go of their own.
     */
    public function update(Request $request, string $phoneKey, HighValueCustomers $highValue): JsonResponse|RedirectResponse
    {
        $key = CustomerLink::primaryFor($phoneKey);
        abort_unless(isset($highValue->all()[$key]), 404, 'This customer is not on the High AOV / VIP list.');

        $data = $request->validate([
            'assigned_cra_id' => ['sometimes', 'nullable', Rule::in(LeadGenerator::cras()->pluck('id'))],
            'point_of_contact' => ['sometimes', 'nullable', Rule::in(array_keys(config('customers.points_of_contact')))],
            'buyer_type' => ['sometimes', 'nullable', Rule::in(array_keys(config('customers.buyer_types')))],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], ['assigned_cra_id.in' => 'Customers can only be assigned to active CRAs.']);

        $user = $request->user();
        $account = CustomerAccount::firstOrNew(['phone_key' => $key]);

        if (array_key_exists('assigned_cra_id', $data)) {
            $craId = $data['assigned_cra_id'] === null ? null : (int) $data['assigned_cra_id'];
            $current = $account->assigned_cra_id;

            if (! $user->can('customers.high_value.assign')) {
                $claims = $craId === $user->id && $current === null;
                $releases = $craId === null && $current === $user->id;
                abort_unless($claims || $releases || $craId === $current, 403, 'You can only claim customers nobody has yet, or let go of your own.');
            }

            if ($craId !== $current) {
                $account->forceFill(['assigned_cra_id' => $craId, 'assigned_at' => $craId ? now() : null]);
            }
        }

        if (array_key_exists('remarks', $data)) {
            $data['remarks'] = trim((string) $data['remarks']) ?: null;
        }

        $account->fill(array_intersect_key($data, array_flip(['point_of_contact', 'buyer_type', 'remarks'])));
        $account->updated_by = $user->id;
        $account->save();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('status', 'Saved.');
    }
}
