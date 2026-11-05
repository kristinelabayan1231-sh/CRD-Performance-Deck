{{-- Per product: one bar split by Customer's Feedback choice, in the donut's colours and order. Hover a part for its count.
     Expects $byProduct (product => [choice key => count]), $slices (the donut's, for labels and colours), $fold (choice
     key => slice key, folding the rare ones into Other) and $noun. --}}
<div class="x-scrollbar h-full overflow-y-auto rounded-xl bg-white p-4 shadow-sm">
    <h3 class="mb-2 text-xs font-bold tracking-wide text-muted uppercase">By product</h3>
    <ul class="space-y-2.5">
        @foreach ($byProduct as $product => $counts)
            @php($folded = collect($counts)->groupBy(fn ($count, $key) => $fold($key), true)->map->sum())
            @php($productTotal = $folded->sum())
            <li>
                <p class="mb-1 flex justify-between gap-2 text-xs">
                    <span class="truncate font-semibold text-ink" title="{{ $product }}">{{ $product ?: '—' }}</span>
                    <span class="shrink-0 text-muted tabular-nums">{{ number_format($productTotal) }} {{ $noun }}</span>
                </p>
                <div class="flex h-4 overflow-hidden rounded bg-canvas" role="img"
                     aria-label="{{ $product }}: {{ $slices->filter(fn ($s) => ($folded[$s['key']] ?? 0) > 0)->map(fn ($s) => $s['label'].' '.$folded[$s['key']])->join(', ') }}">
                    @foreach ($slices as $slice)
                        @php($value = $folded[$slice['key']] ?? 0)
                        @if ($value > 0)
                            @php($share = $value / $productTotal * 100)
                            {{-- 1px surface gap between parts --}}
                            <span class="block min-w-1 border-r border-white last:border-r-0" style="width: {{ $share }}%; background: {{ $slice['color'] }}"
                                  data-tip="{{ $slice['label'] }}: {{ number_format($value) }} ({{ round($share) }}%)"></span>
                        @endif
                    @endforeach
                </div>
            </li>
        @endforeach
    </ul>
</div>
