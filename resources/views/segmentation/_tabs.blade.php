<div @class(['flex shrink-0 border-b border-line', 'mb-3' => ! isset($toolbar), 'mb-2' => isset($toolbar)])>
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
</div>

@isset($toolbar)
    {{-- Filters on their own row under the tabs, starting at the left --}}
    <div class="mb-3 shrink-0">@include($toolbar)</div>
@endisset
