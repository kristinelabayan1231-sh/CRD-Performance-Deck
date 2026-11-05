{{-- Pending full view → Reserved - Pancake: customers who reserved in a Pancake conversation, added here by the CRA (PancakeReservationController).
     Same columns as the other lists; Qty to Type come from the customer's last order when one is found. Expects $reservations plus the full view's $canManage, $cras, $optionalColumns. --}}
@php($visibleOptional = collect($optionalColumns)->reject(fn ($c) => $c['coming_soon'] ?? false))
@php($canManage = $canManage ?? false)

<x-panel title="Reserved - Pancake" accent="amber" icon="tag" title-id="reserved-pancake-title" :tinted="false" body-class="" data-lead-section="reserved_pancake" class="flex flex-col">
    <x-slot:badges>
        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-ink tabular-nums shadow-sm">{{ number_format($reservations->total()) }}</span>
        <p class="hidden text-xs text-muted sm:block">Reserved in a Pancake chat. Added as Hot Leads, Repeat Purchase Reserve, contacted now. Not counted as leads.</p>
    </x-slot:badges>

    {{-- Add: the contact number finds their last order (Find shows it first); the name is only needed when nothing is found --}}
    <form method="POST" action="{{ route('segmentation.reservations.store') }}" data-reservation-form data-lookup-url="{{ route('segmentation.reservations.lookup') }}"
          class="flex flex-wrap items-end gap-3 border-b border-line px-4 py-3">
        @csrf
        <label class="flex flex-col gap-1 text-xs font-semibold text-muted">
            Contact #
            <span class="flex gap-2">
                <input type="tel" name="phone_number" value="{{ old('phone_number') }}" required placeholder="09xx xxx xxxx" data-reservation-phone
                       class="h-9 w-40 rounded-lg border border-line bg-white px-3 text-sm font-normal text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                <button type="button" data-reservation-find class="h-9 rounded-lg border border-line px-3 text-sm font-semibold text-ink hover:border-brand-400 hover:text-brand-600 disabled:opacity-60">Find</button>
            </span>
        </label>
        <label class="flex flex-col gap-1 text-xs font-semibold text-muted">
            Customer Name
            <input type="text" name="customer_name" value="{{ old('customer_name') }}" maxlength="255" placeholder="From their last order" data-reservation-name
                   class="h-9 w-56 rounded-lg border border-line bg-white px-3 text-sm font-normal text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
        </label>
        @if ($canManage)
            <label class="flex flex-col gap-1 text-xs font-semibold text-muted">
                Assigned to
                <select name="assigned_to" class="h-9 rounded-lg border border-line bg-white px-3 text-sm font-normal text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                    <option value="">Me</option>
                    @foreach ($cras as $cra)
                        <option value="{{ $cra->id }}" @selected((string) old('assigned_to', ctype_digit((string) request('cra')) ? request('cra') : '') === (string) $cra->id)>{{ $cra->displayName() }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <button type="submit" class="h-9 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Add reservation</button>
        <p data-reservation-found role="status" class="basis-full text-xs text-muted empty:hidden"></p>
        @if (session('status'))
            <p role="status" class="basis-full text-xs font-semibold text-teal-700">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p role="alert" class="basis-full text-xs font-semibold text-coral-700">{{ $errors->first() }}</p>
        @endif
    </form>

    @if ($reservations->isEmpty())
        <p class="px-4 py-10 text-center text-sm text-muted">No Pancake reservations yet. Add one above when a customer reserves in a chat.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-max min-w-full text-left text-sm">
                <thead class="sticky top-0 z-20 bg-[#f7f4f8] text-xs tracking-wide text-muted uppercase">
                    <tr>
                        <th data-col="customer_name" class="sticky left-0 z-10 bg-[#f7f4f8] px-4 py-3 font-semibold">Customer Name</th>
                        <th data-col="qty" class="px-4 py-3 text-right font-semibold">Qty</th>
                        <th data-col="product" class="px-4 py-3 font-semibold">Product</th>
                        <th data-col="contact_number" class="px-4 py-3 font-semibold">Contact #</th>
                        <th data-col="delivered_date" class="px-4 py-3 font-semibold">Delivered Date</th>
                        <th data-col="days_since_delivered" class="bg-[#fff4d6] px-4 py-3 text-center font-semibold text-[#7a5a00]">Days since Delivered</th>
                        <th data-col="est_out_of_stock" class="px-4 py-3 font-semibold">Est. Out of Stock</th>
                        <th data-col="replenishment_day" class="px-4 py-3 font-semibold">Recommended Replenishment Day</th>
                        <th data-col="type" class="px-4 py-3 font-semibold">Type</th>
                        <th data-col="assigned_to" class="px-4 py-3 font-semibold">Assigned to</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        @foreach ($visibleOptional as $key => $column)
                            @continue($key === 'call_recording_url')
                            <th data-col="{{ $key }}" class="bg-[#e3f1ea] px-4 py-3 font-semibold text-[#1d5b45]">{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($reservations as $reservation)
                        @php($canEdit = $canManage || $reservation->assigned_to === auth()->id())
                        @php($updateUrl = route('segmentation.reservations.update', $reservation))
                        <tr class="align-middle hover:bg-canvas/30">
                            <td data-col="customer_name" class="group sticky left-0 z-10 bg-white px-4 py-3 font-medium whitespace-nowrap" data-note-cell
                                data-note-url="{{ $updateUrl }}" data-customer="{{ $reservation->customer_name }}">
                                <span data-note-indicator @if (! $reservation->notes) hidden @endif aria-label="Has a note"
                                      class="absolute top-0 right-0 size-0 border-t-[8px] border-l-[8px] border-t-ink border-l-transparent"></span>
                                <span class="flex items-center gap-2">
                                    {{ $reservation->customer_name }}
                                    @if ($canEdit)
                                        <button type="button" data-note-open aria-label="Note for {{ $reservation->customer_name }}"
                                                class="rounded p-0.5 text-muted opacity-0 transition group-hover:opacity-100 hover:text-brand-600 focus:opacity-100">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L19 9l-4-4L4 16v4Zm9-13 4 4"/></svg>
                                        </button>
                                        <form method="POST" action="{{ route('segmentation.reservations.destroy', $reservation) }}"
                                              data-confirm="Remove {{ $reservation->customer_name }} from Reserved - Pancake?" onsubmit="return confirm(this.dataset.confirm)">
                                            @csrf @method('DELETE')
                                            <button type="submit" aria-label="Remove {{ $reservation->customer_name }}"
                                                    class="rounded p-0.5 text-muted opacity-0 transition group-hover:opacity-100 hover:text-coral-700 focus:opacity-100">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"/></svg>
                                            </button>
                                        </form>
                                    @endif
                                </span>
                                <div data-note-popover @if (! $reservation->notes) hidden @endif
                                     class="absolute top-1 left-full z-20 ml-1 hidden w-64 rounded-md border border-line bg-white p-3 text-left text-sm font-normal whitespace-normal shadow-lg group-hover:block">
                                    <p data-note-text class="whitespace-pre-wrap text-ink">{{ $reservation->notes }}</p>
                                    <p data-note-meta class="mt-2 text-xs text-muted">{{ \App\Http\Controllers\PancakeReservationController::notesMeta($reservation) }}</p>
                                </div>
                                <template data-note-value>{{ $reservation->notes }}</template>
                                <span class="mt-1 inline-block rounded-full bg-[#ffe5a0]/60 px-2 py-0.5 text-[0.6875rem] font-semibold text-[#473821]">Added {{ $reservation->created_at->timezone(config('segmentation.timezone'))->format('M j') }}</span>
                            </td>
                            <td data-col="qty" class="px-4 py-3 text-right tabular-nums">{{ $reservation->qty ?? '—' }}</td>
                            <td data-col="product" class="px-4 py-3 whitespace-nowrap">{{ $reservation->product_name ?? '—' }}</td>
                            <td data-col="contact_number" class="px-4 py-3 tabular-nums">
                                <a href="tel:{{ $reservation->phone_number }}" draggable="false" class="whitespace-nowrap hover:text-brand-600 hover:underline">{{ $reservation->phone_number }}</a>
                            </td>
                            <td data-col="delivered_date" class="px-4 py-3 whitespace-nowrap">{{ $reservation->delivered_date?->format('M j, Y') ?? '—' }}</td>
                            <td data-col="days_since_delivered" class="bg-[#fff4d6]/60 px-4 py-3 text-center font-semibold tabular-nums">{{ $reservation->daysSinceDelivered() ?? '—' }}</td>
                            <td data-col="est_out_of_stock" class="px-4 py-3 whitespace-nowrap">{{ $reservation->est_out_of_stock_date?->format('M j, Y') ?? '—' }}</td>
                            <td data-col="replenishment_day" class="px-4 py-3 whitespace-nowrap">{{ $reservation->recommendedReplenishmentDay()?->format('M j, Y') ?? '—' }}</td>
                            <td data-col="type" class="px-4 py-3">
                                @if ($reservation->lead_type)
                                    <span @class([
                                        'rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap',
                                        'bg-teal/15 text-teal-700' => $reservation->lead_type === \App\Models\Lead::TYPE_CRD,
                                        'bg-sky/15 text-sky-800' => $reservation->lead_type === \App\Models\Lead::TYPE_FSD,
                                    ])>{{ $reservation->typeLabel() }}</span>
                                @else
                                    <span class="text-xs text-muted">—</span>
                                @endif
                            </td>
                            <td data-col="assigned_to" class="px-4 py-3">
                                @if ($canManage)
                                    <form method="POST" action="{{ $updateUrl }}" data-autosave>
                                        @csrf @method('PATCH')
                                        <select name="assigned_to" aria-label="Assign {{ $reservation->customer_name }}"
                                                class="h-9 max-w-40 rounded-full border-0 bg-[#dbe7fb] px-3 text-xs font-semibold text-[#0b57d0] focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                            <option value="">Unassigned</option>
                                            @foreach ($cras as $cra)
                                                <option value="{{ $cra->id }}" @selected($reservation->assigned_to === $cra->id)>{{ $cra->displayName() }}</option>
                                            @endforeach
                                            @if ($reservation->assignee && ! $cras->contains($reservation->assignee))
                                                <option value="" selected disabled>{{ $reservation->assignee->displayName() }} (not a CRA)</option>
                                            @endif
                                        </select>
                                    </form>
                                @elseif ($reservation->assignee)
                                    <span class="rounded-full bg-[#dbe7fb] px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-[#0b57d0]">{{ $reservation->assignee->displayName() }}</span>
                                @else
                                    <span class="text-xs text-muted">Unassigned</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-lead-select :lead="$reservation" :action="$updateUrl" field="status" list="statuses" :editable="$canEdit" placeholder="— Set status —" aria-label="Status for {{ $reservation->customer_name }}" />
                            </td>
                            @foreach ($visibleOptional as $key => $column)
                                @continue($key === 'call_recording_url')
                                <td data-col="{{ $key }}" class="px-4 py-3">
                                    @switch($key)
                                        @case('repeat_purchase')
                                            <x-lead-select :lead="$reservation" :action="$updateUrl" field="repeat_purchase" list="repeat_purchase" :editable="$canEdit" aria-label="Repeat purchase for {{ $reservation->customer_name }}" />
                                            @break
                                        @case('customer_tag')
                                            <x-lead-select :lead="$reservation" :action="$updateUrl" field="customer_tag" list="customer_tags" :editable="$canEdit" aria-label="Customer tagging for {{ $reservation->customer_name }}" />
                                            @break
                                        @case('contact_date')
                                            <x-lead-date :lead="$reservation" :action="$updateUrl" field="contact_date" :editable="$canEdit" aria-label="Date of contact for {{ $reservation->customer_name }}" />
                                            @break
                                        @case('contact_time')
                                            <x-lead-select :lead="$reservation" :action="$updateUrl" field="contact_time" list="contact_times" :editable="$canEdit" aria-label="Time of contact for {{ $reservation->customer_name }}" />
                                            @break
                                        @case('feedback')
                                            <x-lead-feedback :lead="$reservation" :action="$updateUrl" :editable="$canEdit" aria-label="Customer's feedback for {{ $reservation->customer_name }}" />
                                            @break
                                        @case('callback_date')
                                            <x-lead-date :lead="$reservation" :action="$updateUrl" field="callback_date" :editable="$canEdit" aria-label="Callback date for {{ $reservation->customer_name }}" />
                                            @break
                                    @endswitch
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($reservations->hasPages())
            <div class="shrink-0 border-t border-line px-4 py-2">{{ $reservations->links() }}</div>
        @endif
    @endif
</x-panel>
