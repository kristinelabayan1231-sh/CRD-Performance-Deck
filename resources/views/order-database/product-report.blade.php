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
                <span class="text-xs text-muted">Orders by status, created {{ $label }}. Total qty counts every piece; an order with several products splits its sale between them. Click a product for its CRAs.</span>
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
                            @foreach ($rows as $index => $row)
                                <tr class="border-t border-line">
                                    <th scope="row" class="{{ $sticky }} bg-white px-4 py-2.5 text-left">
                                        <button type="button" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="sellers-{{ $index }}"
                                                onclick="const open = this.getAttribute('aria-expanded') !== 'true'; this.setAttribute('aria-expanded', open); document.querySelectorAll('[data-sellers=&quot;{{ $index }}&quot;]').forEach((row) => row.hidden = ! open)"
                                                class="group inline-flex items-center gap-1.5 text-left font-semibold text-brand-600 hover:underline">
                                            <svg class="size-3.5 shrink-0 transition group-aria-expanded:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>
                                            {{ $row['product'] }}
                                        </button>
                                    </th>
                                    @include('order-database._status-cells', ['counts' => $row['statuses']])
                                    @include('order-database._product-cells', ['row' => $row, 'cell' => 'py-2.5'])
                                </tr>
                                @foreach ($row['sellers'] as $seller)
                                    <tr data-sellers="{{ $index }}" @if ($loop->first) id="sellers-{{ $index }}" @endif @if (! $open) hidden @endif class="border-t border-brand-100 bg-brand-50/60 text-[13px]">
                                        <th scope="row" class="{{ $sticky }} bg-brand-50 py-2 pr-4 pl-10 text-left font-medium">{{ $seller['name'] }}</th>
                                        @include('order-database._status-cells', ['counts' => $seller['statuses']])
                                        @include('order-database._product-cells', ['row' => $seller, 'cell' => 'py-2'])
                                    </tr>
                                @endforeach
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
