{{-- One CRA card's comparison: month $cur against month $base (null: nothing to compare), from $craFigures (one per board month). Uses the board's helpers. --}}
@php
    $now = $craFigures[$cur];
    $was = $base === null ? null : $craFigures[$base];
    $isOpenMonth = $isRunning && $cur === $monthCount - 1;
    // Counts that build up day by day are compared at the running month's full-month pace, as the board's other chips.
    $flowChip = fn (string $key) => $was && $now[$key] && $was[$key] ? $chip($isOpenMonth ? $paced($now[$key]) : $now[$key], $was[$key]) : null;
    $plainChip = fn (string $key) => $was && $now[$key] !== null && $was[$key] ? $chip($now[$key], $was[$key]) : null;
    $signedPeso = fn (?float $value) => $value === null ? '—' : ($value < 0 ? '−' : '').$peso(abs($value));
    // NI can be negative, so its change is in pesos, not %.
    $niChange = $was && $now['ni'] !== null && $was['ni'] !== null ? $now['ni'] - $was['ni'] : null;
    $chipBase = 'display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; ';
    $tag = fn (string $text) => ['text' => $text, 'style' => $chipBase.'background: var(--pb-chip); color: var(--pb-muted)'];
    $compareRows = [
        ['Gross', $isOpenMonth ? 'paced in the change' : '', $now['gross'] ? $pesoShort($now['gross']) : '—', $was && $was['gross'] ? $pesoShort($was['gross']) : '—', $flowChip('gross')],
        ['Orders', '', $now['orders'] ? $number($now['orders']) : '—', $was && $was['orders'] ? $number($was['orders']) : '—', $flowChip('orders')],
        ['AOV', '', $peso($now['aov']), $was ? $peso($was['aov']) : '—', $plainChip('aov')],
        ['NI', 'from Nov', $signedPeso($now['ni']), $was ? $signedPeso($was['ni']) : '—', $niChange === null
            ? $tag($now['ni'] === null ? 'not yet' : 'first month')
            : ['text' => ($niChange > 0 ? '▲ +' : ($niChange < 0 ? '▼ −' : '')).$peso(abs($niChange)), 'style' => $chipBase.($niChange > 0 ? 'background: var(--pb-good-bg); color: var(--pb-good)' : ($niChange < 0 ? 'background: var(--pb-bad-bg); color: var(--pb-bad)' : 'background: var(--pb-chip); color: var(--pb-soft)'))]],
        ['Capacity a day', '', $now['capacity'] === null ? '—' : number_format($now['capacity']), $was && $was['capacity'] !== null ? number_format($was['capacity']) : '—', $plainChip('capacity')],
        ['High AOV', 'assigned now', $number($craRow['high_aov']), '', $tag('now')],
        ['VIP', 'assigned now', $number($craRow['vip']), '', $tag('now')],
    ];
@endphp
<div class="flex flex-col gap-1.5">
    <div class="flex flex-wrap items-baseline justify-between gap-1.5">
        <h4 style="margin: 0; font-size: 12px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; color: var(--pb-muted)">{{ $monthName($months[$cur]) }} vs {{ $base === null ? '—' : $months[$base]['label'] }}</h4>
        <span style="font-size: 11px; color: var(--pb-muted)">{{ $hint }}</span>
    </div>
    <table style="width: 100%; border-collapse: collapse; font-size: 13px">
        <thead>
            <tr style="font-size: 11px; text-align: right; color: var(--pb-muted)">
                <th scope="col" style="text-align: left; font-weight: 600; padding: 4px 0">Figure</th>
                <th scope="col" style="font-weight: 700; padding: 4px 6px; color: var(--pb-text); white-space: nowrap">{{ $monthName($months[$cur]) }}</th>
                <th scope="col" style="font-weight: 600; padding: 4px 6px">{{ $base === null ? '—' : $months[$base]['label'] }}</th>
                <th scope="col" style="font-weight: 600; padding: 4px 0 4px 6px">Change</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($compareRows as [$label, $about, $nowText, $wasText, $change])
                <tr style="text-align: right; border-top: 1px solid var(--pb-row)">
                    <th scope="row" style="text-align: left; font-weight: 600; padding: 6px 0; white-space: nowrap">{{ $label }}@if ($about)<span style="display: block; font-size: 10px; font-weight: 500; color: var(--pb-muted)">{{ $about }}</span>@endif</th>
                    <td style="padding: 6px; font-weight: 700; white-space: nowrap; {{ $label === 'NI' && $now['ni'] !== null ? 'color: '.($now['ni'] < 0 ? $lost : $added) : '' }}">{{ $nowText }}</td>
                    <td style="padding: 6px; white-space: nowrap; color: var(--pb-soft)">{{ $wasText }}</td>
                    <td style="padding: 6px 0 6px 6px">@if ($change)<span style="{{ $change['style'] }}">{{ $change['text'] }}</span>@else<span style="font-size: 12px; color: var(--pb-muted)">—</span>@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
