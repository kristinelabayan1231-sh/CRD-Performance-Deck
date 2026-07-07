@props(['title', 'items'])

@php
    $total = array_sum(array_column($items, 'sales_value'));
    $max = ! empty($items) ? max(array_column($items, 'sales_value')) : 0;
@endphp

<section class="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 bg-slate-50 px-5 py-3">
        <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
    </div>

    @if (empty($items))
        <p class="px-5 py-6 text-sm text-slate-400">No data in this date range.</p>
    @else
        <div data-chart-view class="space-y-3 px-5 py-4">
            @foreach ($items as $item)
                @php
                    $pct = $total > 0 ? $item['sales_value'] / $total * 100 : 0;
                    $barWidth = $max > 0 ? max(2, round($item['sales_value'] / $max * 100)) : 0;
                @endphp
                <div class="flex items-center gap-3">
                    <span class="w-28 shrink-0 truncate text-xs text-slate-600" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                    <div class="h-5 min-w-0 flex-1 rounded-r bg-slate-100">
                        <div class="h-5 rounded-r bg-teal-500" style="width: {{ $barWidth }}%"></div>
                    </div>
                    <span class="w-32 shrink-0 text-right text-xs tabular-nums text-slate-600">
                        ₱{{ number_format($item['sales_value'], 0) }} &middot; {{ number_format($pct, 1) }}%
                    </span>
                </div>
            @endforeach
        </div>

        <div data-table-view class="hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-2 font-medium">{{ $title }}</th>
                        <th class="px-5 py-2 font-medium text-right">Sales Value</th>
                        <th class="px-5 py-2 font-medium text-right">Parcel Qty.</th>
                        <th class="px-5 py-2 font-medium text-right">Product Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <td class="px-5 py-2 text-slate-700">{{ $item['label'] }}</td>
                            <td class="px-5 py-2 text-right tabular-nums text-slate-700">₱{{ number_format($item['sales_value'], 2) }}</td>
                            <td class="px-5 py-2 text-right tabular-nums text-slate-700">{{ number_format($item['parcel_qty']) }}</td>
                            <td class="px-5 py-2 text-right tabular-nums text-slate-700">₱{{ number_format($item['product_cost'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 font-semibold">
                        <td class="px-5 py-2">Total</td>
                        <td class="px-5 py-2 text-right tabular-nums">₱{{ number_format($total, 2) }}</td>
                        <td class="px-5 py-2 text-right tabular-nums">{{ number_format(array_sum(array_column($items, 'parcel_qty'))) }}</td>
                        <td class="px-5 py-2 text-right tabular-nums">₱{{ number_format(array_sum(array_column($items, 'product_cost')), 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</section>
