{{-- Leads by priority as one bar with a labelled legend. $mix: source => count. --}}
@php($total = array_sum($mix))
@php($parts = [
    'leftover' => ['Leftover', 'bg-[#e8b84a]'],
    'high_value' => ['High AOV / VIP', 'bg-brand-500'],
    'handled' => ['Handled twice', 'bg-teal'],
    'random' => ['Random', 'bg-sky'],
])
<div>
    @if ($total > 0)
        <div class="flex h-2.5 overflow-hidden rounded-full bg-line" role="img"
             aria-label="{{ collect($parts)->map(fn ($part, $key) => $part[0].' '.($mix[$key] ?? 0))->implode(', ') }}">
            @foreach ($parts as $key => [$label, $color])
                @if (($mix[$key] ?? 0) > 0)
                    <span class="{{ $color }}" style="width: {{ ($mix[$key] / $total) * 100 }}%"></span>
                @endif
            @endforeach
        </div>
    @else
        <div class="h-2.5 rounded-full bg-line" aria-hidden="true"></div>
    @endif
    <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
        @foreach ($parts as $key => [$label, $color])
            <li class="flex items-center gap-1.5"><span aria-hidden="true" class="size-2 rounded-full {{ $color }}"></span>{{ $label }} <strong class="text-ink tabular-nums">{{ number_format($mix[$key] ?? 0) }}</strong></li>
        @endforeach
    </ul>
</div>
