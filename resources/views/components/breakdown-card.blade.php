@props(['title', 'items', 'icon' => null])

@php
    $total = array_sum(array_column($items, 'sales_value'));
@endphp

<section class="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
        @if ($icon)
            <span class="flex h-6 w-6 items-center justify-center rounded-md bg-white text-slate-400 shadow-sm ring-1 ring-slate-200">
                {{ $icon }}
            </span>
        @endif
        <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
    </div>

    @if (empty($items))
        <p class="px-5 py-6 text-sm text-slate-400">No data in this date range.</p>
    @else
        <div data-chart-view>
            <x-donut-chart :items="$items" :total="$total" />
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
