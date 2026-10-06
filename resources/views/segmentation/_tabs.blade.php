<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-line" aria-label="Segmentation Tracker sections">
    @foreach (['segmentation.index' => 'Daily', 'segmentation.weekly' => 'Weekly Segmentation'] as $route => $label)
        <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif
           @class([
               '-mb-px shrink-0 border-b-2 px-4 py-2.5 text-sm font-semibold transition',
               'border-brand-500 text-brand-600' => request()->routeIs($route),
               'border-transparent text-muted hover:text-brand-600' => ! request()->routeIs($route),
           ])>{{ $label }}</a>
    @endforeach
</nav>
