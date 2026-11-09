{{-- The Exclusive Leads Dashboard's customer list full size, shown in the dashboard's Expand pop-up. --}}
<x-layouts.bare title="Exclusive Leads Dashboard · Customers">
    @php($full = true)
    <p class="flex flex-wrap items-center gap-3 px-4 pt-3 text-xs text-muted">
        <span class="text-sm font-semibold text-ink">{{ $search !== null ? 'Search “'.$search.'”' : ($picked ? $picked['product'].' · '.\App\Http\Controllers\ExclusiveLeadDashboardController::BUCKETS[$picked['qty']] : 'Customers') }} · {{ number_format($leads?->total() ?? 0) }} {{ \Illuminate\Support\Str::plural('customer', $leads?->total() ?? 0) }}</span>
        <span>Soonest to run out first</span>
    </p>
    @include('exclusive-leads._customers')
</x-layouts.bare>
