@props(['series', 'days'])

@php
    // Distinct hue per day (not per seller) — day is the thing being compared.
    $dayColors = ['#2a78d6', '#1baf7a', '#eda100'];

    $globalMax = 0.0;
    foreach ($series as $s) {
        foreach ($s['points'] as $point) {
            $globalMax = max($globalMax, $point['sales_value']);
        }
    }
    $globalMax = $globalMax > 0 ? $globalMax : 1;
@endphp

<section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3">
        <h3 class="text-sm font-semibold text-slate-900">Sales Value by Day</h3>
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
                <div class="flex flex-wrap items-center gap-4 px-5 py-3">
                    <div class="flex w-44 shrink-0 items-center gap-2">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-teal-100 text-[11px] font-semibold text-teal-700">
                            {{ strtoupper(substr(trim(str_replace('CRD', '', $s['label'])), 0, 1) ?: 'C') }}
                        </span>
                        <span class="truncate text-sm font-medium text-slate-700" title="{{ $s['label'] }}">{{ $s['label'] }}</span>
                    </div>

                    <div class="flex flex-1 items-end gap-3">
                        @foreach ($s['points'] as $i => $point)
                            @php $heightPct = max(4, round($point['sales_value'] / $globalMax * 100)); @endphp
                            <div class="flex flex-col items-center gap-1">
                                <span class="text-[10px] tabular-nums text-slate-500">₱{{ number_format($point['sales_value'], 0) }}</span>
                                <div class="flex h-16 w-6 items-end rounded-sm bg-slate-100">
                                    <div class="w-full rounded-sm" style="height: {{ $heightPct }}%; background-color: {{ $dayColors[$i % count($dayColors)] }}"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="w-28 shrink-0 text-right">
                        <p class="text-[10px] uppercase tracking-wide text-slate-400">Total</p>
                        <p class="text-sm font-semibold text-slate-900">₱{{ number_format($s['total'], 0) }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div data-table-view class="hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-2 font-medium">Seller</th>
                        @foreach ($days as $day)
                            <th class="px-5 py-2 font-medium text-right">{{ $day->format('M j') }}</th>
                        @endforeach
                        <th class="px-5 py-2 font-medium text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($series as $s)
                        <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <td class="px-5 py-2 text-slate-700">{{ $s['label'] }}</td>
                            @foreach ($s['points'] as $point)
                                <td class="px-5 py-2 text-right tabular-nums text-slate-700">₱{{ number_format($point['sales_value'], 2) }}</td>
                            @endforeach
                            <td class="px-5 py-2 text-right tabular-nums font-medium text-slate-900">₱{{ number_format($s['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
