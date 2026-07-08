@props(['series', 'days'])

@php
    // Fixed-order categorical palette (validated: node scripts/validate_palette.js).
    $palette = ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#e34948'];
    $otherColor = '#94a3b8';

    $maxSeries = 5;
    $lines = $series;

    if (count($series) > $maxSeries + 1) {
        $head = array_slice($series, 0, $maxSeries);
        $tail = array_slice($series, $maxSeries);

        $otherPoints = [];
        foreach ($days as $i => $day) {
            $otherPoints[] = [
                'date' => $day->toDateString(),
                'sales_value' => array_sum(array_map(fn ($s) => $s['points'][$i]['sales_value'], $tail)),
                'parcel_qty' => array_sum(array_map(fn ($s) => $s['points'][$i]['parcel_qty'], $tail)),
                'product_cost' => array_sum(array_map(fn ($s) => $s['points'][$i]['product_cost'], $tail)),
            ];
        }

        $lines = array_merge($head, [[
            'label' => 'Other',
            'total' => array_sum(array_column($tail, 'total')),
            'points' => $otherPoints,
            'is_other' => true,
        ]]);
    }

    $plotLeft = 50;
    $plotRight = 580;
    $plotTop = 14;
    $plotBottom = 170;

    $dayCount = count($days);
    $xStep = $dayCount > 1 ? ($plotRight - $plotLeft) / ($dayCount - 1) : 0;

    $maxValue = 0.0;
    foreach ($lines as $line) {
        foreach ($line['points'] as $point) {
            $maxValue = max($maxValue, $point['sales_value']);
        }
    }
    $maxValue = $maxValue > 0 ? $maxValue * 1.15 : 1;

    $yFor = fn ($value) => $plotBottom - ($value / $maxValue) * ($plotBottom - $plotTop);
    $xFor = fn ($i) => $plotLeft + $i * $xStep;

    $gridSteps = 4;
@endphp

<div class="px-5 py-4">
    <svg viewBox="0 0 600 210" class="w-full">
        {{-- Gridlines --}}
        @for ($g = 0; $g <= $gridSteps; $g++)
            @php
                $gridValue = $maxValue * $g / $gridSteps;
                $gridY = $yFor($gridValue);
            @endphp
            <line x1="{{ $plotLeft }}" y1="{{ $gridY }}" x2="{{ $plotRight }}" y2="{{ $gridY }}" stroke="#e1e0d9" stroke-width="1"></line>
            <text x="{{ $plotLeft - 8 }}" y="{{ $gridY + 3 }}" text-anchor="end" font-size="9" fill="#898781">₱{{ number_format($gridValue, 0) }}</text>
        @endfor

        {{-- X-axis day labels --}}
        @foreach ($days as $i => $day)
            <text x="{{ $xFor($i) }}" y="{{ $plotBottom + 18 }}" text-anchor="middle" font-size="10" fill="#52514e">{{ $day->format('M j') }}</text>
        @endforeach

        {{-- Lines + points --}}
        @foreach ($lines as $li => $line)
            @php
                $color = $line['is_other'] ?? false ? $otherColor : $palette[$li % count($palette)];
                $pointsAttr = collect($line['points'])->map(fn ($p, $i) => round($xFor($i), 2).','.round($yFor($p['sales_value']), 2))->implode(' ');
            @endphp
            <polyline points="{{ $pointsAttr }}" fill="none" stroke="{{ $color }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>
            @foreach ($line['points'] as $i => $point)
                <circle cx="{{ $xFor($i) }}" cy="{{ $yFor($point['sales_value']) }}" r="4" fill="{{ $color }}" stroke="#fcfcfb" stroke-width="2"></circle>
            @endforeach
        @endforeach
    </svg>

    <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
        @foreach ($lines as $li => $line)
            @php
                $color = $line['is_other'] ?? false ? $otherColor : $palette[$li % count($palette)];
            @endphp
            <li class="flex items-center gap-1.5 text-xs">
                <span class="h-0.5 w-4 shrink-0 rounded-full" style="background-color: {{ $color }}"></span>
                <span class="text-slate-600">{{ $line['label'] }}</span>
                <span class="tabular-nums text-slate-400">₱{{ number_format($line['total'], 0) }}</span>
            </li>
        @endforeach
    </ul>
</div>
