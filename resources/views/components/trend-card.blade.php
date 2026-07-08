@props(['title', 'series', 'days', 'icon' => null])

<section class="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
        @if ($icon)
            <span class="flex h-6 w-6 items-center justify-center rounded-md bg-white text-slate-400 shadow-sm ring-1 ring-slate-200">
                {{ $icon }}
            </span>
        @endif
        <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
    </div>

    @if (empty($series))
        <p class="px-5 py-6 text-sm text-slate-400">No data in this date range.</p>
    @else
        <div data-chart-view>
            <x-line-chart :series="$series" :days="$days" />
        </div>

        <div data-table-view class="hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-2 font-medium">{{ $title }}</th>
                        @foreach ($days as $day)
                            <th class="px-5 py-2 font-medium text-right">{{ $day->format('M j') }}</th>
                        @endforeach
                        <th class="px-5 py-2 font-medium text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($series as $line)
                        <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <td class="px-5 py-2 text-slate-700">{{ $line['label'] }}</td>
                            @foreach ($line['points'] as $point)
                                <td class="px-5 py-2 text-right tabular-nums text-slate-700">₱{{ number_format($point['sales_value'], 2) }}</td>
                            @endforeach
                            <td class="px-5 py-2 text-right tabular-nums font-medium text-slate-900">₱{{ number_format($line['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 font-semibold">
                        <td class="px-5 py-2">Total</td>
                        @php $dayCount = count($days); @endphp
                        @for ($i = 0; $i < $dayCount; $i++)
                            <td class="px-5 py-2 text-right tabular-nums">₱{{ number_format(array_sum(array_map(fn ($l) => $l['points'][$i]['sales_value'], $series)), 2) }}</td>
                        @endfor
                        <td class="px-5 py-2 text-right tabular-nums">₱{{ number_format(array_sum(array_column($series, 'total')), 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</section>
