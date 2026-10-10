<h1 class="mb-4 text-xl font-semibold">Settings <span class="text-sm font-normal text-muted">· Reference data used across the deck.</span></h1>

{{-- Add more Settings sub tabs here: route name => [label, permission]. --}}
@php($tabs = [
    'settings.product-consumption.index' => ['Product Consumption', 'product_consumption.view'],
    'settings.pancake-pages.index' => ['Pancake Pages', 'pancake_pages.manage'],
    'settings.pancake-accounts.index' => ['Pancake Accounts', 'pancake_accounts.manage'],
    'settings.sales-goals.index' => ['Sales Goals', 'sales_goals.manage'],
    'settings.working-date.index' => ['Working Date', 'sales_goals.manage'],
    'settings.segmentation-options.index' => ['Segmentation Tracker', 'segmentation_options.manage'],
    'settings.connections.index' => ['Connections', 'connections.check'],
])

<nav class="mb-4 flex gap-1 overflow-x-auto border-b border-line" aria-label="Settings sections">
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
