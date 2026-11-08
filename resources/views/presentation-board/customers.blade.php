<x-layouts.app :title="$title">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ $title }} <span class="text-sm font-normal text-muted">· {{ $rangeLabel }}</span></h1>
                @if ($customers->total() === $listedCount)
                    <p class="text-sm text-muted">{{ number_format($customers->total()) }} {{ Str::plural('customer', $customers->total()) }}, the same ones the board counts. A customer in two months is listed in each.</p>
                @else
                    <p class="text-sm text-muted">{{ number_format($customers->total()) }} of the {{ number_format($listedCount) }} the board counts. A customer in two months is listed in each.</p>
                @endif
            </div>
            <a href="{{ route('presentation-board.index', ['from' => $from, 'to' => $to]) }}" class="text-sm font-semibold text-brand-600 hover:underline">&larr; Presentation Board</a>
        </header>

        @php($listQuery = ['metric' => $filters['metric'], 'from' => $from, 'to' => $to])
        <x-customer-list-filters :action="route('presentation-board.customers')" :filters="$filters" :hidden="$listQuery" :clear-url="route('presentation-board.customers', $listQuery)" />

        <div class="overflow-x-auto rounded-2xl border border-line bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-canvas/60 text-left text-xs text-muted">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Customer</th>
                        <th class="px-4 py-3 font-semibold">Product</th>
                        <th class="px-4 py-3 font-semibold">Order</th>
                        <th class="px-4 py-3 font-semibold">Delivered</th>
                        <th class="px-4 py-3 font-semibold">{{ $dateLabel }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line tabular-nums">
                    @forelse ($customers as $customer)
                        @php($flags = $highValue[$customer['customer']] ?? [])
                        <tr @class(['bg-[#fbf1d6]' => $flags['vip'] ?? false, 'bg-brand-50' => ! ($flags['vip'] ?? false) && ($flags['high_aov'] ?? false)])>
                            <td class="px-4 py-3">
                                @can('customers.view')
                                    <a href="{{ route('customers.index', ['search' => $customer['customer']]) }}" class="font-medium hover:text-brand-600 hover:underline" title="Open in the Customer Database">{{ $customer['name'] }}</a>
                                @else
                                    <span class="font-medium">{{ $customer['name'] }}</span>
                                @endcan
                                @include('customers._value-pill', ['flags' => $flags])
                                <span class="block text-xs text-muted">{{ $customer['phone'] }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $customer['product'] }}</td>
                            <td class="px-4 py-3">{{ $customer['order_id'] }}</td>
                            <td class="px-4 py-3">{{ $customer['delivered'] ? \Carbon\CarbonImmutable::parse($customer['delivered'])->format('M j, Y') : '—' }}</td>
                            <td class="px-4 py-3">{{ \Carbon\CarbonImmutable::parse($customer['day'])->format('M j, Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-muted">{{ $listedCount ? 'No customers match.' : 'No customers in these months.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $customers->links() }}
    </div>
</x-layouts.app>
