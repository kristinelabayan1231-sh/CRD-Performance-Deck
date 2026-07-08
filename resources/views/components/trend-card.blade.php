@props(['title', 'series', 'days', 'icon' => null, 'hideOther' => false])

@php
    // Known category display order — only affects cards whose labels match
    // (e.g. "By Region"). Cards with unrelated labels (products, statuses)
    // are untouched since none of their labels appear in this map.
    $categoryOrder = [
        'Luzon' => 0,
        'Metro Manila' => 1,
        'Mindanao' => 2,
        'Visayas' => 3,
    ];

    $maxSeries = 5;
    $lines = $series;

    if (! $hideOther && count($series) > $maxSeries + 1) {
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
    } elseif ($hideOther) {
        // Just cap at the top N, dropping the remainder entirely — no
        // "Other" bucket at all.
        $lines = array_slice($series, 0, $maxSeries);
    }

    // Apply the fixed category order where labels match a known category;
    // everything else keeps its existing (value-sorted) relative order.
    usort($lines, function ($a, $b) use ($categoryOrder) {
        $aKnown = array_key_exists($a['label'], $categoryOrder);
        $bKnown = array_key_exists($b['label'], $categoryOrder);

        if ($aKnown && $bKnown) {
            return $categoryOrder[$a['label']] <=> $categoryOrder[$b['label']];
        }
        if ($aKnown) {
            return -1;
        }
        if ($bKnown) {
            return 1;
        }

        return 0;
    });
@endphp

<section class="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
        @if ($icon)
            <span class="flex h-6 w-6 items-center justify-center rounded-md bg-white text-slate-400 shadow-sm ring-1 ring-slate-200">
                {{ $icon }}
            </span>
        @endif
        <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
        <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-500">Sales Value</span>
    </div>
    @if (empty($lines))
        <p class="px-5 py-6 text-sm text-slate-400">No data in this date range.</p>
    @else
        <div data-chart-view>
            <x-line-chart :series="$lines" :days="$days" :title="$title" />
        </div>
        <div data-table-view class="hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-4 py-2 font-medium">{{ $title }}</th>
                        @foreach ($days as $day)
                            <th class="px-4 py-2 font-medium text-right">{{ $day->format('M j') }}</th>
                        @endforeach
                        <th class="px-4 py-2 font-medium text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        @php
                            $lineQty = array_sum(array_column($line['points'], 'parcel_qty'));
                            $lineCost = array_sum(array_column($line['points'], 'product_cost'));
                        @endphp
                        <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <td class="px-4 py-2 align-top text-slate-700">{{ $line['label'] }}</td>
                            @foreach ($line['points'] as $point)
                                <td class="px-4 py-2 text-right align-top leading-tight">
                                    <p class="tabular-nums text-slate-700">₱{{ number_format($point['sales_value'], 0) }}</p>
                                    <p class="text-[11px] tabular-nums text-slate-400">{{ number_format($point['parcel_qty']) }} pcs &middot; ₱{{ number_format($point['product_cost'], 0) }}</p>
                                </td>
                            @endforeach
                            <td class="px-4 py-2 text-right align-top leading-tight">
                                <p class="font-medium tabular-nums text-slate-900">₱{{ number_format($line['total'], 0) }}</p>
                                <p class="text-[11px] tabular-nums text-slate-400">{{ number_format($lineQty) }} pcs &middot; ₱{{ number_format($lineCost, 0) }}</p>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 font-semibold">
                        <td class="px-4 py-2">Total</td>
                        @php $dayCount = count($days); @endphp
                        @for ($i = 0; $i < $dayCount; $i++)
                            @php
                                $daySales = array_sum(array_map(fn ($l) => $l['points'][$i]['sales_value'], $lines));
                                $dayQty = array_sum(array_map(fn ($l) => $l['points'][$i]['parcel_qty'], $lines));
                                $dayCost = array_sum(array_map(fn ($l) => $l['points'][$i]['product_cost'], $lines));
                            @endphp
                            <td class="px-4 py-2 text-right align-top leading-tight">
                                <p class="tabular-nums">₱{{ number_format($daySales, 0) }}</p>
                                <p class="text-[11px] font-normal tabular-nums text-slate-400">{{ number_format($dayQty) }} pcs &middot; ₱{{ number_format($dayCost, 0) }}</p>
                            </td>
                        @endfor
                        @php
                            $grandQty = array_sum(array_map(fn ($l) => array_sum(array_column($l['points'], 'parcel_qty')), $lines));
                            $grandCost = array_sum(array_map(fn ($l) => array_sum(array_column($l['points'], 'product_cost')), $lines));
                        @endphp
                        <td class="px-4 py-2 text-right align-top leading-tight">
                            <p class="tabular-nums">₱{{ number_format(array_sum(array_column($lines, 'total')), 0) }}</p>
                            <p class="text-[11px] font-normal tabular-nums text-slate-400">{{ number_format($grandQty) }} pcs &middot; ₱{{ number_format($grandCost, 0) }}</p>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</section>