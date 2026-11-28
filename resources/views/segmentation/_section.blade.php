{{-- One list of leads (Pending or Catered), 30 per page with 10 in view and the rest by scrolling (headers stay put). Pending has a coral header, Catered a teal one. --}}
@php $visibleOptional = collect($optionalColumns)->reject(fn ($c) => $c['coming_soon'] ?? false); @endphp
@php $pending = $id === 'unprocessed'; @endphp
{{-- A Call button on the rows this user may edit, when their phone is linked (Settings → Call Recordings) --}}
@php $canDial = filled(auth()->user()->call_webhook_url); @endphp
{{-- $switch (both lists' totals): Pending and Catered one at a time, with the buttons that switch them (segmentation.js) --}}
@php $switch = request()->boolean('full') ? null : ($switch ?? null); @endphp
@php $shownList = request()->has('processed_page') ? 'processed' : 'unprocessed'; @endphp
{{-- Each row's SMS Reminders text (to be texted, texted, replied), looked up once for the section --}}
@php $smsBadges = rescue(fn () => app(\App\Services\RestockReminders::class)->badgesFor(
    $leads instanceof \Illuminate\Contracts\Pagination\Paginator ? $leads->items() : $leads), [], false); @endphp
@php $smsStates = collect($smsBadges)->countBy('state'); @endphp
<x-panel :title="$title" :accent="$accent ?? ($pending ? 'coral' : 'teal')" :icon="$icon ?? ($pending ? 'clock' : 'check')" :title-id="$id.'-title'"
         :tinted="false" body-class="" data-lead-section="{{ $id }}" :hidden="$switch !== null && $shownList !== $id" class="flex-col [&:not([hidden])]:flex">
    @if ($switch)
        <x-slot:header>
            {{-- Pending and Catered (one at a time), Pending's lists by text and its follow-up lists, then the full view --}}
            <header class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 pt-3 pb-2">
                <h2 id="{{ $id }}-title" class="sr-only">{{ $title }}</h2>
                <div class="flex items-center gap-1" role="group" aria-label="Lists">
                    @foreach (['unprocessed' => 'Pending', 'processed' => 'Catered'] as $listId => $listLabel)
                        <button type="button" data-show-list="{{ $listId }}" aria-pressed="{{ $listId === $id ? 'true' : 'false' }}" @if ($listId === $id) title="{{ $hint }}" @endif
                                class="flex h-10 items-center gap-2 rounded-full px-2.5 text-[0.9375rem] font-bold text-muted hover:text-ink aria-pressed:text-ink">
                            {{ $listLabel }}
                            <em @class(['rounded-[0.625rem] px-2 text-xs leading-5 font-bold text-white not-italic tabular-nums', 'bg-coral-700' => $listId === 'unprocessed', 'bg-brand-600' => $listId !== 'unprocessed'])>{{ number_format($switch[$listId]) }}</em>
                        </button>
                    @endforeach
                </div>
                @if ($pending)
                    @php
                        $chip = 'flex h-10 shrink-0 items-center rounded-full border border-[#d9d9d6] bg-white px-3 text-xs font-semibold text-ink tabular-nums transition-colors hover:border-ink aria-pressed:border-brand-600 aria-pressed:bg-brand-600 aria-pressed:text-white';
                        $chipIcon = fn (string $path) => '<svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$path.'"/></svg>';
                        // By text (this page's rows; sms-panel.js shows only the picked ones): Wants to order and Needs reply keep their label open
                        $stateChips = auth()->user()->can('sms_reminders.view') ? collect([
                            'yes' => ['Wants to order', 'M3 4h2l2.4 10.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6M10 20h.01M17 20h.01', true],
                            'replied' => ['Needs reply', 'M9 14 4 9l5-5M4 9h10a6 6 0 0 1 6 6v5', true],
                            'to-text' => ['To text', 'M4 12 20 4l-6 16-2-6z', false],
                            'sending' => ['Sending', 'M12 7v5l3 2M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18', false],
                            'sent' => ['Sent, waiting for a reply', 'M6 3h12M6 21h12M8 3c0 5 8 5 8 9s-8 4-8 9M16 3c0 5-8 5-8 9s8 4 8 9', false],
                            'failed' => ['Failed', 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z', false],
                            'unticked' => ['Unticked', 'M8 12h8M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18', false],
                            'stop' => ['STOP', 'M5.6 5.6l12.8 12.8M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18', false],
                        ])->filter(fn ($chip, $state) => $smsStates[$state] ?? 0) : collect();
                        // The follow-up lists open in Pending's full view
                        $followUpLists = [
                            'callback' => ['Call back for today', 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2M15 3h6v6M21 3l-6 6'],
                            'reactivation' => ['Reactivation', 'M12 3c.5 3.5 5 5.5 5 10a5 5 0 0 1-10 0c0-2.5 1.5-4 2.5-5.5.5 2 1.5 3 2.5 3.5 0-3-.5-5.5 0-8'],
                            'reserved' => ['Reserved', 'M21 8 12 3 3 8v8l9 5 9-5zM3 8l9 5 9-5M12 13v8'],
                            'reserved_pancake' => ['Reserved - Pancake', 'M6 3h12v18l-6-4-6 4z'],
                        ];
                    @endphp
                    <nav data-sms-chips class="flex flex-1 flex-wrap items-center gap-1" aria-label="Pending lists">
                        <button type="button" data-sms-filter="" aria-pressed="true" title="All pending" class="{{ $chip }}">
                            {!! $chipIcon('M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01') !!}<span class="slide-label">All pending</span><em class="ml-1.5 not-italic">{{ number_format($leads->total()) }}</em>
                        </button>
                        @foreach ($stateChips as $state => [$chipLabel, $chipPath, $open])
                            <button type="button" data-sms-filter="{{ $state }}" aria-pressed="false" title="{{ $chipLabel }} (this page)" aria-label="{{ $chipLabel }}, {{ $smsStates[$state] }}"
                                    @class([$chip, '!text-coral-700' => in_array($state, ['failed', 'stop'], true)])>
                                {!! $chipIcon($chipPath) !!}<span @class(['slide-label', 'is-open' => $open])>{{ $chipLabel }}</span><em class="ml-1.5 not-italic">{{ $smsStates[$state] }}</em>
                            </button>
                        @endforeach
                        @foreach ($followUpLists as $listKey => [$listLabel, $listPath])
                            <button type="button" title="{{ $listLabel }} (opens the full list)" aria-label="Open {{ $listLabel }}" class="{{ $chip }}"
                                    data-expand-title="{{ $listLabel }}" data-expand-src="{{ request()->fullUrlWithQuery(['show' => 'unprocessed', 'full' => 1, 'list' => $listKey, 'page' => null, 'processed_page' => null]) }}">
                                {!! $chipIcon($listPath) !!}<span class="slide-label">{{ $listLabel }}</span>
                            </button>
                        @endforeach
                    </nav>
                @else
                    <p class="hidden flex-1 text-xs text-muted xl:block">{{ $hint }}</p>
                @endif
                <button type="button" title="Full view" aria-label="Open {{ $title }} in full view"
                        data-expand-title="{{ $title }}" data-expand-src="{{ request()->fullUrlWithQuery(['show' => $id, 'full' => 1, 'page' => null, 'processed_page' => null]) }}"
                        class="ml-auto grid size-10 shrink-0 place-items-center rounded-full bg-canvas text-ink hover:bg-brand-600 hover:text-white">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7"/></svg>
                </button>
            </header>
        </x-slot:header>
    @endif
    <x-slot:badges>
        <span @class(['rounded-full bg-white px-2 py-0.5 text-xs font-bold tabular-nums shadow-sm', 'text-coral-700' => $pending, 'text-teal-700' => ! $pending && ! isset($accent), 'text-ink' => isset($accent)])>{{ number_format($leads->total()) }}</span>
        <p class="hidden text-xs text-muted sm:block">{{ $hint }}</p>
    </x-slot:badges>
    @if (! request()->boolean('full'))
        <x-slot:actions>
            <button type="button" title="Full view" aria-label="Open {{ $title }} in full view"
                    data-expand-title="{{ $title }}" data-expand-src="{{ request()->fullUrlWithQuery(['show' => $id, 'full' => 1, 'page' => null, 'processed_page' => null]) }}"
                    class="grid size-8 place-items-center rounded-lg bg-white/70 text-muted hover:bg-white hover:text-brand-600">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7"/></svg>
            </button>
        </x-slot:actions>
    @endif


    {{-- Pending: tick or untick today's texts a page at a time, or all of the CRA's at once (sms-reminders.bulk) --}}
    @php
        $tickIds = collect($smsBadges)->filter(fn ($badge) => $badge['changeable'] && (auth()->user()->can('sms_reminders.view_all') || $badge['cra_id'] === auth()->id()))->pluck('reminder_id')->values();
        $tickBar = $pending && ! request()->boolean('full') && ($sendBar ?? null) && $leads->isNotEmpty();
        $tickButton = 'flex h-9 items-center gap-1.5 rounded-full border border-[#d9d9d6] bg-white px-3 text-xs font-medium text-ink hover:border-ink disabled:opacity-50';
    @endphp
    @if ($tickBar)
        <div class="flex flex-wrap items-center gap-1.5 px-3 pb-2.5" role="group" aria-label="Tick">
            @foreach ([1 => ['Tick page', 'm5 13 4 4L19 7'], 0 => ['Untick page', 'M5 12h14']] as $include => [$tickLabel, $tickPath])
                <form method="POST" action="{{ route('sms-reminders.bulk') }}">
                    @csrf
                    <input type="hidden" name="date" value="{{ \App\Support\WorkingDate::realToday()->toDateString() }}">
                    <input type="hidden" name="include" value="{{ $include }}">
                    @foreach ($tickIds as $tickId)
                        <input type="hidden" name="ids[]" value="{{ $tickId }}">
                    @endforeach
                    <button type="submit" class="{{ $tickButton }}" @disabled($tickIds->isEmpty())>
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $tickPath }}"/></svg>{{ $tickLabel }}
                    </button>
                </form>
            @endforeach
            @foreach ([1 => ['Tick all', $sendBar['counts']['excluded'], null], 0 => ['Untick all', $sendBar['counts']['toSend'], 'Untick all '.$sendBar['counts']['toSend'].'? They stay on the list.']] as $include => [$tickLabel, $tickCount, $tickConfirm])
                <form method="POST" action="{{ route('sms-reminders.bulk') }}" @if ($tickConfirm) onsubmit="return confirm(@js($tickConfirm))" @endif>
                    @csrf
                    <input type="hidden" name="date" value="{{ \App\Support\WorkingDate::realToday()->toDateString() }}">
                    <input type="hidden" name="cra" value="{{ $sendBar['cra']->id }}">
                    <input type="hidden" name="include" value="{{ $include }}">
                    <button type="submit" class="{{ $tickButton }}" @disabled(! $tickCount) @if ($include) title="Failed ones are ticked one by one or with their page" @endif>{{ $tickLabel }} · {{ number_format($tickCount) }}</button>
                </form>
            @endforeach
            <span class="ml-auto text-xs text-muted tabular-nums">{{ number_format($leads->firstItem()) }}–{{ number_format($leads->lastItem()) }} of {{ number_format($leads->total()) }}</span>
        </div>
    @endif
    @if ($leads->isEmpty())
        <p class="px-4 py-10 text-center text-sm text-muted">{{ $empty }}</p>
    @else
        <div class="overflow-x-auto" @unless (request()->boolean('full')) data-visible-rows="10" @endunless>
            <table class="w-max min-w-full text-left text-sm">
                <thead class="sticky top-0 z-20 border-b border-line bg-canvas text-[0.6875rem] tracking-wide text-ink uppercase">
                    <tr>
                        @can('sms_reminders.view')
                            <th data-col="sms" class="sticky left-0 z-10 w-12 min-w-12 bg-canvas py-3 pr-1 pl-4 font-semibold">Text</th>
                        @endcan
                        {{-- With the Text column pinned first, the name sticks right after it --}}
                        <th data-col="customer_name" @class(['sticky z-10 bg-canvas px-4 py-3 font-semibold', 'left-12' => auth()->user()->can('sms_reminders.view'), 'left-0' => ! auth()->user()->can('sms_reminders.view')])>Customer Name</th>
                        @can('sms_reminders.view')
                            <th data-col="sms" class="px-3 py-3 font-semibold">SMS</th>
                        @endcan
                        <th data-col="actions" class="px-3 py-3 font-semibold">Actions</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th data-col="qty" class="px-4 py-3 text-right font-semibold">Qty</th>
                        <th data-col="product" class="px-4 py-3 font-semibold">Product</th>
                        <th data-col="contact_number" class="px-4 py-3 font-semibold">
                            <span class="inline-flex items-center gap-1.5">
                                Contact #
                                {{-- Copies every contact # in this list, one per line (segmentation.js) --}}
                                <button type="button" data-copy-phones title="Copy all contact numbers" aria-label="Copy all contact numbers in {{ $title }}"
                                        class="grid size-6 place-items-center rounded-md text-muted normal-case hover:bg-white hover:text-brand-600">
                                    <svg data-copy-icon class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                    <svg data-copied-icon class="hidden size-3.5 text-teal-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                                </button>
                                {{-- Exclusive Lead Tracker: every number on the whole month's list, not just this page's (segmentation.js) --}}
                                @isset($allPhonesUrl)
                                    <button type="button" data-copy-all-phones="{{ $allPhonesUrl }}" title="Copy every number on the whole month's list" aria-label="Copy every contact number on the whole month's list"
                                            class="flex h-6 items-center gap-1 rounded-md px-1 text-muted normal-case hover:bg-white hover:text-brand-600 disabled:opacity-60">
                                        <svg data-copy-icon class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                        <svg data-copied-icon class="hidden size-3.5 text-teal-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                                        <span data-copy-label>All</span>
                                    </button>
                                @endisset
                                {{-- Exclusive Lead Tracker: the whole month's list as an Excel file, Pending and Catered together --}}
                                @isset($exportUrl)
                                    <a href="{{ $exportUrl }}" title="Download the whole month's leads as Excel" aria-label="Download the whole month's leads as an Excel file"
                                       class="grid size-6 place-items-center rounded-md text-muted normal-case hover:bg-white hover:text-brand-600">
                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0-4-4m4 4 4-4M5 20h14"/></svg>
                                    </a>
                                @endisset
                            </span>
                        </th>
                        <th data-col="delivered_date" class="px-4 py-3 font-semibold">Delivered Date</th>
                        <th data-col="days_since_delivered" class="px-4 py-3 text-center font-semibold">Days since Delivered</th>
                        <th data-col="est_out_of_stock" class="px-4 py-3 font-semibold">Est. Out of Stock</th>
                        <th data-col="replenishment_day" class="px-4 py-3 font-semibold">Recommended Replenishment Day</th>
                        <th data-col="type" class="px-4 py-3 font-semibold">Type</th>
                        <th data-col="assigned_to" class="px-4 py-3 font-semibold">Assigned to</th>
                        @isset($sources)
                            <th data-col="listed_as" class="px-4 py-3 font-semibold">Listed as</th>
                        @endisset
                        @isset($listedDays)
                            <th data-col="last_listed" class="px-4 py-3 font-semibold">Last listed</th>
                        @endisset
                        @isset($taggedCustomers)
                            <th data-col="order_tag" class="px-4 py-3 font-semibold">Tag</th>
                        @endisset
                        @foreach ($visibleOptional as $key => $column)
                            <th data-col="{{ $key }}" class="px-4 py-3 font-semibold">{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    {{-- High AOV / VIP customers (Customer Database), looked up once for the section's rows --}}
                    @php $highValueFlags = rescue(fn () => app(\App\Services\HighValueCustomers::class)->flagsForNumbers(
                        collect($leads instanceof \Illuminate\Contracts\Pagination\Paginator ? $leads->items() : $leads)
                            ->map(fn ($lead) => \App\Services\LeadGenerator::normalizePhone((string) $lead->phone_number))->unique()->values()->all()), [], false); @endphp
                    @foreach ($leads as $lead)
                        @include('segmentation._row', ['lead' => $lead, 'backlogRow' => $backlogRows ?? false,
                            'highValue' => $highValueFlags[\App\Services\LeadGenerator::normalizePhone((string) $lead->phone_number)] ?? null,
                            'smsBadge' => $smsBadges[$lead->id] ?? null])
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($leads->hasPages())
            <div class="shrink-0 border-t border-line px-4 py-2">{{ $leads->links() }}</div>
        @endif
    @endif
</x-panel>
