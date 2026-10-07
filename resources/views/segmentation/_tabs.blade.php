<div class="mb-3 flex shrink-0 flex-wrap items-end justify-between gap-x-4 gap-y-2 border-b border-line">
    <nav class="-mb-px flex gap-1 overflow-x-auto" aria-label="Segmentation Tracker sections">
        @foreach (['segmentation.index' => 'Daily', 'segmentation.weekly' => 'Weekly Segmentation'] as $route => $label)
            <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif
               @class([
                   'shrink-0 border-b-2 px-4 py-2.5 text-sm font-semibold transition',
                   'border-brand-500 text-brand-600' => request()->routeIs($route),
                   'border-transparent text-muted hover:text-brand-600' => ! request()->routeIs($route),
               ])>{{ $label }}</a>
        @endforeach
    </nav>

    @isset($toolbar)
        <div class="pb-2">@include($toolbar)</div>
    @endisset
</div>
