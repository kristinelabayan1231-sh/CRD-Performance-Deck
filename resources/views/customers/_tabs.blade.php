{{-- Customer Database sections --}}
<nav class="-mb-px flex gap-1 overflow-x-auto border-b border-line" aria-label="Customer Database sections">
    @foreach (['customers.index' => 'Customers', 'customers.churn' => 'Churn'] as $route => $label)
        <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif
           @class([
               'shrink-0 border-b-2 px-4 py-2.5 text-sm font-semibold transition',
               'border-brand-500 text-brand-600' => request()->routeIs($route),
               'border-transparent text-muted hover:text-brand-600' => ! request()->routeIs($route),
           ])>{{ $label }}</a>
    @endforeach
</nav>
