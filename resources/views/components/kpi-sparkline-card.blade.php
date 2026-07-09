@props(['label', 'icon', 'values', 'days', 'isCurrency' => true, 'diverging' => false])

@php
    $total = array_sum($values);
    $max = max(array_map('abs', $values)) ?: 1;
    $totalColorClass = $diverging ? ($total >= 0 ? 'text-teal-600' : 'text-red-600') : 'text-slate-900';
@endphp

<div class="rounded-xl bg-white p-5 shadow-sm">
    <div class="flex items-center gap-2 text-slate-400">
        {{ $icon }}
        <p class="text-xs font-medium uppercase tracking-wide">{{ $label }}</p>
    </div>
    <p class="mt-2 text-xl font-semibold {{ $totalColorClass }}">{{ $isCurrency ? '₱' : '' }}{{ number_format($total, $isCurrency ? 2 : 0) }}</p>

    <div class="mt-3 flex items-end gap-2">
        @foreach ($values as $i => $value)
            @php
                $heightPct = max(6, round(abs($value) / $max * 100));
                $barColor = $diverging ? ($value >= 0 ? 'bg-teal-500' : 'bg-red-500') : 'bg-teal-500';
            @endphp
            <div class="flex flex-1 flex-col items-center gap-1">
                <div class="flex h-8 w-full items-end rounded-sm bg-slate-100">
                    <div class="w-full rounded-sm {{ $barColor }}" style="height: {{ $heightPct }}%"></div>
                </div>
                <span class="text-[9px] text-slate-400">{{ $days[$i]->format('M j') }}</span>
            </div>
        @endforeach
    </div>
</div>
