{{-- One list of leads (Pending or Catered), 30 per page with 10 in view and the rest by scrolling (headers stay put). Pending has a coral header, Catered a teal one. --}}
@php($visibleOptional = collect($optionalColumns)->reject(fn ($c) => $c['coming_soon'] ?? false))
@php($pending = $id === 'unprocessed')
{{-- A Call button on the rows this user may edit, when their phone is linked (Settings → Call Recordings) --}}
@php($canDial = filled(auth()->user()->call_webhook_url))
{{-- $switch (both lists' totals): Pending and Catered one at a time, with the buttons that switch them (segmentation.js) --}}
@php($switch = request()->boolean('full') ? null : ($switch ?? null))
@php($shownList = request()->has('processed_page') ? 'processed' : 'unprocessed')

<x-panel :title="$title" :accent="$accent ?? ($pending ? 'coral' : 'teal')" :icon="$icon ?? ($pending ? 'clock' : 'check')" :title-id="$id.'-title'"
         :tinted="false" body-class="" data-lead-section="{{ $id }}" :hidden="$switch !== null && $shownList !== $id" class="flex-col [&:not([hidden])]:flex">
    @if ($switch)
        <x-slot:header>
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                <h2 id="{{ $id }}-title" class="sr-only">{{ $title }}</h2>
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach (['unprocessed' => 'Pending', 'processed' => 'Catered'] as $listId => $listLabel)
                        <button type="button" data-show-list="{{ $listId }}" aria-pressed="{{ $listId === $id ? 'true' : 'false' }}"
                                class="h-9 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-muted tabular-nums hover:border-brand-400 hover:text-brand-600 aria-pressed:border-brand-500 aria-pressed:bg-brand-50 aria-pressed:text-brand-700">{{ $listLabel }} · {{ number_format($switch[$listId]) }}</button>
                    @endforeach
                    {{-- The follow-up lists open in Pending's full view --}}
                    <select data-expand-select aria-label="Follow-up lists" class="h-9 rounded-lg border border-line bg-white px-2.5 text-sm font-semibold text-muted focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                        <option value="">Follow-up lists</option>
                        @foreach (['callback' => 'Call back for today', 'reactivation' => 'Reactivation', 'reserved' => 'Reserved', 'reserved_pancake' => 'Reserved - Pancake'] as $listKey => $listLabel)
                            <option value="{{ request()->fullUrlWithQuery(['show' => 'unprocessed', 'full' => 1, 'list' => $listKey, 'page' => null, 'processed_page' => null]) }}">{{ $listLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-3">
                    <p class="hidden text-xs text-muted xl:block">{{ $hint }}</p>
                    <button type="button" title="Full view" aria-label="Open {{ $title }} in full view"
                            data-expand-title="{{ $title }}" data-expand-src="{{ request()->fullUrlWithQuery(['show' => $id, 'full' => 1, 'page' => null, 'processed_page' => null]) }}"
                            class="grid size-9 place-items-center rounded-lg border border-line text-muted hover:border-brand-400 hover:text-brand-600">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7"/></svg>
                    </button>
                </div>
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

    @if ($leads->isEmpty())
        <p class="px-4 py-10 text-center text-sm text-muted">{{ $empty }}</p>
    @else
        <div class="overflow-x-auto" @unless (request()->boolean('full')) data-visible-rows="10" @endunless>
            <table class="w-max min-w-full text-left text-sm">
                <thead class="sticky top-0 z-20 bg-[#f7f4f8] text-xs tracking-wide text-muted uppercase">
                    <tr>
                        <th data-col="customer_name" class="sticky left-0 z-10 bg-[#f7f4f8] px-4 py-3 font-semibold">Customer Name</th>
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
                        @can('sms_reminders.view')
                            <th data-col="sms" class="bg-brand-100 px-4 py-3 font-semibold text-brand-700">Text</th>
                        @endcan
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th data-col="delivered_date" class="px-4 py-3 font-semibold">Delivered Date</th>
                        <th data-col="days_since_delivered" class="bg-[#fff4d6] px-4 py-3 text-center font-semibold text-[#7a5a00]">Days since Delivered</th>
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
                            <th data-col="{{ $key }}" class="bg-[#e3f1ea] px-4 py-3 font-semibold text-[#1d5b45]">{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    {{-- High AOV / VIP customers (Customer Database), looked up once for the section's rows --}}
                    @php($highValueFlags = rescue(fn () => app(\App\Services\HighValueCustomers::class)->flagsForNumbers(
                        collect($leads instanceof \Illuminate\Contracts\Pagination\Paginator ? $leads->items() : $leads)
                            ->map(fn ($lead) => \App\Services\LeadGenerator::normalizePhone((string) $lead->phone_number))->unique()->values()->all()), [], false))
                    {{-- Each row's SMS Reminders text (to be texted, texted, replied), looked up once for the section --}}
                    @php($smsBadges = rescue(fn () => app(\App\Services\RestockReminders::class)->badgesFor(
                        $leads instanceof \Illuminate\Contracts\Pagination\Paginator ? $leads->items() : $leads), [], false))
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
