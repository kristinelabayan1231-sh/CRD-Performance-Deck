@php
    $badges = collect(config('customers.pos_statuses'))->map(fn (array $status) => $status[1]);
    $peso = fn (float $amount) => '₱'.number_format($amount);
    $sticky = 'sticky left-0 z-10';
    $tabTitle = "CRA's Performance";
@endphp

<x-layouts.app title="Order Database">
    <div class="space-y-4">
        @include('order-database._filters', ['tab' => 'order-database.cra-performance'])

        <x-panel :title="$tabTitle" icon="table" title-id="cra-performance-title" body-class="p-0" :tinted="false">
            <x-slot:badges>
                <span class="text-xs text-muted">Orders by status, created {{ $label }}. Click a CRA for their products; an order counts under its main product.</span>
            </x-slot:badges>

            @if ($rows->isEmpty())
                <p class="px-4 py-8 text-center text-sm text-muted">No orders from {{ $canViewAll ? 'the CRD accounts' : 'your Pancake account' }} in these dates.</p>
            @else
                <div class="x-scrollbar overflow-x-auto">
                    <table class="w-full text-sm tabular-nums">
                        <thead class="bg-canvas text-[11px] font-semibold tracking-wide text-muted uppercase">
                            <tr>
                                <th scope="col" class="{{ $sticky }} min-w-56 bg-canvas px-4 py-3 text-left">Assigned seller</th>
                                @foreach ($statuses as $name)
                                    <th scope="col" class="px-3 py-3 text-center whitespace-nowrap">{{ $name }}</th>
                                @endforeach
                                <th scope="col" class="px-3 py-3 text-center whitespace-nowrap">Total qty</th>
                                <th scope="col" class="px-4 py-3 text-right whitespace-nowrap">Total sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $index => $row)
                                <tr class="border-t border-line">
                                    <th scope="row" class="{{ $sticky }} bg-white px-4 py-2.5 text-left">
                                        <button type="button" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="products-{{ $index }}"
                                                onclick="const open = this.getAttribute('aria-expanded') !== 'true'; this.setAttribute('aria-expanded', open); document.querySelectorAll('[data-products=&quot;{{ $index }}&quot;]').forEach((row) => row.hidden = ! open)"
                                                class="group inline-flex items-center gap-1.5 font-semibold text-brand-600 hover:underline">
                                            <svg class="size-3.5 transition group-aria-expanded:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>
                                            {{ $row['name'] }}
                                        </button>
                                    </th>
                                    @include('order-database._status-cells', ['counts' => $row['statuses']])
                                    <td class="px-3 py-2.5 text-center font-mono">{{ number_format($row['qty']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-mono">{{ $peso($row['sales']) }}@include('order-database._discount', ['discount' => $row['discount']])</td>
                                </tr>
                                @foreach ($row['products'] as $product)
                                    <tr data-products="{{ $index }}" @if ($loop->first) id="products-{{ $index }}" @endif @if (! $open) hidden @endif class="border-t border-brand-100 bg-brand-50/60 text-[13px]">
                                        <th scope="row" class="{{ $sticky }} bg-brand-50 py-2 pr-4 pl-10 text-left font-medium">{{ $product['product'] }}</th>
                                        @include('order-database._status-cells', ['counts' => $product['statuses']])
                                        <td class="px-3 py-2 text-center font-mono">{{ number_format($product['qty']) }}</td>
                                        <td class="px-4 py-2 text-right font-mono">{{ $peso($product['sales']) }}@include('order-database._discount', ['discount' => $product['discount']])</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-line bg-canvas font-bold">
                                <th scope="row" class="{{ $sticky }} bg-canvas px-4 py-3 text-left">TOTAL</th>
                                @include('order-database._status-cells', ['counts' => $total['statuses']])
                                <td class="px-3 py-3 text-center font-mono">{{ number_format($total['qty']) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ $peso($total['sales']) }}@include('order-database._discount', ['discount' => $total['discount']])</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-panel>
    </div>
</x-layouts.app>
