@php
    $money = fn (?float $value) => $value === null ? '—' : '₱'.number_format($value, 2);
@endphp

<div class="space-y-5">
    {{-- Who --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold">
                {{ $customer['name'] }}
                @if ($customer['segment'])
                    <span @class([
                        'ml-1 rounded-full px-2 py-0.5 align-middle text-xs font-semibold',
                        'bg-teal/15 text-teal-700' => $customer['segment'] === 'Retained',
                        'bg-brand-100 text-brand-700' => $customer['segment'] !== 'Retained',
                    ])>{{ $customer['segment'] }}</span>
                @else
                    <span class="ml-1 rounded-full bg-canvas px-2 py-0.5 align-middle text-xs font-semibold text-muted">FSD only</span>
                @endif
            </h2>
            <p class="text-sm text-muted tabular-nums">{{ $customer['phone'] }}</p>
            @php($since = \Carbon\CarbonImmutable::parse(\App\Models\LogisticsOrder::coveredFrom())->format('M j, Y'))
            <p class="mt-1 text-xs text-muted">
                @if ($customer['prior_cra_orders'] === null)
                    Orders before {{ $since }} not checked in Pancake yet.
                @elseif ($customer['prior_cra_orders'] > 0)
                    Before {{ $since }}: <span class="font-semibold text-ink">{{ $customer['prior_cra_orders'] }} CRA-handled {{ Str::plural('order', $customer['prior_cra_orders']) }}</span>
                    (last {{ $customer['prior_last_ordered_on']?->format('M j, Y') }}), counted for Retained / Repeat.
                @else
                    No CRA-handled orders before {{ $since }}.
                @endif
            </p>
        </div>
        <dl class="flex gap-6 text-right">
            <div>
                <dt class="text-[11px] font-semibold tracking-wide text-muted uppercase">QTY</dt>
                <dd class="text-xl font-bold tabular-nums">{{ number_format($customer['purchases']) }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-wide text-muted uppercase">By a CRA</dt>
                <dd class="text-xl font-bold tabular-nums">{{ number_format($customer['cra_orders']) }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-wide text-muted uppercase">Total spent</dt>
                <dd class="text-xl font-bold tabular-nums">{{ $money($customer['total_spent']) }}</dd>
            </div>
        </dl>
    </div>

    {{-- Status breakdown --}}
    <section>
        <h3 class="mb-2 text-sm font-semibold">Orders by status</h3>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            @foreach ($customer['statuses'] as $status)
                <div class="rounded-lg border border-line p-3">
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $status['classes'] }}">{{ $status['label'] }}</span>
                    <p class="mt-1.5 text-lg font-bold tabular-nums">{{ number_format($status['orders']) }} <span class="text-sm font-normal text-muted">{{ Str::plural('order', $status['orders']) }}</span></p>
                    <p class="text-sm font-semibold tabular-nums text-muted">{{ $money($status['amount']) }}</p>
                    @if ($status['unpriced'])
                        <p class="text-xs text-muted">{{ $status['unpriced'] }} not in Pancake yet (no amount)</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- Product CLTV --}}
    <section>
        <h3 class="text-sm font-semibold">Product CLTV</h3>
        <p class="mb-2 text-xs text-muted">Delivered units of Product Consumption products × SRP, against the product's CLTV (SRP × {{ config('customers.cltv_units') }}).</p>
        @if (empty($customer['products']))
            <p class="rounded-lg border border-dashed border-line px-3 py-4 text-center text-sm text-muted">No Product Consumption products in this customer's delivered orders.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-sm">
                    <thead class="text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="py-2 pr-3 font-semibold">Product</th>
                            <th class="px-3 py-2 text-right font-semibold">Units</th>
                            <th class="px-3 py-2 text-right font-semibold">Spent</th>
                            <th class="px-3 py-2 text-right font-semibold">CLTV</th>
                            <th class="py-2 pl-3 font-semibold">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($customer['products'] as $product)
                            <tr>
                                <td class="py-2 pr-3 font-medium">{{ $product['name'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($product['units']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" title="{{ $product['srp'] !== null ? $product['units'].' × '.$money($product['srp']) : '' }}">{{ $money($product['spent']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $money($product['cltv']) }}</td>
                                <td class="py-2 pl-3">
                                    @if ($product['cltv'] === null)
                                        <span class="text-xs text-muted">No SRP set</span>
                                    @elseif ($product['reached'])
                                        <span class="rounded-full bg-[#d4edbc] px-2 py-0.5 text-xs font-semibold text-[#11734b]">Reached CLTV</span>
                                    @else
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-24 overflow-hidden rounded-full bg-canvas" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($product['progress'] * 100) }}">
                                                <div class="h-full rounded-full bg-brand-500" style="width: {{ round($product['progress'] * 100, 1) }}%"></div>
                                            </div>
                                            <span class="text-xs whitespace-nowrap text-muted tabular-nums">{{ $money($product['cltv'] - $product['spent']) }} to go</span>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Orders --}}
    <section>
        <h3 class="mb-2 text-sm font-semibold">Orders</h3>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="text-xs tracking-wide text-muted uppercase">
                    <tr>
                        <th class="py-2 pr-3 font-semibold">Ordered</th>
                        <th class="px-3 py-2 font-semibold">Order #</th>
                        <th class="px-3 py-2 font-semibold">Products</th>
                        <th class="px-3 py-2 font-semibold">Status</th>
                        <th class="px-3 py-2 font-semibold">Handled by</th>
                        <th class="py-2 pl-3 text-right font-semibold">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($customer['orders'] as $order)
                        <tr class="align-top">
                            <td class="py-2 pr-3 whitespace-nowrap tabular-nums">
                                {{ $order['date']?->format('M j, Y') ?? '—' }}
                                @if ($order['delivered_on'])
                                    <span class="block text-xs text-muted">delivered {{ $order['delivered_on']->format('M j') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 tabular-nums">{{ $order['order_id'] }}</td>
                            <td class="px-3 py-2">{{ collect($order['items'])->map(fn ($i) => ($i['qty'] ?? 1).' × '.$i['name'])->join(', ') ?: '—' }}</td>
                            <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap {{ $order['status_classes'] }}">{{ $order['status_label'] }}</span></td>
                            <td class="px-3 py-2">
                                {{ $order['seller'] ?? ($order['team'] === 'crd' ? 'CRD' : ($order['team'] === 'fsd' ? 'FSD' : '—')) }}
                                @if ($order['by_cra'])
                                    <span class="ml-1 rounded-full bg-brand-100 px-1.5 py-0.5 text-[11px] font-semibold text-brand-700">CRA</span>
                                @endif
                            </td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $money($order['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
