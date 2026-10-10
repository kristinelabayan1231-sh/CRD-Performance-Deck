{{-- One lead row. Expects $lead plus the parent's $canManage, $cras, $visibleOptional, $canTransfer; pass backlogRow => true for carried-over leads. --}}
@php($canEdit = $canManage || $lead->assigned_to === auth()->id())
@php($transferable = ($backlogRow ?? false) && $canTransfer && $lead->carriesOver() && $lead->assigned_to)
{{-- Right-click opens Copy name / Copy mobile number and Mark as catered / Unmark catered (segmentation.js) for leads this user can edit. --}}
<tr class="align-middle hover:bg-canvas/30"
    @if ($canEdit) data-lead-row data-update-url="{{ route('segmentation.update', $lead) }}" data-customer="{{ $lead->customer_name }}"
        data-phone="{{ $lead->phone_number }}" data-mark="{{ $lead->isProcessed() ? 'unprocessed' : 'processed' }}" data-clears="{{ $lead->unmarkClears() }}" @endif>
    <td class="group sticky left-0 z-10 bg-white px-4 py-3 font-medium whitespace-nowrap" data-note-cell
        data-note-url="{{ route('segmentation.update', $lead) }}" data-customer="{{ $lead->customer_name }}">
        {{-- Sheets-style note marker in the top-right corner --}}
        <span data-note-indicator @if (! $lead->notes) hidden @endif aria-label="Has a note"
              class="absolute top-0 right-0 size-0 border-t-[8px] border-l-[8px] border-t-ink border-l-transparent"></span>
        <span class="flex items-center gap-2">
            @if ($transferable)
                <input type="checkbox" value="{{ $lead->id }}" data-backlog-select data-from="{{ $lead->assigned_to }}" data-from-name="{{ $lead->assignee?->displayName() }}"
                       aria-label="Select {{ $lead->customer_name }} for transfer" class="size-4 accent-brand-500">
            @endif
            {{ $lead->customer_name }}
            @if ($canEdit)
                <button type="button" data-note-open aria-label="Note for {{ $lead->customer_name }}"
                        class="rounded p-0.5 text-muted opacity-0 transition group-hover:opacity-100 hover:text-brand-600 focus:opacity-100">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L19 9l-4-4L4 16v4Zm9-13 4 4"/></svg>
                </button>
            @endif
        </span>
        <div data-note-popover @if (! $lead->notes) hidden @endif
             class="absolute top-1 left-full z-20 ml-1 hidden w-64 rounded-md border border-line bg-white p-3 text-left text-sm font-normal whitespace-normal shadow-lg group-hover:block">
            <p data-note-text class="whitespace-pre-wrap text-ink">{{ $lead->notes }}</p>
            <p data-note-meta class="mt-2 text-xs text-muted">{{ \App\Http\Controllers\SegmentationController::notesMeta($lead) }}</p>
        </div>
        <template data-note-value>{{ $lead->notes }}</template>
        @if ($backlogRow ?? false)
            <span @class([
                'mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold',
                'bg-coral/15 text-coral-700' => $lead->carriesOver(),
                'bg-teal/15 text-teal-700' => ! $lead->carriesOver(),
            ])>{{ $lead->carriesOver() ? ($lead->statusLabel() ?? 'Pending') : 'Catered · carried' }} since {{ $lead->est_out_of_stock_date->format('M j') }}</span>
            @if ($lead->transfers->first())
                <span class="mt-1 ml-1 inline-block text-[11px] text-muted">from {{ $lead->transfers->first()->fromUser?->displayName() ?? 'another CRA' }}</span>
            @endif
        @endif
    </td>
    <td class="px-4 py-3 text-right tabular-nums">{{ $lead->qty }}</td>
    <td class="px-4 py-3 whitespace-nowrap">{{ $lead->product_name }}</td>
    <td class="px-4 py-3 tabular-nums">
        <a href="tel:{{ $lead->phone_number }}" draggable="false" class="hover:text-brand-600 hover:underline">{{ $lead->phone_number }}</a>
    </td>
    <td class="px-4 py-3 whitespace-nowrap">{{ $lead->delivered_date->format('M j, Y') }}</td>
    <td class="bg-[#fff4d6]/60 px-4 py-3 text-center font-semibold tabular-nums">{{ $lead->daysSinceDelivered() }}</td>
    <td class="px-4 py-3 whitespace-nowrap">{{ $lead->est_out_of_stock_date->format('M j, Y') }}</td>
    <td class="px-4 py-3 whitespace-nowrap">{{ $lead->recommendedReplenishmentDay()->format('M j, Y') }}</td>
    <td class="px-4 py-3">
        <span @class([
            'rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap',
            'bg-teal/15 text-teal-700' => $lead->lead_type === \App\Models\Lead::TYPE_CRD,
            'bg-sky/15 text-sky-800' => $lead->lead_type === \App\Models\Lead::TYPE_FSD,
        ])>{{ $lead->typeLabel() }}</span>
    </td>
    <td class="px-4 py-3">
        @if ($canManage)
            <form method="POST" action="{{ route('segmentation.update', $lead) }}" data-autosave>
                @csrf @method('PATCH')
                <select name="assigned_to" aria-label="Assign {{ $lead->customer_name }}"
                        class="h-9 max-w-40 rounded-full border-0 bg-[#dbe7fb] px-3 text-xs font-semibold text-[#0b57d0] focus:ring-2 focus:ring-brand-200 focus:outline-none">
                    <option value="">Unassigned</option>
                    @foreach ($cras as $cra)
                        <option value="{{ $cra->id }}" @selected($lead->assigned_to === $cra->id)>{{ $cra->displayName() }}</option>
                    @endforeach
                    @if ($lead->assignee && ! $cras->contains($lead->assignee))
                        <option value="" selected disabled>{{ $lead->assignee->displayName() }} (not a CRA)</option>
                    @endif
                </select>
            </form>
        @elseif ($lead->assignee)
            <span class="flex items-center gap-2">
                <span class="rounded-full bg-[#dbe7fb] px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-[#0b57d0]">{{ $lead->assignee->displayName() }}</span>
                @if ($transferable)
                    <button type="button" data-transfer-open data-lead-ids="[{{ $lead->id }}]" data-from="{{ $lead->assigned_to }}"
                            data-from-name="{{ $lead->assignee->displayName() }}" data-label="{{ $lead->customer_name }}"
                            class="rounded-lg border border-line px-2 py-1 text-xs font-medium hover:border-brand-400 hover:text-brand-600">Transfer</button>
                @endif
            </span>
        @else
            <span class="text-xs text-muted">Unassigned</span>
        @endif
    </td>
    <td class="px-4 py-3">
        <x-lead-select :lead="$lead" field="status" list="statuses" :editable="$canEdit" placeholder="— Set status —" aria-label="Status for {{ $lead->customer_name }}" />
    </td>
    @foreach ($visibleOptional as $key => $column)
        <td data-col="{{ $key }}" class="px-4 py-3">
            @switch($key)
                @case('repeat_purchase')
                    <x-lead-select :lead="$lead" field="repeat_purchase" list="repeat_purchase" :editable="$canEdit" aria-label="Repeat purchase for {{ $lead->customer_name }}" />
                    @break
                @case('customer_tag')
                    <x-lead-select :lead="$lead" field="customer_tag" list="customer_tags" :editable="$canEdit" aria-label="Customer tagging for {{ $lead->customer_name }}" />
                    @break
                @case('contact_date')
                    <x-lead-date :lead="$lead" field="contact_date" :editable="$canEdit" aria-label="Date of contact for {{ $lead->customer_name }}" />
                    @break
                @case('contact_time')
                    <x-lead-select :lead="$lead" field="contact_time" list="contact_times" :editable="$canEdit" aria-label="Time of contact for {{ $lead->customer_name }}" />
                    @break
                @case('feedback')
                    <x-lead-select :lead="$lead" field="feedback" list="feedback" :editable="$canEdit" empty-note="Options coming soon" aria-label="Customer's feedback for {{ $lead->customer_name }}" />
                    @break
                @case('callback_date')
                    <x-lead-date :lead="$lead" field="callback_date" :editable="$canEdit" aria-label="Callback date for {{ $lead->customer_name }}" />
                    @break
            @endswitch
        </td>
    @endforeach
</tr>
