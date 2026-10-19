@props([
    'title',
    // list of ['label' => …, 'color' => '#hex', 'values' => [Y-m-d => ?float]]; every series has the same days
    'series',
    'type' => 'line',
    'format',
    // optional ['label' => …, 'values' => [Y-m-d => float]], drawn as a dashed line
    'target' => null,
    'legend' => [],
])

@php
    // One day = 10 units wide, the plot 100 high; the SVG is stretched to its box (strokes keep their width).
    $days = array_keys($series[0]['values']);
    $count = max(1, count($days));
    $width = $count * 10;
    $height = 100;

    $stacks = collect($days)->map(fn ($day) => $type === 'bars'
        ? collect($series)->sum(fn ($line) => $line['values'][$day] ?? 0)
        : collect($series)->max(fn ($line) => $line['values'][$day] ?? 0));
    $peak = max($stacks->max() ?? 0, $target ? max($target['values']) : 0);

    // A round top for the scale (1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6 or 8 × a power of ten), just above the peak.
    $top = 1;
    if ($peak > 0) {
        $power = 10 ** floor(log10($peak));
        $top = collect([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10])->map(fn ($step) => $step * $power)->first(fn ($step) => $step >= $peak * 1.05) ?? 10 * $power;
    }
    $y = fn (float $value) => round($height - $value / $top * $height, 2);

    // A line through the days with a value; a day without one breaks it. A single day is drawn across its slot.
    $path = function (array $values) use ($days, $y, $count) {
        $d = '';
        $open = false;
        foreach ($days as $i => $day) {
            $value = $values[$day] ?? null;
            if ($value === null) {
                $open = false;

                continue;
            }
            $d .= ($open ? ' L' : ' M').($count === 1 ? '1 '.$y($value).' L9' : ($i * 10 + 5)).' '.$y($value);
            $open = true;
        }

        return trim($d);
    };
    $dayLabel = fn (string $day, string $format = 'M j') => \Carbon\CarbonImmutable::parse($day)->format($format);
@endphp

<div class="flex h-full min-h-0 flex-col gap-1.5">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
        <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">{{ $title }}</p>
        @if ($legend)
            <ul class="flex flex-wrap gap-x-2.5 text-[11px] text-ink tabular-nums">
                @foreach ($legend as [$name, $color, $value, $dashed])
                    <li class="flex items-center gap-1">
                        <span aria-hidden="true" @class(['inline-block w-2.5', 'h-0 border-t-2 border-dashed' => $dashed, 'h-2.5 rounded-sm' => ! $dashed]) style="{{ $dashed ? 'border-color' : 'background' }}: {{ $color }}"></span>
                        {{ $name }} <span class="font-semibold">{{ $value }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="relative min-h-6 flex-1">
        <span class="absolute -top-1 left-0 z-10 rounded bg-white/80 px-0.5 text-[10px] leading-none text-muted tabular-nums">{{ $format($top) }}</span>
        <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" class="absolute inset-0 size-full overflow-visible" role="img" aria-label="{{ $title }}">
            <line x1="0" x2="{{ $width }}" y1="0" y2="0" stroke="#ebe6ee" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" />
            <line x1="0" x2="{{ $width }}" y1="{{ $height / 2 }}" y2="{{ $height / 2 }}" stroke="#ebe6ee" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" />

            @if ($type === 'bars')
                {{-- Stacked, first series at the bottom; the white outline keeps a gap between segments --}}
                @foreach ($days as $i => $day)
                    @php($base = 0)
                    @foreach ($series as $line)
                        @php($value = (float) ($line['values'][$day] ?? 0))
                        @if ($value > 0)
                            <rect x="{{ $i * 10 + 1.5 }}" width="7" y="{{ $y($base + $value) }}" height="{{ round($value / $top * $height, 2) }}" fill="{{ $line['color'] }}" stroke="#fff" stroke-width="1" vector-effect="non-scaling-stroke" />
                        @endif
                        @php($base += $value)
                    @endforeach
                @endforeach
            @else
                @foreach ($series as $line)
                    <path d="{{ $path($line['values']) }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                @endforeach
            @endif

            @if ($target)
                <path d="{{ $path($target['values']) }}" fill="none" stroke="#4f5563" stroke-width="1.5" stroke-dasharray="4 3" vector-effect="non-scaling-stroke" />
            @endif

            <line x1="0" x2="{{ $width }}" y1="{{ $height }}" y2="{{ $height }}" stroke="#c9c2cf" vector-effect="non-scaling-stroke" />

            {{-- One hover column per day: its values in the tooltip --}}
            @foreach ($days as $i => $day)
                <rect x="{{ $i * 10 }}" width="10" y="0" height="{{ $height }}" class="fill-transparent hover:fill-ink/5">
                    <title>{{ $dayLabel($day, 'D, M j') }}@foreach ($series as $line){{ "\n".$line['label'].': '.(($line['values'][$day] ?? null) === null ? '—' : $format($line['values'][$day])) }}@endforeach{{ $target ? "\n".$target['label'].': '.$format($target['values'][$day]) : '' }}</title>
                </rect>
            @endforeach
        </svg>
    </div>

    <div class="flex justify-between text-[10px] text-muted tabular-nums">
        <span>{{ $dayLabel($days[0]) }}</span>
        @if ($count > 2)
            <span>{{ $dayLabel($days[intdiv($count - 1, 2)]) }}</span>
        @endif
        @if ($count > 1)
            <span>{{ $dayLabel($days[$count - 1]) }}</span>
        @endif
    </div>
</div>
