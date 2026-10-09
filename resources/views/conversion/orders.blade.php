@php
    $dashboardQuery = array_filter(['month' => $filters['month'] ?? null, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]);
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
@endphp

<x-layouts.app title="Confirmed Orders">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Confirmed Orders <span class="text-sm font-normal text-muted">· {{ $range->label() }} · tagged CRD - BROADCAST or CRD - SEGMENTATION</span></h1>
            <a href="{{ route('dashboard', $dashboardQuery) }}" class="text-sm font-semibold text-brand-600 hover:underline">&larr; Dashboard</a>
        </header>

        <section aria-label="Totals" class="grid grid-cols-3 gap-3">
            @foreach ([['Confirmed orders', number_format($count)], ['Gross sales', '₱'.number_format($gross, 2)], ['AOV', $aov === null ? '—' : '₱'.number_format($aov, 2)]] as [$label, $value])
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">{{ $label }}</p>
                    <p class="text-xl font-bold tabular-nums">{{ $value }}</p>
                </div>
            @endforeach
        </section>

        @if ($canViewAll)
            <form method="GET" action="{{ route('conversion.orders') }}" class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm">
                @foreach ($dashboardQuery as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    CRA
                    <select name="cra" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                        <option value="">All CRAs</option>
                        @foreach ($allCras as $cra)
                            <option value="{{ $cra->id }}" @selected((int) ($filters['cra'] ?? 0) === $cra->id)>{{ $cra->displayName() }}</option>
                        @endforeach
                    </select>
                </label>
            </form>
        @endif

        <section class="overflow-hidden rounded-xl bg-white shadow-sm">
            @if ($orders->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">No confirmed orders in this period.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Ordered</th>
                                <th class="px-4 py-3 font-semibold">Order #</th>
                                <th class="px-4 py-3 font-semibold">CRA</th>
                                <th class="px-4 py-3 font-semibold">Customer</th>
                                <th class="px-4 py-3 font-semibold">Tag</th>
                                <th class="px-4 py-3 font-semibold">Status</th>
                                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($orders as $order)
                                @php($status = $statuses[$order->status] ?? null)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap tabular-nums">{{ ($order->ordered_at?->timezone(config('segmentation.timezone')) ?? $order->ordered_on)->format($order->ordered_at ? 'M j, g:i A' : 'M j') }}</td>
                                    <td class="px-4 py-3 tabular-nums">{{ $order->pancake_order_id }}</td>
                                    <td class="px-4 py-3">{{ $craFor($order)?->displayName() ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        {{ $order->customer_name ?: '—' }}
                                        @if ($order->phone_number)
                                            <span class="block text-xs text-muted tabular-nums">{{ $order->phone_number }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap', 'bg-[#e3f3f9] text-[#0c5a7d]' => $order->conversion_type === 'broadcast', 'bg-brand-100 text-brand-700' => $order->conversion_type !== 'broadcast'])>
                                            {{ $order->conversion_type === 'broadcast' ? 'Broadcast' : 'Segmentation' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap {{ $status[1] ?? 'bg-canvas text-muted' }}">{{ $status[0] ?? 'Status '.($order->status ?? '—') }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">₱{{ number_format($order->sales(), 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($orders->hasPages())
                    <div class="border-t border-line px-4 py-3">{{ $orders->links() }}</div>
                @endif
            @endif
        </section>
    </div>
</x-layouts.app>
