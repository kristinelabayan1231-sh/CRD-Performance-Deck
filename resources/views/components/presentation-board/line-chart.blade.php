@props([
    // list of ['key' => month field, 'color' => '#hex', 'name' => label]
    'series',
    // the month rows; a month with a null value leaves a gap
    'months',
    'readTarget',
    // callable: month row => read-out text
    'read',
    'height' => 220,
    // ['at' => month index, 'before' => label, 'after' => label], a line drawn between two kinds of data
    'split' => null,
])

@php
    $peak = collect($months)->flatMap(fn (array $month) => collect($series)->map(fn (array $line) => $month[$line['key']]))->filter()->max() ?? 0;
    $top = $peak <= .05 ? 5 : ($peak <= .2 ? 20 : max(20, (int) ceil($peak * 5) * 20));
    $count = max(1, count($months));
    $x = fn (int $index) => round(($index + .5) / $count * 100, 2);
    $y = fn (float $rate) => round($rate * 100 / $top * 100, 2);
    $lastMonth = $months[array_key_last($months)];
    $labelled = [];
    foreach (collect($series)->sortByDesc(fn (array $line) => $lastMonth[$line['key']] ?? -1) as $line) {
        $value = $lastMonth[$line['key']];
        if ($value !== null && collect($labelled)->every(fn (float $shown) => abs($shown - $value) * 100 / $top >= .09)) {
            $labelled[$line['key']] = $value;
        }
    }
    $muted = 'color: #bdb6cf';
@endphp

<div class="flex min-w-0 gap-2">
    <div aria-hidden="true" class="flex flex-col justify-between text-right" style="width: 36px; height: {{ $height }}px; font-size: 12px; {{ $muted }}">
        @foreach ([1, .75, .5, .25, 0] as $step)
            <span>{{ rtrim(rtrim(number_format($top * $step, 2), '0'), '.') }}%</span>
        @endforeach
    </div>
    <div class="min-w-0 flex-1">
        <div data-chart style="position: relative; height: {{ $height }}px; background-image: linear-gradient(rgb(255 255 255 / .1) 1px, transparent 1px); background-size: 100% 25%; border-bottom: 1px solid rgb(255 255 255 / .3)">
            @if ($split && $split['at'] > 0)
                @php
                    $splitLeft = round($split['at'] / $count * 100, 2);
                @endphp
                <span aria-hidden="true" style="position: absolute; top: 0; bottom: 0; left: {{ $splitLeft }}%; width: 1px; background: rgb(255 255 255 / .35)"></span>
                <span style="position: absolute; top: 2px; left: {{ $splitLeft }}%; transform: translateX(-100%); padding-right: 6px; font-size: 12px; white-space: nowrap; {{ $muted }}">{{ $split['before'] }}</span>
                <span style="position: absolute; top: 2px; left: {{ $splitLeft }}%; padding-left: 6px; font-size: 12px; white-space: nowrap; {{ $muted }}">{{ $split['after'] }}</span>
            @endif
            <svg role="img" aria-label="{{ collect($series)->pluck('name')->join(', ') }} by month; the numbers are in the table" viewBox="0 0 100 100" preserveAspectRatio="none" style="position: absolute; inset: 0; width: 100%; height: 100%">
                @foreach ($series as $line)
                    <polyline fill="none" stroke="{{ $line['color'] }}" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"
                              points="{{ collect($months)->map(fn (array $month, int $index) => $month[$line['key']] === null ? null : $x($index).','.(100 - $y($month[$line['key']])))->filter()->join(' ') }}" />
                @endforeach
            </svg>
            @foreach ($series as $line)
                @foreach ($months as $index => $month)
                    @if ($month[$line['key']] !== null)
                        <span aria-hidden="true" style="position: absolute; left: {{ $x($index) }}%; bottom: {{ $y($month[$line['key']]) }}%; width: 8px; height: 8px; margin: 0 0 -4px -4px; border-radius: 50%; background: {{ $line['color'] }}; box-shadow: 0 0 0 2px #2d2a3e"></span>
                        @if ($index === array_key_last($months) && isset($labelled[$line['key']]))
                            <span style="position: absolute; left: {{ $x($index) }}%; bottom: {{ $y($month[$line['key']]) }}%; transform: translate(-50%, -8px); font-size: 12px; font-weight: 700; white-space: nowrap">{{ number_format($month[$line['key']] * 100, 1) }}%</span>
                        @endif
                    @endif
                @endforeach
            @endforeach
            <div class="flex" style="position: absolute; inset: 0">
                @foreach ($months as $month)
                    <button type="button" data-point="{{ $month['key'] }}" data-read="{{ $read($month) }}" data-read-target="{{ $readTarget }}" aria-label="{{ $read($month) }}" title="{{ $read($month) }}" class="flex-1 cursor-default" style="background: transparent; border-radius: 6px"></button>
                @endforeach
            </div>
        </div>
        <div aria-hidden="true" class="flex pt-1" style="font-size: 12px; font-weight: 600; {{ $muted }}">
            @foreach ($months as $month)
                <span class="flex-1 text-center">{{ $month['label'] }}</span>
            @endforeach
        </div>
    </div>
</div>
