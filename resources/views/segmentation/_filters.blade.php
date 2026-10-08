{{-- Tracker filters, shown beside the Daily / Weekly tabs. Icons only, except Type and Status. --}}
@php($field = 'h-9 rounded-lg border border-line bg-white text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')
@php($icon = 'pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted')

<form method="GET" action="{{ route('segmentation.index') }}" class="flex flex-wrap items-center gap-2">
    <input type="hidden" name="show" value="{{ $filters['show'] }}">

    {{-- Search: name, contact # or order #, across every lead day (Enter to search) --}}
    <label class="relative" title="Search leads">
        <span class="sr-only">Search leads</span>
        <svg class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search name, contact # or order #" maxlength="100"
               @class([$field, 'w-64 pr-8 pl-8 [&::-webkit-search-cancel-button]:hidden', 'border-brand-500 ring-2 ring-brand-200' => $filters['q']])>
        @if ($filters['q'])
            <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null, 'processed_page' => null]) }}" title="Clear search" aria-label="Clear search"
               class="absolute top-1/2 right-2 grid size-5 -translate-y-1/2 place-items-center rounded text-muted hover:text-brand-600">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </a>
        @endif
    </label>

    <label class="relative" title="Month">
        <span class="sr-only">Month</span>
        <svg class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"/></svg>
        <input type="month" name="month" value="{{ $filters['month'] }}" class="{{ $field }} pr-2 pl-8"
               onchange="this.form.date.value=''; this.form.submit()">
    </label>

    <label class="relative" title="Date">
        <span class="sr-only">Date</span>
        <svg class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"/><circle cx="12" cy="15" r="1.5" fill="currentColor"/></svg>
        <input type="date" name="date" value="{{ $filters['date'] ?? '' }}" class="{{ $field }} pr-2 pl-8" onchange="this.form.submit()">
    </label>

    @if ($canViewAll)
        <label class="relative" title="CRA">
            <span class="sr-only">CRA</span>
            <svg class="{{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 21a8 8 0 0 1 16 0"/></svg>
            <select name="cra" class="{{ $field }} pr-8 pl-8" onchange="this.form.submit()">
                <option value="all" @selected($filters['cra'] === 'all')>All</option>
                @foreach ($cras as $cra)
                    <option value="{{ $cra->id }}" @selected($filters['cra'] === (string) $cra->id)>{{ $cra->displayName() }}</option>
                @endforeach
            </select>
        </label>
    @endif

    <select name="type" aria-label="Type" class="{{ $field }} pr-8 pl-3" onchange="this.form.submit()">
        <option value="">All types</option>
        @foreach (\App\Models\Lead::TYPES as $value => $label)
            <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>

    <select name="status" aria-label="Status" class="{{ $field }} pr-8 pl-3" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <option value="none" @selected(($filters['status'] ?? '') === 'none')>No status yet</option>
        @foreach ($statuses as $value => [$label])
            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>

    {{-- Which lists to show --}}
    <div class="flex h-9 items-center rounded-lg border border-line bg-white p-0.5 text-xs font-semibold" role="group" aria-label="Show">
        @foreach (['all' => 'All', 'unprocessed' => 'Pending', 'processed' => 'Catered'] as $value => $label)
            <a href="{{ request()->fullUrlWithQuery(['show' => $value, 'page' => null, 'processed_page' => null]) }}"
               @if ($filters['show'] === $value) aria-current="true" @endif
               @class([
                   'grid h-full place-items-center rounded-md px-2.5 transition',
                   'bg-brand-600 text-white' => $filters['show'] === $value,
                   'text-muted hover:text-brand-600' => $filters['show'] !== $value,
               ])>{{ $label }}</a>
        @endforeach
        <span class="grid h-full cursor-not-allowed place-items-center px-2.5 text-muted/50" title="Backlogs are turned off for now" aria-disabled="true">Backlogs</span>
    </div>

    <a href="{{ route('segmentation.index') }}" title="Today" aria-label="Today"
       class="grid size-9 place-items-center rounded-lg border border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600">
        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12a8 8 0 1 0 2.3-5.7M4 4v4h4"/></svg>
    </a>

    <details class="relative" data-column-picker>
        <summary title="Columns" aria-label="Columns"
                 class="grid size-9 cursor-pointer list-none place-items-center rounded-lg border border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600 [&::-webkit-details-marker]:hidden">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M9 4v16M15 4v16M4 4h16v16H4z"/></svg>
        </summary>
        <div class="absolute right-0 z-30 mt-2 w-72 rounded-xl border border-line bg-white p-3 shadow-lg">
            <p class="px-1 pb-2 text-xs text-muted">Customer Name to Status are always shown.</p>
            @foreach ($optionalColumns as $key => $column)
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
            <div class="mt-2 flex gap-2 border-t border-line pt-2">
                <button type="button" data-columns-all="show" class="flex-1 rounded-lg px-2 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Show all</button>
                <button type="button" data-columns-all="hide" class="flex-1 rounded-lg px-2 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Hide all</button>
            </div>
        </div>
    </details>

    <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white">Apply</button></noscript>
</form>
