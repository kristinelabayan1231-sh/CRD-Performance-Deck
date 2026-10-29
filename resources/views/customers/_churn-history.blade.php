@php
    $date = fn (?string $day) => $day ? \Carbon\CarbonImmutable::parse($day)->format('M j, Y') : '—';
    $money = fn (?float $value) => $value === null ? '—' : '₱'.number_format($value, 2);
    // An order or delivery after the delivery judged and by the deadline brings them back.
    $inWindow = fn (array $o) => $o['counts'] && collect([$o['ordered'], $o['delivered']])->filter()
        ->contains(fn (string $day) => $day > $window['delivered'] && $day <= $window['deadline']);
    $back = collect($customer['orders'])->filter($inWindow)->sortBy(fn (array $o) => $o['ordered'] ?? $o['delivered'])->first();
    $heading = 'mb-2 flex items-center gap-2 text-sm font-bold text-ink';
    $bar = '<span aria-hidden="true" class="h-4 w-1 rounded-full bg-brand-500"></span>';
    $teamPill = fn (string $team) => $team === 'crd' ? 'bg-brand-100 text-brand-700' : 'bg-sky/20 text-[#156c8c]';
@endphp

<div class="space-y-5">
    {{-- Who, and the reorder window judged --}}
    <div class="space-y-3 rounded-xl border border-line bg-canvas/40 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-lg font-bold">{{ $customer['name'] }}</h2>
                <p class="text-sm text-muted tabular-nums">{{ $customer['phone'] }}</p>
            </div>
            <span class="flex flex-wrap gap-1">
                @if ($back)
                    <span class="rounded-full bg-teal/15 px-2 py-0.5 text-xs font-semibold text-teal-700">Came back</span>
                @else
                    <span class="rounded-full bg-coral/15 px-2 py-0.5 text-xs font-semibold text-coral-700">Lost</span>
                    <span class="rounded-full bg-[#fff1d6] px-2 py-0.5 text-xs font-semibold text-[#8a5a00]">For reactivation</span>
                @endif
            </span>
        </div>
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm tabular-nums">
            <span class="rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold {{ $teamPill($window['team']) }}">{{ strtoupper($window['team']) }}</span>
            Delivered <span class="font-semibold">{{ $date($window['delivered']) }}</span>
            <span class="text-muted">→ ran out</span> {{ $date($window['ran_out']) }}
            <span class="text-muted">→ reorder by</span> <span class="font-semibold">{{ $date($window['deadline']) }}</span>
        </p>
        <p @class(['text-sm', 'text-teal-700' => $back, 'text-coral-700' => ! $back])>
            @if ($back)
                Came back with {{ $back['ordered'] ? 'order' : 'delivery' }} {{ $back['order_id'] }} on {{ $date($back['ordered'] ?? $back['delivered']) }}.
            @else
                No order or delivery after {{ $date($window['delivered']) }} up to {{ $date($window['deadline']) }}.
            @endif
        </p>
    </div>

    {{-- Every order and delivery, newest first --}}
    <section>
        <h3 class="{{ $heading }} !mb-0.5">{!! $bar !!}Order history</h3>
        <p class="mb-2 text-xs text-muted">Every Pancake order and delivery on their {{ Str::plural('number', substr_count($customer['phone'], ' / ') + 1) }}. Shaded = inside the reorder window; canceled orders and orders with no listed product don't bring a customer back.</p>
        @if (empty($customer['orders']))
            <p class="rounded-lg border border-dashed border-line px-3 py-4 text-center text-sm text-muted">No orders found.</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-line">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead class="bg-[#f7f4f8] text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="py-2 pr-3 pl-3 font-semibold">Ordered</th>
                            <th class="px-3 py-2 font-semibold">Order #</th>
                            <th class="px-3 py-2 font-semibold">Products</th>
                            <th class="px-3 py-2 font-semibold">Status</th>
                            <th class="px-3 py-2 font-semibold">Handled by</th>
                            <th class="py-2 pr-3 pl-3 text-right font-semibold">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($customer['orders'] as $order)
                            @php($judged = $order['order_id'] === $window['order_id'])
                            <tr @class(['align-top', 'bg-teal/5' => $inWindow($order), 'bg-brand-50/70' => $judged])>
                                <td class="py-2 pr-3 pl-3 whitespace-nowrap tabular-nums">
                                    {{ $date($order['ordered']) }}
                                    @if ($order['delivered'])
                                        <span class="block text-xs text-muted">delivered {{ \Carbon\CarbonImmutable::parse($order['delivered'])->format('M j') }}</span>
                                    @endif
                                    @if ($judged)
                                        <span class="mt-0.5 inline-block rounded-full bg-brand-600 px-1.5 text-[0.625rem] font-semibold text-white">Judged delivery</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 tabular-nums">{{ $order['order_id'] }}</td>
                                <td class="px-3 py-2">{{ $order['items'] ?: '—' }}</td>
                                <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap {{ $order['status_classes'] }}">{{ $order['status_label'] }}</span></td>
                                <td class="px-3 py-2">
                                    {{ $order['seller'] ?? '—' }}
                                    @if ($order['team'])
                                        <span class="ml-1 rounded-full px-1.5 py-0.5 text-[0.6875rem] font-semibold {{ $teamPill($order['team']) }}">{{ strtoupper($order['team']) }}</span>
                                    @elseif ($order['by_cra'])
                                        <span class="ml-1 rounded-full bg-brand-100 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-brand-700">CRA</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3 pl-3 text-right tabular-nums">{{ $money($order['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
