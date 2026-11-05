<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\PancakeReservation;
use App\Services\LeadGenerator;
use App\Services\PancakeReservationLookup;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Pending full view's Reserved - Pancake tab: customers who reserved in a Pancake conversation, added by a CRA.
 * A new one is assigned to whoever adds it (a manager may pick the CRA), tagged Hot Leads with Repeat Purchase =
 * Reserve and contacted now; Qty to Type come from the customer's last order (PancakeReservationLookup).
 */
class PancakeReservationController extends Controller
{
    /**
     * The add form's Find button: what the contact number fills in, before saving, and a line saying so.
     */
    public function lookup(Request $request, PancakeReservationLookup $lookup): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $found = $lookup->find($data['phone']);

        if ($found === null) {
            return response()->json(['customer' => null, 'summary' => 'No order found for this number: type the customer\'s name, the rest stays blank.']);
        }

        $source = [
            PancakeReservationLookup::FROM_LEAD => 'their latest lead',
            PancakeReservationLookup::FROM_DELIVERED => 'their last delivered order',
            PancakeReservationLookup::FROM_POS => 'their last Pancake order (not delivered yet)',
        ][$found['filled_from']];
        $parts = array_filter([
            $found['customer_name'],
            trim(($found['qty'] ? $found['qty'].' × ' : '').($found['product_name'] ?? '')),
            $found['delivered_date'] ? 'delivered '.CarbonImmutable::parse($found['delivered_date'])->format('M j, Y') : null,
            $found['est_out_of_stock_date'] ? 'out of stock '.CarbonImmutable::parse($found['est_out_of_stock_date'])->format('M j, Y') : null,
            $found['lead_type'] ? Lead::TYPES[$found['lead_type']] : null,
        ]);

        return response()->json(['customer' => $found, 'summary' => 'From '.$source.': '.implode(' · ', $parts)]);
    }

    public function store(Request $request, PancakeReservationLookup $lookup): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:30', function (string $attribute, string $value, Closure $fail) {
                if (strlen(LeadGenerator::normalizePhone($value)) < 10) {
                    $fail('Enter the customer\'s full contact number.');
                }
            }],
            'assigned_to' => ['nullable', Rule::in(LeadGenerator::cras()->pluck('id'))],
        ], ['assigned_to.in' => 'Reservations can only be assigned to active CRAs.']);

        $found = $lookup->find($data['phone_number']) ?? [];
        $name = trim((string) ($data['customer_name'] ?? '')) ?: ($found['customer_name'] ?? '');

        if ($name === '') {
            return back()->withErrors(['reservation' => 'No order found for that number: type the customer\'s name too.'])->withInput();
        }

        $now = CarbonImmutable::now(config('segmentation.timezone'));
        $hour = $now->format('H');

        PancakeReservation::create([
            ...$found,
            'customer_name' => $name,
            'phone_number' => trim($data['phone_number']),
            'phone_key' => LeadGenerator::normalizePhone($data['phone_number']),
            'assigned_to' => $user->can('segmentation.manage') && ! empty($data['assigned_to']) ? (int) $data['assigned_to'] : $user->id,
            'created_by' => $user->id,
            'customer_tag' => array_key_exists('hot', config('segmentation.customer_tags')) ? 'hot' : null,
            'repeat_purchase' => Lead::reserveRepeatPurchases()[0] ?? null,
            'contact_date' => $now->toDateString(),
            'contact_time' => array_key_exists($hour, config('segmentation.contact_times')) ? $hour : null,
        ]);

        return back()->with('status', "Added {$name} to Reserved - Pancake.");
    }

    /**
     * A cell of the row saved on change, as on a lead (SegmentationController::update).
     */
    public function update(Request $request, PancakeReservation $reservation): JsonResponse
    {
        $user = $request->user();
        $canManage = $user->can('segmentation.manage');
        abort_unless($canManage || $reservation->assigned_to === $user->id, 403, 'You can only update your own reservations.');

        $in = fn (string $list) => ['sometimes', 'nullable', Rule::in(array_map('strval', array_keys(config("segmentation.{$list}"))))];
        $data = $request->validate([
            'status' => $in('statuses'),
            'assigned_to' => ['sometimes', 'nullable', Rule::in(LeadGenerator::cras()->pluck('id'))],
            'repeat_purchase' => $in('repeat_purchase'),
            'customer_tag' => $in('customer_tags'),
            'contact_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'contact_time' => $in('contact_times'),
            'feedback' => $in('feedback'),
            'feedbacks' => ['sometimes', 'nullable', 'array'],
            'feedbacks.*' => $in('feedback'),
            'callback_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [
            'assigned_to.in' => 'Reservations can only be assigned to active CRAs.',
            'feedbacks.*.in' => "That Customer's Feedback option isn't available.",
        ]);

        $reservation->fill(array_intersect_key($data, array_flip([
            'repeat_purchase', 'customer_tag', 'contact_date', 'contact_time', 'feedback', 'callback_date',
        ])));

        // Customer's Feedback: every choice ticked (several can be).
        if (array_key_exists('feedbacks', $data)) {
            $reservation->pickFeedbacks(array_filter($data['feedbacks'] ?? []));
        }

        if (array_key_exists('notes', $data)) {
            $notes = trim((string) $data['notes']);
            $reservation->forceFill([
                'notes' => $notes === '' ? null : $notes,
                'notes_updated_by' => $notes === '' ? null : $user->id,
                'notes_updated_at' => $notes === '' ? null : now(),
            ]);
        }

        if (array_key_exists('assigned_to', $data)) {
            abort_unless($canManage, 403, 'Only managers can reassign reservations.');
            $reservation->assigned_to = $data['assigned_to'];
        }

        if (array_key_exists('status', $data)) {
            $reservation->forceFill(['status' => $data['status'], 'status_updated_by' => $user->id, 'status_updated_at' => now()]);

            if ($feedback = config('segmentation.status_feedback')[$data['status']] ?? null) {
                $reservation->addFeedback($feedback);
            }
        }

        $reservation->save();
        $reservation->load('notesAuthor');

        return response()->json([
            'saved' => true,
            'feedback' => $reservation->feedback,
            'feedbacks' => $reservation->feedbackKeys(),
            'contact_date' => $reservation->contact_date?->toDateString(),
            'contact_time' => $reservation->contact_time,
            'notes' => $reservation->notes,
            'notes_meta' => self::notesMeta($reservation),
        ]);
    }

    public function destroy(Request $request, PancakeReservation $reservation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('segmentation.manage') || $reservation->assigned_to === $user->id, 403, 'You can only remove your own reservations.');

        $reservation->delete();

        return back()->with('status', "Removed {$reservation->customer_name} from Reserved - Pancake.");
    }

    public static function notesMeta(PancakeReservation $reservation): ?string
    {
        if (! $reservation->notes) {
            return null;
        }

        return trim(($reservation->notesAuthor?->displayName() ?? '').' · '.$reservation->notes_updated_at?->timezone(config('segmentation.timezone'))->format('M j, g:i A'), ' ·');
    }
}
