{{-- The Segmentation Tracker's search and filters in one bar: search, CRA, type, product, month, day (with the days before and
     after it), a date range and the columns. (The Exclusive Lead Tracker keeps _filters, with its Filters pop-up.) --}}
@php
    $field = 'h-10 rounded-[0.625rem] border-0 bg-canvas text-sm text-ink focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $roundButton = 'grid size-10 shrink-0 place-items-center rounded-full bg-canvas text-ink transition hover:bg-brand-600 hover:text-white';
    $oneDay = empty($filters['from']) && ! empty($filters['date']) && empty($filters['view']) && ! $filters['q'] ? \Carbon\CarbonImmutable::parse($filters['date']) : null;
    $ranged = ! empty($filters['from']);
    $dayUrl = fn (\Carbon\CarbonImmutable $on) => request()->fullUrlWithQuery(['date' => $on->toDateString(), 'month' => null, 'from' => null, 'to' => null, 'view' => null, 'repeat' => null, 'page' => null, 'processed_page' => null]);
@endphp
<form method="GET" action="{{ route('segmentation.index') }}" class="flex flex-wrap items-center gap-1.5">
    {{-- First submit button, so Enter in the search submits plainly instead of clicking the range's Apply (which needs both dates). --}}
    <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true"></button>
    @if ($filters['view'] ?? null)
        <input type="hidden" name="view" value="{{ $filters['view'] }}">
    @endif
    @if ($filters['repeat'] ?? null)
        <input type="hidden" name="repeat" value="{{ $filters['repeat'] }}">
    @endif

    {{-- Search: name, contact # or order #, across every lead day (Enter to search) --}}
    <label class="relative min-w-44 flex-1" title="Search leads">
        <span class="sr-only">Search leads</span>
        <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Name, contact or order #" maxlength="100"
               @class([$field, 'w-full pr-8 pl-9 [&::-webkit-search-cancel-button]:hidden', 'ring-2 ring-brand-500' => $filters['q']])>
        @if ($filters['q'])
            <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null, 'processed_page' => null]) }}" title="Clear search" aria-label="Clear search"
               class="absolute top-1/2 right-2 grid size-6 -translate-y-1/2 place-items-center rounded-full text-muted hover:text-ink">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </a>
        @endif
    </label>

    {{-- Everyone picks a CRA; a CRA's own tracker is marked (you) and is where they start. --}}
    <select name="cra" aria-label="CRA" class="{{ $field }} w-40 truncate pr-8 pl-3" onchange="this.form.submit()">
        <option value="all" @selected($filters['cra'] === 'all')>All CRAs</option>
        @foreach ($cras as $cra)
            <option value="{{ $cra->id }}" @selected($filters['cra'] === (string) $cra->id)>{{ $cra->displayName() }}{{ $cra->id === auth()->id() ? ' (you)' : '' }}</option>
        @endforeach
    </select>
    <select name="type" aria-label="Type" @class([$field, 'w-32 truncate pr-8 pl-3', 'ring-2 ring-brand-500' => $filters['type'] ?? null]) onchange="this.form.submit()">
        <option value="">All types</option>
        @foreach (\App\Models\Lead::TYPES as $value => $label)
            <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="product" aria-label="Product" @class([$field, 'w-40 truncate pr-8 pl-3', 'ring-2 ring-brand-500' => $filters['product'] ?? null]) onchange="this.form.submit()">
        <option value="">All products</option>
        @foreach ($products as $product)
            <option value="{{ $product }}" @selected(($filters['product'] ?? '') === $product)>{{ $product }}</option>
        @endforeach
    </select>
    <input type="month" name="month" value="{{ $filters['month'] }}" aria-label="Month" title="Month" class="{{ $field }} w-40 px-3"
           onchange="this.form.date.value=''; this.form.from.value=''; this.form.to.value=''; this.form.submit()">

    {{-- The day shown, with the days before and after it --}}
    <span class="flex items-center gap-1" role="group" aria-label="Day">
        @if ($oneDay)
            <a href="{{ $dayUrl($oneDay->subDay()) }}" title="Previous day" aria-label="Previous day" class="{{ $roundButton }} size-8">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 6-6 6 6 6"/></svg>
            </a>
        @endif
        <input type="date" name="date" value="{{ empty($filters['from']) ? ($filters['date'] ?? '') : '' }}" aria-label="Lead day" title="{{ $filters['q'] ? 'All days' : 'Lead day' }}"
               class="{{ $field }} w-40 px-3" onchange="this.form.from.value=''; this.form.to.value=''; this.form.submit()">
        @if ($oneDay && $oneDay->lt($today))
            <a href="{{ $dayUrl($oneDay->addDay()) }}" title="Next day" aria-label="Next day" class="{{ $roundButton }} size-8">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>
            </a>
        @endif
    </span>

    {{-- A date range: leads from every lead day in it --}}
    <details class="relative shrink-0" @if ($errors->has('from') || $errors->has('to')) open @endif>
        <summary title="{{ $ranged ? \App\Http\Controllers\SegmentationController::periodLabel(\Carbon\CarbonImmutable::parse($filters['from']), \Carbon\CarbonImmutable::parse($filters['to'])) : 'Date range' }}" aria-label="Pick a date range"
                 @class([$roundButton, 'cursor-pointer list-none [&::-webkit-details-marker]:hidden', '!bg-brand-600 !text-white' => $ranged])>
            <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M16 3v4M8 3v4M3 10h18M7 15h4M13 15h4"/></svg>
        </summary>
        <div class="absolute right-0 z-30 mt-2 w-72 space-y-2 rounded-2xl border border-line bg-white p-3 shadow-lg">
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
                <button type="submit" class="h-9 flex-1 rounded-full bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700"
                        onclick="if (!this.form.from.value || !this.form.to.value) { alert('Pick both a From and a To date.'); return false; } this.form.date.value='';">Apply</button>
                @if ($ranged)
                    <a href="{{ request()->fullUrlWithQuery(['from' => null, 'to' => null, 'page' => null, 'processed_page' => null]) }}"
                       class="grid h-9 flex-1 place-items-center rounded-full border border-line text-sm font-medium hover:border-ink">Clear</a>
                @endif
            </div>
        </div>
    </details>

    @include('segmentation._column-picker', ['pickerClass' => $roundButton.' grid'])

    <noscript><button type="submit" class="h-10 rounded-full bg-brand-600 px-4 text-sm font-semibold text-white">Apply</button></noscript>
</form>
