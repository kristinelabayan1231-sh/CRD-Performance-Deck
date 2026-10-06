<div class="mb-6 flex items-center gap-3">
    <span class="grid size-10 place-items-center rounded-lg bg-gradient-to-br from-brand-500 to-violet text-white shadow">
        <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.6 7.6 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.6 7.6 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1c.5.4 1.1.7 1.7 1l.4 2.6h4l.4-2.6c.6-.3 1.2-.6 1.7-1l2.5 1 2-3.5L19.4 13ZM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z" transform="translate(-1 0)"/></svg>
    </span>
    <div>
        <h1 class="text-2xl font-semibold">Settings</h1>
        <p class="text-sm text-muted">Reference data used across the deck.</p>
    </div>
</div>

{{-- Add more Settings sub tabs here: route name => [label, permission]. --}}
@php($tabs = [
    'settings.product-consumption.index' => ['Product Consumption', 'product_consumption.view'],
    'settings.pancake-pages.index' => ['Pancake Pages', 'pancake_pages.manage'],
    'settings.sales-goals.index' => ['Sales Goals', 'sales_goals.manage'],
    'settings.connections.index' => ['Connections', 'connections.check'],
])

<nav class="mb-8 flex gap-1 overflow-x-auto border-b border-line" aria-label="Settings sections">
    @foreach ($tabs as $route => [$label, $permission])
        @can($permission)
            <a href="{{ route($route) }}" @if (request()->routeIs(str_replace('.index', '.*', $route))) aria-current="page" @endif
               @class([
                   '-mb-px shrink-0 border-b-2 px-4 py-2.5 text-sm font-semibold transition',
                   'border-brand-500 text-brand-600' => request()->routeIs(str_replace('.index', '.*', $route)),
                   'border-transparent text-muted hover:text-brand-600' => ! request()->routeIs(str_replace('.index', '.*', $route)),
               ])>{{ $label }}</a>
        @endcan
    @endforeach
</nav>
