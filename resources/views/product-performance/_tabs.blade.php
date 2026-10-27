{{-- Product Performance's tabs: the products' Pancake POS orders, and the Segmentation Tracker leads' summary. --}}
<nav class="flex gap-1 border-b-2 border-line" aria-label="Product Performance">
    @foreach (['product-performance.index' => 'Products', 'product-performance.segmentation-summary' => 'Segmentation Summary'] as $route => $name)
        <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif
           @class(['-mb-0.5 rounded-t-lg border-b-2 px-4 py-2 text-sm font-semibold', 'border-brand-600 bg-white text-brand-600' => request()->routeIs($route), 'border-transparent text-muted hover:text-brand-600' => ! request()->routeIs($route)])>{{ $name }}</a>
    @endforeach
</nav>
