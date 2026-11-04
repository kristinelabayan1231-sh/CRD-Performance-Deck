{{-- Product Performance's tabs: the products' Pancake POS orders (overall, CRD, TSD or testing products; these keep the dates shown), and the Segmentation Tracker leads' summary. --}}
@php
    $productTabs = ['product-performance.index', 'product-performance.crd', 'product-performance.tsd', 'product-performance.testing'];
    $dates = request()->routeIs(...$productTabs) ? request()->only(['period', 'date', 'month', 'from', 'to']) : [];
@endphp
<nav class="flex flex-wrap gap-1 border-b-2 border-line" aria-label="Product Performance">
    @foreach (['product-performance.index' => 'Products', 'product-performance.crd' => 'CRD Products', 'product-performance.tsd' => 'TSD Products', 'product-performance.testing' => 'Testing Products', 'product-performance.segmentation-summary' => 'Segmentation Summary'] as $route => $name)
        <a href="{{ route($route, in_array($route, $productTabs, true) ? $dates : []) }}" @if (request()->routeIs($route)) aria-current="page" @endif
           @class(['-mb-0.5 rounded-t-lg border-b-2 px-4 py-2 text-sm font-semibold', 'border-brand-600 bg-white text-brand-600' => request()->routeIs($route), 'border-transparent text-muted hover:text-brand-600' => ! request()->routeIs($route)])>{{ $name }}</a>
    @endforeach
</nav>
