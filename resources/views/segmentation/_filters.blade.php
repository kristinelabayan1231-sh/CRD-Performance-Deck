{{-- Tracker filters, shown under the Daily / Weekly tabs. Icons only, except Type and Product. --}}
@php($field = 'h-9 rounded-lg border border-line bg-white text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')
@php($icon = 'pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted')

{{-- One line on a laptop screen and wider: search and filters, then dates and columns on the right; wraps on narrower screens. --}}
<form method="GET" action="{{ route('segmentation.index') }}" class="flex flex-wrap items-center gap-2">
    {{-- First submit button, so Enter in the search submits plainly instead of clicking the range's Apply (which needs both dates). --}}
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true"></button>
    @if ($filters['repeat'] ?? null)
        <input type="hidden" name="repeat" value="{{ $filters['repeat'] }}">
    @endif
    {{-- Search: name, contact # or order #, across every lead day (Enter to search) --}}
    <label class="relative min-w-36 flex-1" title="Search leads">
        <span class="sr-only">Search leads</span>
        <svg class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Name, contact or order #" maxlength="100"
               @class([$field, 'w-full pr-8 pl-8 [&::-webkit-search-cancel-button]:hidden', 'border-brand-500 ring-2 ring-brand-200' => $filters['q']])>
        @if ($filters['q'])
            <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null, 'processed_page' => null]) }}" title="Clear search" aria-label="Clear search"
               class="absolute top-1/2 right-2 grid size-5 -translate-y-1/2 place-items-center rounded text-muted hover:text-brand-600">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </a>
        @endif
    </label>

    {{-- Everyone picks a CRA; a CRA's own tracker is marked (you) and is where they start. --}}
    <label class="relative shrink-0" title="CRA">
        <span class="sr-only">CRA</span>
        <svg class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 21a8 8 0 0 1 16 0"/></svg>
        <select name="cra" class="{{ $field }} w-32 truncate pr-7 pl-8" onchange="this.form.submit()">
            <option value="all" @selected($filters['cra'] === 'all')>All</option>
            @foreach ($cras as $cra)
                <option value="{{ $cra->id }}" @selected($filters['cra'] === (string) $cra->id)>{{ $cra->displayName() }}{{ ! $canViewAll && $cra->id === auth()->id() ? ' (you)' : '' }}</option>
            @endforeach
        </select>
    </label>

    <select name="type" aria-label="Type" class="{{ $field }} w-32 shrink-0 truncate pr-7 pl-3" onchange="this.form.submit()">
        <option value="">All types</option>
        @foreach (\App\Models\Lead::TYPES as $value => $label)
            <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>

    <select name="product" aria-label="Product" class="{{ $field }} w-36 shrink-0 truncate pr-7 pl-3" onchange="this.form.submit()">
        <option value="">All products</option>
        @foreach ($products as $product)
            <option value="{{ $product }}" @selected(($filters['product'] ?? '') === $product)>{{ $product }}</option>
        @endforeach
    </select>

    {{-- Dates and columns on the right --}}
    <label class="relative shrink-0" title="Month">
        <span class="sr-only">Month</span>
        <svg class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"/></svg>
        <input type="month" name="month" value="{{ $filters['month'] }}" class="{{ $field }} w-40 pr-1 pl-8"
               onchange="this.form.date.value=''; this.form.from.value=''; this.form.to.value=''; this.form.submit()">
    </label>

    <label class="relative shrink-0" title="Date">
        <span class="sr-only">Date</span>
        <svg class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"/><circle cx="12" cy="15" r="1.5" fill="currentColor"/></svg>
        <input type="date" name="date" value="{{ empty($filters['from']) ? ($filters['date'] ?? '') : '' }}" class="{{ $field }} w-36 pr-1 pl-8"
               onchange="this.form.from.value=''; this.form.to.value=''; this.form.submit()">
    </label>

    {{-- Date range: the lead days from–to (overrides the date and month above) --}}
    @php($ranged = ! empty($filters['from']))
    <details class="relative shrink-0" @if ($errors->has('from') || $errors->has('to')) open @endif>
        <summary title="Date range" aria-label="Date range"
                 @class(['flex h-9 cursor-pointer list-none items-center gap-2 rounded-lg border bg-white px-2.5 text-sm [&::-webkit-details-marker]:hidden',
                         'border-brand-500 font-semibold text-brand-700 ring-2 ring-brand-200' => $ranged,
                         'border-line text-muted hover:border-brand-400 hover:text-brand-600' => ! $ranged])>
            <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4M7 15h4m2 0h4"/></svg>
            <span class="whitespace-nowrap">{{ $ranged ? \App\Http\Controllers\SegmentationController::periodLabel(\Carbon\CarbonImmutable::parse($filters['from']), \Carbon\CarbonImmutable::parse($filters['to'])) : 'Range' }}</span>
        </summary>
        <div class="absolute right-0 z-30 mt-2 w-64 rounded-xl border border-line bg-white p-3 shadow-lg">
            <p class="pb-2 text-xs text-muted">Leads from every lead day in this range.</p>
            <label class="mb-2 block">
                <span class="mb-1 block text-xs font-semibold text-muted">From</span>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="{{ $field }} w-full px-2.5">
            </label>
            <label class="mb-3 block">
                <span class="mb-1 block text-xs font-semibold text-muted">To</span>
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="{{ $field }} w-full px-2.5">
            </label>
            <div class="flex gap-2">
                <button type="submit" class="h-9 flex-1 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700"
                        onclick="if (!this.form.from.value || !this.form.to.value) { alert('Pick both a From and a To date.'); return false; } this.form.date.value='';">Apply</button>
                @if ($ranged)
                    <a href="{{ request()->fullUrlWithQuery(['from' => null, 'to' => null, 'page' => null, 'processed_page' => null]) }}"
                       class="grid h-9 flex-1 place-items-center rounded-lg border border-line text-sm font-medium hover:border-brand-400 hover:text-brand-600">Clear</a>
                @endif
            </div>
        </div>
    </details>

    <details class="relative shrink-0" data-column-picker>
        <summary title="Columns" aria-label="Columns"
                 class="grid size-9 cursor-pointer list-none place-items-center rounded-lg border border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600 [&::-webkit-details-marker]:hidden">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M9 4v16M15 4v16M4 4h16v16H4z"/></svg>
        </summary>
        <div class="absolute right-0 z-30 mt-2 w-72 rounded-xl border border-line bg-white p-3 shadow-lg">
            <p class="px-1 pb-2 text-xs text-muted">Status is always shown.</p>
            <div class="max-h-[60vh] overflow-y-auto">
            @foreach ([...config('segmentation.lead_columns'), ...$optionalColumns] as $key => $column)
                @if ($loop->index === count(config('segmentation.lead_columns')))
                    <hr class="my-1.5 border-line">
                @endif
                <label @class(['flex items-center justify-between gap-2 rounded-lg px-1 py-1.5 text-sm', 'text-muted' => $column['coming_soon'] ?? false])>
                    <span class="flex items-center gap-2">
                        <input type="checkbox" class="size-4 accent-brand-500" data-column-toggle="{{ $key }}"
                               @if ($column['coming_soon'] ?? false) disabled @else checked @endif>
                        {{ $column['label'] }}
                    </span>
                    @if ($column['coming_soon'] ?? false)
                        <span class="rounded-full bg-canvas px-2 py-0.5 text-xs font-semibold">Coming soon</span>
                    @endif
                </label>
            @endforeach
            </div>
            <div class="mt-2 flex gap-2 border-t border-line pt-2">
                <button type="button" data-columns-all="show" class="flex-1 rounded-lg px-2 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Show all</button>
                <button type="button" data-columns-all="hide" class="flex-1 rounded-lg px-2 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Hide all</button>
            </div>
        </div>
    </details>

    <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white">Apply</button></noscript>
</form>
