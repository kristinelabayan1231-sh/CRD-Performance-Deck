{{-- Tracker filters on one line: the day, search, Filters (type, product, dates) and columns; also on the Exclusive Lead Tracker ($filtersRoute, no All CRAs). --}}
@php($field = 'h-9 rounded-lg border border-line bg-white text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')
@php($icon = 'pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted')

{{-- Wraps on narrower screens. --}}
<form method="GET" action="{{ route($filtersRoute ?? 'segmentation.index') }}" class="flex flex-wrap items-center gap-2">
    {{-- First submit button, so Enter in the search submits plainly instead of clicking the range's Apply (which needs both dates). --}}
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true"></button>
    @if ($filters['view'] ?? null)
        <input type="hidden" name="view" value="{{ $filters['view'] }}">
    @endif
    @if ($filters['repeat'] ?? null)
        <input type="hidden" name="repeat" value="{{ $filters['repeat'] }}">
    @endif
    {{-- The day shown, with the days before and after it --}}
    @php($oneDay = empty($filters['from']) && ! empty($filters['date']) && empty($filters['view']) && ! $filters['q'] ? \Carbon\CarbonImmutable::parse($filters['date']) : null)
    @php($ranged = ! empty($filters['from']))
    @php($dayUrl = fn (\Carbon\CarbonImmutable $on) => request()->fullUrlWithQuery(['date' => $on->toDateString(), 'month' => null, 'from' => null, 'to' => null, 'view' => null, 'repeat' => null, 'page' => null, 'processed_page' => null]))
    <div class="flex shrink-0 items-center gap-1" role="group" aria-label="Day">
        @if ($oneDay)
            <a href="{{ $dayUrl($oneDay->subDay()) }}" title="Previous day" aria-label="Previous day" class="grid size-9 place-items-center rounded-lg border border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600">‹</a>
        @endif
        <strong class="min-w-24 px-1 text-center text-sm whitespace-nowrap">{{ match (true) {
            (bool) $filters['q'] => 'All days',
            $ranged => \App\Http\Controllers\SegmentationController::periodLabel(\Carbon\CarbonImmutable::parse($filters['from']), \Carbon\CarbonImmutable::parse($filters['to'])),
            $oneDay !== null => $oneDay->format('D, M j'),
            default => \Carbon\CarbonImmutable::parse($filters['month'].'-01')->format('F Y'),
        } }}</strong>
        @if ($oneDay && $oneDay->lt($today))
            <a href="{{ $dayUrl($oneDay->addDay()) }}" title="Next day" aria-label="Next day" class="grid size-9 place-items-center rounded-lg border border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600">›</a>
        @endif
    </div>

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
            @unless ($oneCraAtATime ?? false)
                <option value="all" @selected($filters['cra'] === 'all')>All</option>
            @endunless
            @foreach ($cras as $cra)
                <option value="{{ $cra->id }}" @selected($filters['cra'] === (string) $cra->id)>{{ $cra->displayName() }}{{ $cra->id === auth()->id() ? ' (you)' : '' }}</option>
            @endforeach
        </select>
    </label>

    {{-- Type, product and dates, in a pop-up --}}
    @php($filtersOn = collect([$filters['type'] ?? null, $filters['product'] ?? null, $filters['from'] ?? null, $filters['view'] ?? null])->filter()->count())
    <details class="relative shrink-0" @if ($errors->has('from') || $errors->has('to')) open @endif>
        <summary @class(['flex h-9 cursor-pointer list-none items-center gap-2 rounded-lg border bg-white px-3 text-sm font-medium [&::-webkit-details-marker]:hidden',
                         'border-brand-500 text-brand-700 ring-2 ring-brand-200' => $filtersOn > 0,
                         'border-line hover:border-brand-400 hover:text-brand-600' => $filtersOn === 0])>Filters{{ $filtersOn ? ' · '.$filtersOn : '' }}</summary>
        <div class="absolute right-0 z-30 mt-2 w-72 space-y-2 rounded-xl border border-line bg-white p-3 shadow-lg">
            @isset($dayLinks)
                @include($dayLinks)
            @endisset
            <select name="type" aria-label="Type" class="{{ $field }} w-full truncate pr-7 pl-3" onchange="this.form.submit()">
                <option value="">All types</option>
                @foreach (\App\Models\Lead::TYPES as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="product" aria-label="Product" class="{{ $field }} w-full truncate pr-7 pl-3" onchange="this.form.submit()">
                <option value="">All products</option>
                @foreach ($products as $product)
                    <option value="{{ $product }}" @selected(($filters['product'] ?? '') === $product)>{{ $product }}</option>
                @endforeach
            </select>
            <label class="relative block" title="Month">
                <span class="sr-only">Month</span>
                <svg class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"/></svg>
                <input type="month" name="month" value="{{ $filters['month'] }}" class="{{ $field }} w-full pr-1 pl-8"
                       onchange="this.form.date.value=''; this.form.from.value=''; this.form.to.value=''; this.form.submit()">
            </label>
            <label class="relative block" title="Date">
                <span class="sr-only">Date</span>
                <svg class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"/><circle cx="12" cy="15" r="1.5" fill="currentColor"/></svg>
                <input type="date" name="date" value="{{ empty($filters['from']) ? ($filters['date'] ?? '') : '' }}" class="{{ $field }} w-full pr-1 pl-8"
                       onchange="this.form.from.value=''; this.form.to.value=''; this.form.submit()">
            </label>
            <div class="space-y-2 border-t border-line pt-3">
                <p class="text-xs text-muted">{{ $rangeHint ?? 'Leads from every lead day in this range.' }}</p>
                <div class="grid grid-cols-2 gap-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold text-muted">From</span>
                        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="{{ $field }} w-full px-2">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold text-muted">To</span>
                        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="{{ $field }} w-full px-2">
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="h-9 flex-1 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700"
                            onclick="if (!this.form.from.value || !this.form.to.value) { alert('Pick both a From and a To date.'); return false; } this.form.date.value='';">Apply</button>
                    @if ($ranged)
                        <a href="{{ request()->fullUrlWithQuery(['from' => null, 'to' => null, 'page' => null, 'processed_page' => null]) }}"
                           class="grid h-9 flex-1 place-items-center rounded-lg border border-line text-sm font-medium hover:border-brand-400 hover:text-brand-600">Clear</a>
                    @endif
                </div>
            </div>
        </div>
    </details>

    @include('segmentation._column-picker')

    <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white">Apply</button></noscript>
</form>
