{{-- The dashboard's customer list: the picked cell's customers, or the search's; on the page and in its full-size pop-up. --}}
{{-- Search the period's customers by name, contact number or order # --}}
<form method="GET" action="{{ route('exclusive-leads.dashboard') }}{{ ($full ?? false) ? '' : '#customers-title' }}" role="search" class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2.5">
    @foreach (\Illuminate\Support\Arr::except($listFilters, ['q']) as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    @if ($full ?? false)
        <input type="hidden" name="full" value="1">
    @endif
    <label class="relative min-w-0 flex-1">
        <span class="sr-only">Search customers</span>
        <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
        <input type="search" name="q" value="{{ $search }}" placeholder="Search name, contact # or order # ({{ strtolower(\App\Http\Controllers\ExclusiveLeadDashboardController::PERIODS[$period]) }})"
               class="h-9 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm focus:border-brand-400 focus:ring-brand-400">
    </label>
    <button type="submit" class="h-9 rounded-lg bg-brand-600 px-3.5 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
    @if ($search !== null)
        <a href="{{ route('exclusive-leads.dashboard', [...\Illuminate\Support\Arr::except($listFilters, ['q']), ...(($full ?? false) ? ['full' => 1] : [])]) }}{{ ($full ?? false) ? '' : '#customers-title' }}" class="flex h-9 items-center rounded-lg px-3 text-sm font-medium text-muted hover:bg-canvas hover:text-ink">Clear</a>
    @endif
</form>
@if (! $leads || $leads->isEmpty())
    <p class="px-4 py-10 text-center text-sm text-muted">{{ $search !== null ? 'No customer of '.$cra->displayName()."'s matches “".$search.'” for '.strtolower(\App\Http\Controllers\ExclusiveLeadDashboardController::PERIODS[$period]).'.' : 'Pick a number in the grid to list its customers.' }}</p>
@else
    {{-- What the Est. out of stock colours mean --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-line px-4 py-2 text-xs">
        <span class="font-semibold text-muted">Est. out of stock:</span>
        <span class="flex items-center gap-1.5"><span class="rounded-full bg-coral/25 px-2 py-0.5 font-semibold text-coral-700">Red</span> out of stock or within {{ \App\Http\Controllers\ExclusiveLeadDashboardController::URGENT_DAYS }} days · call now</span>
        <span class="flex items-center gap-1.5"><span class="rounded-full bg-[#fdecc0] px-2 py-0.5 font-semibold text-[#7a4f00]">Amber</span> in {{ \App\Http\Controllers\ExclusiveLeadDashboardController::URGENT_DAYS + 1 }}–{{ \App\Http\Controllers\ExclusiveLeadDashboardController::SOON_DAYS }} days · call soon</span>
        <span class="flex items-center gap-1.5"><span class="rounded-full bg-teal-700/15 px-2 py-0.5 font-semibold text-teal-700">Green</span> more than {{ \App\Http\Controllers\ExclusiveLeadDashboardController::SOON_DAYS }} days · no need to call yet</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-[0.8125rem]">
            <thead class="text-left text-xs text-muted">
                <tr>
                    <th class="px-4 py-2 font-semibold">Customer</th>
                    <th class="px-2 py-2 font-semibold">
                        <span class="flex items-center gap-1">
                            Contact #
                            {{-- This page's numbers, and every number of the cell (segmentation.js) --}}
                            <button type="button" data-copy-phones title="Copy this page's contact numbers" aria-label="Copy this page's contact numbers"
                                    class="grid size-6 place-items-center rounded-md text-muted hover:bg-canvas hover:text-brand-600">
                                <svg data-copy-icon class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                <svg data-copied-icon class="hidden size-3.5 text-teal-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                            </button>
                            <button type="button" data-copy-all-phones="{{ $phonesUrl }}" title="Copy every number of these {{ number_format($leads->total()) }} customers" aria-label="Copy every contact number of these {{ number_format($leads->total()) }} customers"
                                    class="flex h-6 items-center gap-1 rounded-md px-1 text-muted hover:bg-canvas hover:text-brand-600 disabled:opacity-60">
                                <svg data-copy-icon class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                <svg data-copied-icon class="hidden size-3.5 text-teal-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
                                <span data-copy-label>All</span>
                            </button>
                        </span>
                    </th>
                    <th class="px-2 py-2 font-semibold">Qty</th>
                    <th class="px-2 py-2 font-semibold">Type</th>
                    <th class="px-2 py-2 font-semibold">Delivered</th>
                    <th class="px-2 py-2 font-semibold">Est. out of stock</th>
                    <th class="px-4 py-2 font-semibold">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($leads as $lead)
                    @php($daysLeft = (int) $leadToday->diffInDays($lead->est_out_of_stock_date, false))
                    <tr class="border-t border-line">
                        <td class="px-4 py-1.5 font-medium">{{ $lead->customer_name }}</td>
                        <td data-col="contact_number" class="px-2 py-1.5 whitespace-nowrap tabular-nums"><a href="tel:{{ $lead->phone_number }}" class="hover:text-brand-600 hover:underline">{{ $lead->phone_number }}</a></td>
                        <td class="px-2 py-1.5 tabular-nums">{{ $lead->qty_unknown ? '?' : $lead->qty }}</td>
                        <td class="px-2 py-1.5">{{ $lead->typeLabel() }}</td>
                        <td class="px-2 py-1.5 whitespace-nowrap">{{ $lead->delivered_date->format('M j, Y') }}</td>
                        <td class="px-2 py-1.5 whitespace-nowrap">
                            <span @class(['rounded-full px-2 py-0.5',
                                'bg-coral/25 font-semibold text-coral-700' => $daysLeft <= \App\Http\Controllers\ExclusiveLeadDashboardController::URGENT_DAYS,
                                'bg-[#fdecc0] font-semibold text-[#7a4f00]' => $daysLeft > \App\Http\Controllers\ExclusiveLeadDashboardController::URGENT_DAYS && $daysLeft <= \App\Http\Controllers\ExclusiveLeadDashboardController::SOON_DAYS,
                                'bg-teal-700/15 font-semibold text-teal-700' => $daysLeft > \App\Http\Controllers\ExclusiveLeadDashboardController::SOON_DAYS])>
                                {{ $lead->est_out_of_stock_date->format('M j') }}{{ $daysLeft > 0 && $daysLeft <= \App\Http\Controllers\ExclusiveLeadDashboardController::SOON_DAYS ? ' · in '.$daysLeft.' '.\Illuminate\Support\Str::plural('day', $daysLeft) : '' }}
                            </span>
                        </td>
                        <td class="px-4 py-1.5 text-muted">{{ $lead->statusLabel() ?? 'Pending' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($leads->hasPages())
        <div class="border-t border-line px-4 py-2">{{ ($full ?? false) ? $leads->links() : $leads->fragment('customers-title')->links() }}</div>
    @endif
@endif
