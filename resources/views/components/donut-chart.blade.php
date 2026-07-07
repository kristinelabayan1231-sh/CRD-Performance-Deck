@props(['items', 'total'])

@php
    // Fixed-order categorical palette (validated: node scripts/validate_palette.js).
    $palette = ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#e34948', '#e87ba4', '#eb6834'];
    $otherColor = '#94a3b8';

    $maxSlices = 8;
    $sliceItems = $items;

    if (count($items) > $maxSlices) {
        $head = array_slice($items, 0, $maxSlices - 1);
        $tail = array_slice($items, $maxSlices - 1);

        $sliceItems = array_merge($head, [[
            'label' => 'Other',
            'sales_value' => array_sum(array_column($tail, 'sales_value')),
            'parcel_qty' => array_sum(array_column($tail, 'parcel_qty')),
            'product_cost' => array_sum(array_column($tail, 'product_cost')),
            'is_other' => true,
        ]]);
    }

    $radius = 15.91549430918954; // circumference = 100, so dasharray maps directly to percentage points
    $gap = 0.6; // visual separation between slices, in percentage points
    $cumulative = 0;
@endphp

<div class="flex flex-col items-center gap-5 px-5 py-5 sm:flex-row sm:items-center">
    <div class="relative h-36 w-36 shrink-0">
        <svg viewBox="0 0 42 42" class="h-full w-full -rotate-0">
            <circle cx="21" cy="21" r="{{ $radius }}" fill="transparent" stroke="#e1e0d9" stroke-width="4"></circle>
            @foreach ($sliceItems as $i => $item)
                @php
                    $pct = $total > 0 ? ($item['sales_value'] / $total * 100) : 0;
                    $visiblePct = max($pct - $gap, 0);
                    $offset = 25 - $cumulative;
                    $cumulative += $pct;
                    $color = $item['is_other'] ?? false ? $otherColor : $palette[$i % count($palette)];
                @endphp
                @if ($pct > 0)
                    <circle cx="21" cy="21" r="{{ $radius }}" fill="transparent" stroke="{{ $color }}" stroke-width="4"
                        stroke-dasharray="{{ round($visiblePct, 3) }} {{ round(100 - $visiblePct, 3) }}"
                        stroke-dashoffset="{{ round($offset, 3) }}"></circle>
                @endif
            @endforeach
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
            <span class="text-[10px] font-medium uppercase tracking-wide text-slate-400">Total</span>
            <span class="text-sm font-semibold text-slate-900">₱{{ number_format($total, 0) }}</span>
        </div>
    </div>

    <ul class="w-full min-w-0 space-y-2">
        @foreach ($sliceItems as $i => $item)
            @php
                $pct = $total > 0 ? ($item['sales_value'] / $total * 100) : 0;
                $color = $item['is_other'] ?? false ? $otherColor : $palette[$i % count($palette)];
            @endphp
            <li class="flex items-center gap-2 text-xs">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $color }}"></span>
                <span class="min-w-0 flex-1 truncate text-slate-600" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                <span class="shrink-0 tabular-nums text-slate-500">{{ number_format($pct, 1) }}%</span>
            </li>
        @endforeach
    </ul>
</div>
