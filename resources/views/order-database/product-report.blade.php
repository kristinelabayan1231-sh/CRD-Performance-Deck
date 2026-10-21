@php
    $badges = collect(config('customers.pos_statuses'))->map(fn (array $status) => $status[1]);
    $peso = fn (float $amount) => '₱'.number_format($amount);
    $sticky = 'sticky left-0 z-10';
    $tabTitle = 'Product Report';
@endphp

<x-layouts.app title="Order Database">
    <div class="space-y-4">
        @include('order-database._filters', ['tab' => 'order-database.product-report'])

        <x-panel :title="$tabTitle" icon="table" title-id="product-report-title" body-class="p-0" :tinted="false">
            <x-slot:badges>
                <span class="text-xs text-muted">Orders by status, created {{ $label }}. As Pancake's Product Performance: Total qty counts every piece (a 5-piece bundle is 5), sales each item at its Pancake price, Product cost each at its variation's cost. Items not in Settings → Product Consumption are left out.</span>
            </x-slot:badges>

            @if ($rows->isEmpty())
                <p class="px-4 py-8 text-center text-sm text-muted">No orders from {{ $canViewAll ? 'the CRD accounts' : 'your Pancake account' }} in these dates.</p>
            @else
                <div class="x-scrollbar overflow-x-auto">
                    <table class="w-full text-sm tabular-nums">
                        <thead class="bg-canvas text-[11px] font-semibold tracking-wide text-muted uppercase">
                            <tr>
                                <th scope="col" class="{{ $sticky }} min-w-56 bg-canvas px-4 py-3 text-left">Product</th>
                                @foreach ($statuses as $name)
                                    <th scope="col" class="px-3 py-3 text-center whitespace-nowrap">{{ $name }}</th>
                                @endforeach
                                <th scope="col" class="px-3 py-3 text-center whitespace-nowrap">Total qty</th>
                                <th scope="col" class="px-3 py-3 text-right whitespace-nowrap">Total sales</th>
                                <th scope="col" class="px-3 py-3 text-right whitespace-nowrap">Product cost</th>
                                <th scope="col" class="px-3 py-3 text-center whitespace-nowrap" title="Delivered ÷ (delivered + RTS), by sales">Actual Del%</th>
                                <th scope="col" class="px-3 py-3 text-center whitespace-nowrap" title="RTS (returning, returned, partially returned) ÷ (delivered + RTS), by sales">Actual RTS%</th>
                                <th scope="col" class="px-4 py-3 text-center whitespace-nowrap" title="Delivered ÷ (delivered + RTS + still shipped), by sales">Running Del%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-t border-line">
                                    <th scope="row" class="{{ $sticky }} bg-white px-4 py-2.5 text-left font-semibold">
                                        {{ $row['product'] }}
                                        @unless ($row['listed'])
                                            <span class="block text-xs font-normal text-muted">Not in Product Consumption</span>
                                        @endunless
                                    </th>
                                    @include('order-database._status-cells', ['counts' => $row['statuses']])
                                    @include('order-database._product-cells', ['row' => $row, 'cell' => 'py-2.5'])
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-line bg-canvas font-bold">
                                <th scope="row" class="{{ $sticky }} bg-canvas px-4 py-3 text-left">TOTAL</th>
                                @include('order-database._status-cells', ['counts' => $total['statuses']])
                                @include('order-database._product-cells', ['row' => $total, 'cell' => 'py-3'])
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-panel>
    </div>
</x-layouts.app>
