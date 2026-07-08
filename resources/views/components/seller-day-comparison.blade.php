@props(['series', 'days'])

@php
    // Distinct hue per day (not per seller) — day is the thing being compared.
    // Each metric (Sales Value / Qty / Product Cost) gets its own group of
    // day-bars, sitting side by side within the same seller row.
    $dayColors = ['#2a78d6', '#1baf7a', '#eda100'];

    $metrics = [
        ['key' => 'sales_value', 'label' => 'Sales Value', 'prefix' => '₱', 'decimals' => 0],
        ['key' => 'parcel_qty', 'label' => 'Qty', 'prefix' => '', 'decimals' => 0],
        ['key' => 'product_cost', 'label' => 'Product Cost', 'prefix' => '₱', 'decimals' => 0],
    ];

    $maxByMetric = [];
    foreach ($metrics as $metric) {
        $max = 0.0;
        foreach ($series as $s) {
            foreach ($s['points'] as $point) {
                $max = max($max, $point[$metric['key']]);
            }
        }
        $maxByMetric[$metric['key']] = $max > 0 ? $max : 1;
    }

    $sellerTotals = [];
    foreach ($series as $s) {
        $sellerTotals[$s['label']] = [
            'sales_value' => $s['total'],
            'parcel_qty' => array_sum(array_column($s['points'], 'parcel_qty')),
            'product_cost' => array_sum(array_column($s['points'], 'product_cost')),
        ];
    }
@endphp

<section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3">
        <h3 class="text-sm font-semibold text-slate-900">Seller Performance by Day</h3>
        <ul class="flex items-center gap-3">
            @foreach ($days as $i => $day)
                <li class="flex items-center gap-1.5 text-xs text-slate-500">
                    <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $dayColors[$i % count($dayColors)] }}"></span>
                    {{ $day->format('M j') }}
                </li>
            @endforeach
        </ul>
    </div>

    @if (empty($series))
        <p class="px-5 py-6 text-sm text-slate-400">No data in this date range.</p>
    @else
        <div data-chart-view class="divide-y divide-slate-100">
            @foreach ($series as $s)
                <div class="flex flex-nowrap items-center gap-6 px-5 py-4">
                    <div class="flex w-44 shrink-0 items-center gap-2">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-teal-100 text-[11px] font-semibold text-teal-700">
                            {{ strtoupper(substr(trim(str_replace('CRD', '', $s['label'])), 0, 1) ?: 'C') }}
                        </span>
                        <span class="truncate text-sm font-medium text-slate-700" title="{{ $s['label'] }}">{{ $s['label'] }}</span>
                    </div>

                    <div class="flex flex-1 items-start divide-x divide-slate-100">
                        @foreach ($metrics as $metric)
                            <div class="flex flex-1 flex-col items-center gap-1 px-4">
                                <div class="flex items-end justify-center gap-3">
                                    @foreach ($s['points'] as $i => $point)
                                        @php
                                            $value = $point[$metric['key']];
                                            $heightPct = max(4, round($value / $maxByMetric[$metric['key']] * 100));
                                        @endphp
                                        <div class="flex flex-col items-center gap-1">
                                            <span class="text-[10px] tabular-nums text-slate-500">{{ $metric['prefix'] }}{{ number_format($value, $metric['decimals']) }}</span>
                                            <div class="flex h-16 w-6 items-end rounded-sm bg-slate-100">
                                                <div class="w-full rounded-sm" style="height: {{ $heightPct }}%; background-color: {{ $dayColors[$i % count($dayColors)] }}"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <p class="mt-1 text-xs font-semibold text-slate-700">{{ $metric['label'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="w-32 shrink-0 text-right">
                        <p class="text-[10px] uppercase tracking-wide text-slate-400">Total</p>
                        <p class="text-sm font-semibold text-slate-900">₱{{ number_format($sellerTotals[$s['label']]['sales_value'], 0) }}</p>
                        <p class="mt-0.5 text-[10px] tabular-nums text-slate-500">{{ number_format($sellerTotals[$s['label']]['parcel_qty']) }} pcs &middot; ₱{{ number_format($sellerTotals[$s['label']]['product_cost'], 0) }} cost</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div data-table-view class="hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-2 font-medium" rowspan="2">Seller</th>
                        @foreach ($days as $day)
                            <th class="px-5 py-2 text-center font-medium" colspan="3">{{ $day->format('M j') }}</th>
                        @endforeach
                        <th class="px-5 py-2 font-medium text-right" rowspan="2">Total Sales</th>
                        <th class="px-5 py-2 font-medium text-right" rowspan="2">Total Qty.</th>
                        <th class="px-5 py-2 font-medium text-right" rowspan="2">Total Cost</th>
                    </tr>
                    <tr class="border-b border-slate-200 text-left text-[10px] uppercase tracking-wide text-slate-400">
                        @foreach ($days as $day)
                            <th class="px-2 py-1 text-right font-medium">Sales</th>
                            <th class="px-2 py-1 text-right font-medium">Qty.</th>
                            <th class="px-2 py-1 text-right font-medium">Cost</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($series as $s)
                        <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <td class="px-5 py-2 text-slate-700">{{ $s['label'] }}</td>
                            @foreach ($s['points'] as $point)
                                <td class="px-2 py-2 text-right tabular-nums text-slate-700">₱{{ number_format($point['sales_value'], 2) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-slate-700">{{ number_format($point['parcel_qty']) }}</td>
                                <td class="px-2 py-2 text-right tabular-nums text-slate-700">₱{{ number_format($point['product_cost'], 2) }}</td>
                            @endforeach
                            <td class="px-5 py-2 text-right tabular-nums font-medium text-slate-900">₱{{ number_format($sellerTotals[$s['label']]['sales_value'], 2) }}</td>
                            <td class="px-5 py-2 text-right tabular-nums font-medium text-slate-900">{{ number_format($sellerTotals[$s['label']]['parcel_qty']) }}</td>
                            <td class="px-5 py-2 text-right tabular-nums font-medium text-slate-900">₱{{ number_format($sellerTotals[$s['label']]['product_cost'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>