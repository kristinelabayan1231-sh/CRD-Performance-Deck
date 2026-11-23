{{-- Side by side by month for Top products and CRA performance: a row per item ($gridRows), a column per month
     ($gridColumns, the month before the range first when there is one) and Overall for the months picked. Each month's
     chip compares it with the column before; a running month's sales at its full-month pace. Uses the board's helpers. --}}
@php
    $gridCount = count($gridColumns) + 1;
    $gridStyle = 'display: grid; grid-template-columns: minmax(170px, 1.3fr) repeat('.$gridCount.', minmax(112px, 1fr)); column-gap: 6px; min-width: '.(190 + $gridCount * 118).'px';
    $gridOverall = array_sum(array_column($gridRows, 'overall'));
@endphp
<div class="overflow-x-auto">
    <div style="{{ $gridStyle }}">
        <span style="position: sticky; left: 0; z-index: 1; padding: 8px 4px; background: var(--pb-solid); font-size: 12px; font-weight: 600; {{ $muted }}">{{ $gridFirst }}</span>
        @foreach ($gridColumns as $column)
            <div class="flex flex-col items-center justify-center text-center" style="padding: 7px 4px; border-radius: 10px 10px 0 0; background: {{ $column['open'] ? 'var(--pb-btn)' : ($column['before'] ? 'var(--pb-chip)' : 'var(--pb-on)') }}; color: {{ $column['open'] ? 'var(--pb-btn-text)' : 'var(--pb-text)' }}">
                <strong style="font-size: 13px">{{ $column['label'] }}</strong>
                <span style="font-size: 10px; opacity: .85">{{ $column['note'] }}</span>
            </div>
        @endforeach
        <div class="flex flex-col items-center justify-center text-center" style="padding: 7px 4px; border-radius: 10px 10px 0 0; background: var(--pb-sales-bg); color: var(--pb-sales)">
            <strong style="font-size: 13px">Overall</strong>
            <span style="font-size: 10px">the months picked</span>
        </div>

        @foreach ($gridRows as $gridRow)
            <div class="flex min-w-0 items-center gap-2" style="position: sticky; left: 0; z-index: 1; padding: 8px 4px; background: var(--pb-solid); {{ $rowLine }}">
                <span class="flex items-center justify-center" style="width: 22px; height: 22px; flex: none; border-radius: 50%; font-size: 11px; font-weight: 700; {{ $medal($gridRow['rank']) }}">{{ $gridRow['rank'] }}</span>
                <span style="min-width: 0; font-size: 13px; font-weight: 600; overflow-wrap: anywhere">{{ $gridRow['name'] }}</span>
            </div>
            @foreach ($gridColumns as $index => $column)
                @php
                    $gridValue = $gridRow['values'][$column['key']] ?? ['gross' => 0.0, 'orders' => 0];
                    $gridCompared = $column['open'] ? $paced($gridValue['gross']) : $gridValue['gross'];
                    $gridBefore = $index > 0 ? ($gridRow['values'][$gridColumns[$index - 1]['key']]['gross'] ?? 0.0) : null;
                    $gridChip = $gridBefore && $gridValue['gross'] > 0 ? $chip($gridCompared, $gridBefore) : null;
                    $gridBest = ! $column['before'] && $gridValue['gross'] > 0 && $gridRow['best'] !== null && (float) $gridCompared === (float) $gridRow['best'];
                @endphp
                <div class="flex flex-col items-center justify-center gap-0.5 text-center" style="padding: 6px 4px; {{ $rowLine }}; background: {{ $gridBest ? 'var(--pb-best)' : ($column['before'] ? 'var(--pb-cell)' : 'transparent') }}">
                    @if ($gridValue['gross'] > 0)
                        <strong style="font-size: 14px" title="{{ $peso($gridValue['gross']) }}">{{ $pesoShort($gridValue['gross']) }}</strong>
                        <span style="font-size: 11px; {{ $muted }}">{{ $number($gridValue['orders']) }} {{ Str::plural('order', $gridValue['orders']) }}</span>
                        <span class="flex flex-wrap justify-center gap-1">
                            @if ($gridChip)
                                <span style="{{ $gridChip['style'] }}; font-size: 10px; padding: 1px 6px">{{ $gridChip['text'] }}</span>
                            @endif
                            @if ($gridBest)
                                <span style="padding: 1px 6px; border-radius: 999px; font-size: 10px; font-weight: 700; background: var(--pb-gold); color: var(--pb-gold-text)">★ Best</span>
                            @endif
                        </span>
                    @else
                        <span style="font-size: 12px; {{ $muted }}">No sales</span>
                    @endif
                </div>
            @endforeach
            <div class="flex flex-col items-center justify-center gap-0.5 text-center" style="padding: 6px 4px; {{ $rowLine }}; background: color-mix(in srgb, var(--pb-sales) 7%, transparent)">
                <strong style="font-size: 14px" title="{{ $peso($gridRow['overall']) }}">{{ $pesoShort($gridRow['overall']) }}</strong>
                <span style="font-size: 11px; {{ $muted }}">{{ $number($gridRow['overallOrders']) }} {{ Str::plural('order', $gridRow['overallOrders']) }}</span>
                <span style="font-size: 10px; font-weight: 700; color: var(--pb-soft)">{{ $gridOverall > 0 ? number_format($gridRow['overall'] / $gridOverall * 100, 1).'% of these' : '' }}</span>
            </div>
        @endforeach
    </div>
</div>
