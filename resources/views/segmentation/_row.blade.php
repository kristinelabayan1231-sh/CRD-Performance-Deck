{{-- One lead row. Expects $lead plus the parent's $canManage, $cras, $visibleOptional, $canTransfer; pass backlogRow => true for carried-over leads. --}}
@php($readOnly = $readOnly ?? false)
@php($canEdit = ! $readOnly && ($canManage || $lead->assigned_to === auth()->id()))
@php($transferable = ($backlogRow ?? false) && $canTransfer && $lead->carriesOver() && $lead->assigned_to)
{{-- Right-click opens Copy name / Copy mobile number, Mark as catered / Unmark catered and, on a Pending row, Transfer to the CRA
     of the customer's latest order (segmentation.js) for leads this user can edit.
     Clicking the row opens the customer's Pancake chat and the Spiels panel (spiels.js). --}}
{{-- View only (a CRA on another CRA's Exclusive Lead Tracker): no chat on click, and right-click offers Copy mobile number only. --}}
{{-- A High AOV / VIP customer gets a crown (VIP) or a gem (High AOV) by their name --}}
@php($isVip = (bool) ($highValue['vip'] ?? false))
@php($isHighAov = ! $isVip && ($highValue['high_aov'] ?? false))
{{-- A reply to answer (YES or another reply) tints the row gold; the row open in the customer panel is a deeper gold (spiels.js) --}}
@php($toAnswer = in_array(($smsBadge ?? null)['state'] ?? null, ['yes', 'replied'], true))
<tr @class(['align-middle', 'cursor-pointer' => ! $readOnly, 'bg-[#fff8e1] [&>td.sticky]:bg-[#fff8e1]' => $toAnswer, 'hover:bg-canvas/60' => ! $toAnswer])
    data-sms-state="{{ ($smsBadge ?? null)['state'] ?? 'none' }}" @if ($toAnswer) data-to-answer @endif
    @if ($readOnly) data-copy-row data-phone="{{ $lead->phone_number }}"
    @else data-spiel-row data-chat-url="{{ route('segmentation.chat', $lead) }}" data-orders-url="{{ route('segmentation.orders', $lead) }}"
        @if (($smsBadge ?? null) && (auth()->user()->can('sms_reminders.view_all') || $smsBadge['cra_id'] === auth()->id())) data-sms-chat="{{ route('sms-reminders.conversations.chat', $smsBadge['phone_key']) }}" @endif
        data-spiel-customer="{{ $lead->customer_name }}" data-spiel-product="{{ $lead->product_name }}" data-spiel-qty="{{ $lead->qty }}" data-spiel-cra="{{ $lead->assignee?->displayName() }}"
        data-spiel-phone="{{ $lead->phone_number }}" data-spiel-oos="{{ $lead->est_out_of_stock_date->format('M j') }}" @endif
    @if ($canEdit) data-lead-row data-update-url="{{ route('segmentation.update', $lead) }}" data-customer="{{ $lead->customer_name }}"
        data-phone="{{ $lead->phone_number }}" data-mark="{{ $lead->isProcessed() ? 'unprocessed' : 'processed' }}" data-clears="{{ $lead->unmarkClears() }}"
        @unless ($lead->isProcessed()) data-handover-url="{{ route('segmentation.handover.show', $lead) }}" @endunless @endif>
    @can('sms_reminders.view')
        {{-- Text: the tick that puts the customer in today's texts --}}
        <td data-col="sms" class="py-3 pr-1 pl-4">
                @php($tickBadge = $smsBadge ?? null)
                @if ($tickBadge && ! $readOnly && (auth()->user()->can('sms_reminders.view_all') || $tickBadge['cra_id'] === auth()->id()))
                    @if ($tickBadge['state'] === 'stop')
                        {{-- Replied STOP: unticked; ticking (after a confirm) lets them be texted again --}}
                        <form method="POST" action="{{ route('sms-reminders.conversations.allow', $tickBadge['phone_key']) }}" class="flex">
                            @csrf
                            <input type="checkbox" data-sms-allow="{{ $lead->customer_name }}" aria-label="Text {{ $lead->customer_name }} again" title="Asked not to be texted: tick to text them again"
                                   class="size-4.5 cursor-pointer rounded accent-brand-600">
                        </form>
                    @elseif (! $tickBadge['changeable'] && $tickBadge['ticked'])
                        {{-- Already texted: nothing to tick --}}
                        <span title="{{ $tickBadge['label'] }}" class="grid size-4.5 place-items-center text-muted">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                            <span class="sr-only">{{ $tickBadge['label'] }}</span>
                        </span>
                    @else
                        <form method="POST" action="{{ route('sms-reminders.update', $tickBadge['reminder_id']) }}" class="flex">
                            @csrf @method('PATCH')
                            <input type="hidden" name="include" value="{{ $tickBadge['ticked'] ? 0 : 1 }}">
                            <input type="checkbox" @checked($tickBadge['ticked']) @disabled(! $tickBadge['changeable']) onchange="this.form.submit()"
                                   aria-label="Text {{ $lead->customer_name }} today" title="{{ $tickBadge['changeable'] ? ($tickBadge['ticked'] ? 'In today\'s texts: untick to leave them out (they stay on the call list)' : 'Left out: tick to text them') : 'Already texted' }}"
                                   class="size-4.5 cursor-pointer rounded accent-brand-600 disabled:cursor-not-allowed disabled:opacity-40">
                        </form>
                    @endif
                @else
                    <span class="inline-block size-4.5" aria-hidden="true"></span>
                @endif
        </td>
    @endcan
    <td data-col="customer_name" class="group sticky left-0 z-10 bg-white px-4 py-3 font-semibold whitespace-nowrap shadow-[6px_0_8px_-8px_rgb(22_19_31/0.25)]" data-note-cell
        data-note-url="{{ route('segmentation.update', $lead) }}" data-customer="{{ $lead->customer_name }}">
        {{-- Sheets-style note marker in the top-right corner --}}
        <span data-note-indicator @if (! $lead->notes) hidden @endif aria-label="Has a note"
              class="absolute top-0 right-0 size-0 border-t-[8px] border-l-[8px] border-t-ink border-l-transparent"></span>
        <span class="flex items-center gap-2">
            @if ($transferable)
                <input type="checkbox" value="{{ $lead->id }}" data-backlog-select data-from="{{ $lead->assigned_to }}" data-from-name="{{ $lead->assignee?->displayName() }}"
                       aria-label="Select {{ $lead->customer_name }} for transfer" class="size-4 accent-brand-500">
            @endif
            {{-- Initials, with a dot when there's a reply to answer --}}
            @php($initials = collect(preg_split('/\s+/', trim((string) $lead->customer_name)))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode(''))
            <span aria-hidden="true" class="relative grid size-7 shrink-0 place-items-center rounded-full bg-brand-50 text-[0.625rem] font-bold text-ink ring-1 ring-brand-200">
                {{ $initials ?: '?' }}
                @if ($toAnswer)
                    <i class="absolute -top-0.5 -right-0.5 size-2.5 rounded-full border-2 border-white bg-coral"></i>
                @endif
            </span>
            {{ $lead->customer_name }}
            {{-- VIP (crown) or High AOV (gem), as in Customer Database → High AOV CVR & VIP; a VIP shows only VIP --}}
            @if ($isVip)
                <span role="img" aria-label="VIP" title="VIP in {{ implode(', ', $highValue['vip_products']) }}" class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-600 text-brand-500">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" class="size-3.5"><path d="M3 18h18l-1.5-10-5 4L12 5l-2.5 7-5-4z"/></svg>
                </span>
            @elseif ($isHighAov)
                <span role="img" aria-label="High AOV" title="High AOV" class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-100 text-[#a37800]">
                    <svg aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linejoin="round" class="size-3.5"><path d="M6 3h12l4 6-10 12L2 9zM2 9h20M12 21 8 9l4-6 4 6z"/></svg>
                </span>
            @endif
            {{-- Its SMS Reminders text (users with SMS Reminders see it in the SMS column instead) --}}
            @if (($smsBadge ?? null) && ! auth()->user()->can('sms_reminders.view'))
                <span title="{{ $smsBadge['title'] }}" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold whitespace-nowrap {{ $smsBadge['classes'] }}"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3"><path stroke-linejoin="round" d="M4 5h16v11H9l-5 4z"/></svg>{{ $smsBadge['label'] }}</span>
            @endif
            {{-- Hover: the customer's order history (order date · product), loaded on first hover (segmentation.js) --}}
            <button type="button" data-orders-open data-orders-url="{{ route('segmentation.orders', $lead) }}" aria-label="Order history of {{ $lead->customer_name }}"
                    class="rounded p-0.5 text-muted/60 transition hover:text-brand-600">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5L6 21V3Zm3 5h6m-6 4h6m-6 4h3"/></svg>
            </button>
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
                'mt-1 inline-block rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold',
                'bg-coral/15 text-coral-700' => $lead->carriesOver(),
                'bg-teal/15 text-teal-700' => ! $lead->carriesOver(),
            ])>{{ $lead->carriesOver() ? ($lead->statusLabel() ?? 'Pending') : 'Catered · carried' }} since {{ $lead->listed_on->format('M j') }}</span>
            @if ($lead->transfers->first())
                <span class="mt-1 ml-1 inline-block text-[0.6875rem] text-muted">from {{ $lead->transfers->first()->fromUser?->displayName() ?? 'another CRA' }}</span>
            @endif
        @endif
    </td>
    @can('sms_reminders.view')
        @include('segmentation._sms-cell', ['canEditRow' => $canEdit])
    @else
        @include('segmentation._sms-cell', ['canEditRow' => $canEdit, 'smsBadge' => null])
    @endcan
    <td class="px-4 py-3">
        <span class="flex items-center gap-2">
            <x-lead-select :lead="$lead" field="status" list="statuses" :editable="$canEdit" placeholder="— Set status —" aria-label="Status for {{ $lead->customer_name }}" />
            @include('segmentation._attempt', ['lead' => $lead])
        </span>
    </td>
    <td data-col="qty" class="px-4 py-3 text-right tabular-nums">{{ $lead->qty }}</td>
    <td data-col="product" class="px-4 py-3 whitespace-nowrap">{{ $lead->product_name }}</td>
    <td data-col="contact_number" class="px-4 py-3 tabular-nums">
        <span class="flex items-center gap-2 whitespace-nowrap">
            <a href="tel:{{ $lead->phone_number }}" draggable="false" class="hover:text-brand-600 hover:underline">{{ $lead->phone_number }}</a>
        </span>
    </td>
    <td data-col="delivered_date" class="px-4 py-3 whitespace-nowrap">{{ $lead->delivered_date->format('M j, Y') }}</td>
    <td data-col="days_since_delivered" class="px-4 py-3 text-center font-semibold tabular-nums">{{ $lead->daysSinceDelivered() }}</td>
    <td data-col="est_out_of_stock" class="px-4 py-3 whitespace-nowrap">{{ $lead->est_out_of_stock_date->format('M j, Y') }}</td>
    <td data-col="replenishment_day" class="px-4 py-3 whitespace-nowrap">{{ $lead->recommendedReplenishmentDay()->format('M j, Y') }}</td>
    <td data-col="type" class="px-4 py-3">
        <span @class([
            'rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap',
            'bg-teal/15 text-teal-700' => $lead->lead_type === \App\Models\Lead::TYPE_CRD,
            'bg-sky/15 text-sky-800' => $lead->lead_type === \App\Models\Lead::TYPE_FSD,
        ])>{{ $lead->typeLabel() }}</span>
    </td>
    <td data-col="assigned_to" class="px-4 py-3">
        @if ($canManage && ! ($lockAssignee ?? false))
            <form method="POST" action="{{ route('segmentation.update', $lead) }}" data-autosave>
                @csrf @method('PATCH')
                <select name="assigned_to" aria-label="Assign {{ $lead->customer_name }}"
                        class="h-9 max-w-40 rounded-full border-0 bg-canvas px-3 text-xs font-semibold text-ink focus:ring-2 focus:ring-brand-200 focus:outline-none">
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
                <span class="rounded-full bg-canvas px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-ink">{{ $lead->assignee->displayName() }}</span>
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
    @isset($sources)
        {{-- Exclusive Lead Tracker: why the lead is on the list --}}
        @php($source = $sources[$lead->id] ?? null)
        <td data-col="listed_as" class="px-4 py-3">
            @if ($source)
                <span @class([
                    'rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap',
                    'bg-brand-100 text-brand-700' => $source === 'high_value',
                    'bg-teal/15 text-teal-700' => $source === 'handled',
                    'bg-[#fff4d6] text-[#7a5a00]' => $source === 'leftover',
                    'bg-coral/15 text-coral-700' => $source === 'tagged',
                    'bg-canvas text-muted' => in_array($source, ['random', 'pos'], true),
                ])>{{ \App\Http\Controllers\ExclusiveLeadController::SOURCE_LABELS[$source] ?? $source }}</span>
            @endif
        </td>
    @endisset
    @isset($listedDays)
        {{-- Exclusive Lead Tracker, whole month: the real day it was last on a list, and how many days it was on one --}}
        @php($listedDay = $listedDays[$lead->id] ?? null)
        <td data-col="last_listed" class="px-4 py-3 whitespace-nowrap tabular-nums">
            @if ($listedDay)
                {{ \Carbon\CarbonImmutable::parse($listedDay['last'])->format('M j') }}@if ($listedDay['count'] > 1) · {{ $listedDay['count'] }} days @endif
            @endif
        </td>
    @endisset
    @isset($taggedCustomers)
        {{-- Exclusive Lead Tracker: the POS order tag when one of the customer's orders has it, else a dropdown that adds it to the lead's order in Pancake --}}
        <td data-col="order_tag" class="px-4 py-3">
            @isset($taggedCustomers[\App\Services\LeadGenerator::normalizePhone((string) $lead->phone_number)])
                <span class="inline-flex items-center gap-1.5">
                    <span class="rounded-full bg-brand-100 px-2.5 py-1 text-xs font-semibold whitespace-nowrap text-brand-700">{{ config('exclusive_leads.order_tag.name') }}</span>
                    @can('exclusive_leads.manage')
                        {{-- Supervisors: the tag comes off the order and the lead off her list; another lead takes its place --}}
                        <form method="POST" action="{{ route('exclusive-leads.order-tag.destroy', $lead) }}"
                              data-confirm="Take {{ $lead->customer_name ?: 'this customer' }} off the exclusive leads? The tag comes off the order in Pancake and another lead takes this one's place." onsubmit="return confirm(this.dataset.confirm)">
                            @csrf
                            @method('DELETE')
                            <button type="submit" aria-label="Remove the tag and take {{ $lead->customer_name }} off the exclusive leads"
                                    class="rounded-full px-2 py-1 text-xs font-semibold text-coral-700 hover:bg-coral/10">Remove</button>
                        </form>
                    @endcan
                </span>
            @elseif ($readOnly)
                <span class="text-xs text-muted">—</span>
            @else
                {{-- The CRA whose list it is and supervisors can tag; other CRAs only view it --}}
                <form method="POST" action="{{ route('exclusive-leads.order-tag', $lead) }}" data-autosave>
                    @csrf
                    <select name="order_tag" aria-label="Add a POS tag to the order of {{ $lead->customer_name }}"
                            class="h-9 max-w-64 rounded-full border-0 bg-canvas px-3 text-xs font-semibold text-muted focus:ring-2 focus:ring-brand-200 focus:outline-none">
                        <option value="" class="bg-white text-ink">— Add tag —</option>
                        <option value="{{ config('exclusive_leads.order_tag.id') }}" class="bg-white text-ink">{{ config('exclusive_leads.order_tag.name') }}</option>
                    </select>
                </form>
            @endisset
        </td>
    @endisset
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
                    <x-lead-feedback :lead="$lead" :editable="$canEdit" aria-label="Customer's feedback for {{ $lead->customer_name }}" />
                    @break
                @case('callback_date')
                    <x-lead-date :lead="$lead" field="callback_date" :editable="$canEdit" aria-label="Callback date for {{ $lead->customer_name }}" />
                    @break
                @case('call_recording_url')
                    @include('segmentation._recordings', ['lead' => $lead])
                    @break
            @endswitch
        </td>
    @endforeach
</tr>
