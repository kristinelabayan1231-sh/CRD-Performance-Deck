{{-- One CRA's orders behind their gross sales (Conversion Breakdown pop-up). --}}
@php
    $peso = fn (float $value) => '₱'.number_format($value, 2);
    $tags = [
        \App\Models\PancakeOrder::BROADCAST => ['CRD - BROADCAST', 'bg-teal/15 text-teal-700'],
        \App\Models\PancakeOrder::SEGMENTATION => ['CRD - SEGMENTATION', 'bg-brand-100 text-brand-700'],
    ];
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-bold">{{ $cra->displayName() }}</h2>
            <p class="text-sm text-muted">{{ $period }} · {{ number_format($orders->count()) }} {{ Str::plural('order', $orders->count()) }}</p>
        </div>
        {{-- Same totals as the Gross BC, Gross SC and Gross sales columns --}}
        <dl class="flex gap-5 text-right">
            <div>
                <dt class="text-[11px] font-semibold tracking-wide text-teal-700 uppercase">Gross BC</dt>
                <dd class="font-bold tabular-nums">{{ $peso($bcGross) }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-wide text-brand-700 uppercase">Gross SC</dt>
                <dd class="font-bold tabular-nums">{{ $peso($scGross) }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-wide text-muted uppercase">Gross sales</dt>
                <dd class="text-xl font-bold tabular-nums">{{ $peso($bcGross + $scGross) }}</dd>
            </div>
        </dl>
    </div>

    @if (! $cra->pancake_name)
        <p class="rounded-lg border border-dashed border-line px-3 py-6 text-center text-sm text-muted">No Pancake account set for {{ $cra->displayName() }}, so no orders can be matched.</p>
    @elseif ($orders->isEmpty())
        <p class="rounded-lg border border-dashed border-line px-3 py-6 text-center text-sm text-muted">No orders tagged CRD - BROADCAST or CRD - SEGMENTATION in these dates.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-line">
            <table class="w-full min-w-[680px] text-left text-sm">
                <thead class="bg-[#f7f4f8] text-xs tracking-wide text-muted uppercase">
                    <tr>
                        <th class="px-3 py-2 font-semibold">Date</th>
                        <th class="px-3 py-2 font-semibold">Order ID</th>
                        <th class="px-3 py-2 font-semibold">Customer name</th>
                        <th class="px-3 py-2 font-semibold">Page name</th>
                        <th class="px-3 py-2 font-semibold">Tagging</th>
                        <th class="px-3 py-2 text-right font-semibold">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($orders as $order)
                        <tr>
                            <td class="px-3 py-2 whitespace-nowrap tabular-nums">{{ $order->ordered_on->format('M j') }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ $order->pancake_order_id }}</td>
                            <td class="px-3 py-2">{{ $order->customer_name ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $order->page_name ?: '—' }}</td>
                            <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $tags[$order->conversion_type][1] }}">{{ $tags[$order->conversion_type][0] }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $peso($order->sales()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-ink font-semibold text-white">
                    <tr>
                        <td class="px-3 py-2" colspan="5">Total · {{ number_format($orders->count()) }} {{ Str::plural('order', $orders->count()) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $peso($bcGross + $scGross) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="text-xs text-muted">Amounts are Shecom's sales per order (without the child TSD row) once synced, else Pancake's total, as in Gross sales. Canceled and deleted orders aren't counted.</p>
    @endif
</div>
