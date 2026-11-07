@props(['action', 'filters', 'clearUrl', 'hidden' => [], 'placeholder' => 'Name, contact number, order # or product'])

{{-- Search and All / High AOV / VIP for a customer list. Extra controls for the list go in the slot. --}}
@php($control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')
<form method="GET" action="{{ $action }}" class="flex flex-wrap items-end gap-x-5 gap-y-4 rounded-xl bg-white p-4 shadow-sm">
    @foreach ($hidden as $name => $value)
        @if (filled($value))
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endif
    @endforeach

    <div class="flex min-w-64 flex-1 flex-col gap-1.5">
        <label for="customer-list-search" class="text-xs font-semibold tracking-wide text-muted uppercase">Search</label>
        <div class="flex">
            <input id="customer-list-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="{{ $placeholder }}"
                   class="h-9 min-w-0 flex-1 rounded-l-lg border border-r-0 border-line bg-white px-2.5 text-sm text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
            <button type="submit" class="h-9 shrink-0 rounded-r-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
        </div>
    </div>

    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
        Show
        <select name="show" onchange="this.form.submit()" class="{{ $control }} normal-case">
            @foreach (\App\Services\HighValueCustomers::SHOW as $value => $label)
                <option value="{{ $value }}" @selected(($filters['show'] ?? 'all') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>

    {{ $slot }}

    @if (filled($filters['search'] ?? null) || ($filters['show'] ?? 'all') !== 'all')
        <a href="{{ $clearUrl }}" class="h-9 content-center text-sm font-medium text-muted hover:text-brand-600">Clear</a>
    @endif
</form>
