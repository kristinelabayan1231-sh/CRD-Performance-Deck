{{-- Orders that left Canceled / Waiting: when the sync noticed it, the order, and from → to --}}
@php($badge = fn (?int $status) => $statuses[$status] ?? ($status === 7 ? ['Deleted', 'bg-[#e6e6e6] text-[#3d3d3d]'] : ['Status '.($status ?? '—'), 'bg-canvas text-muted']))
<div class="overflow-x-auto">
    <table class="w-full min-w-[51.25rem] text-left text-sm">
        <thead class="bg-[#ecfaf6] text-xs tracking-wide text-muted uppercase">
            <tr>
                <th class="px-4 py-3 font-semibold">Changed</th>
                <th class="px-4 py-3 font-semibold">Order #</th>
                <th class="px-4 py-3 font-semibold">CRA</th>
                <th class="px-4 py-3 font-semibold">Customer</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3 text-right font-semibold">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @foreach ($changes as $change)
                @php($order = $change->order)
                @php([$fromLabel, $fromClass] = $badge($change->from_status))
                @php([$toLabel, $toClass] = $badge($change->to_status))
                <tr>
                    <td class="px-4 py-3 whitespace-nowrap tabular-nums">
                        {{ $change->created_at->timezone(config('segmentation.timezone'))->format('M j, g:i A') }}
                        @if (isset($unseenIds) && $unseenIds->contains($change->id))
                            <span class="ml-1 rounded-full bg-teal-700 px-1.5 py-0.5 text-[0.625rem] font-bold text-white uppercase">New</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 tabular-nums">
                        {{ $change->pancake_order_id }}
                        @if ($order)
                            <span class="block text-xs text-muted">ordered {{ $order->ordered_on->format('M j') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $order ? ($craFor($order)?->displayName() ?? $order->seller_name ?? '—') : '—' }}</td>
                    <td class="px-4 py-3">{{ $order?->customer_name ?: '—' }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $fromClass }}">{{ $fromLabel }}</span>
                        <span class="mx-1 text-muted" aria-label="to">&rarr;</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $toClass }}">{{ $toLabel }}</span>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $order ? '₱'.number_format($order->sales(), 2) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
