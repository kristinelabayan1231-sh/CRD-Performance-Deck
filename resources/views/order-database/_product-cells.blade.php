{{-- Product Report's Total qty, Total sales, Product cost and the three rates (green from 80%, amber from 50%, else red). --}}
@php
    $rates = \App\Services\ProductReport::rates($row);
    $tone = fn (int $delivered) => match (true) {
        $delivered >= 80 => 'bg-[#d4edbc] text-[#11734b]',
        $delivered >= 50 => 'bg-[#ffe5a0] text-[#473821]',
        default => 'bg-[#ffcfc9] text-[#b10202]',
    };
@endphp
<td class="px-3 {{ $cell }} text-center font-mono">{{ number_format($row['qty']) }}</td>
<td class="px-3 {{ $cell }} text-right font-mono">{{ $peso($row['sales']) }}</td>
<td class="px-3 {{ $cell }} text-right font-mono">
    @if ($row['cost'] !== null)
        {{ $peso($row['cost']) }}
    @else
        <span class="text-muted/60" title="No product cost in Settings → Product Consumption">–</span>
    @endif
</td>
@foreach (['actual_del' => fn (int $rate) => $tone($rate), 'actual_rts' => fn (int $rate) => $tone(100 - $rate), 'running_del' => fn (int $rate) => $tone($rate)] as $key => $color)
    <td @class(['text-center', "px-3 {$cell}" => $key !== 'running_del', "px-4 {$cell}" => $key === 'running_del'])>
        @if ($rates[$key] !== null)
            <span class="inline-block min-w-12 rounded-full px-2 py-0.5 text-xs font-semibold {{ $color($rates[$key]) }}">{{ $rates[$key] }}%</span>
        @else
            <span class="text-muted/60">–</span>
        @endif
    </td>
@endforeach
