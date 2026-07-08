@extends('layouts.app')

@section('title', 'Performance Deck — ' . config('app.name'))

@section('content')
    <div class="mb-8 flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Performance Deck</h1>
            <p class="mt-1 text-sm text-slate-500">Customer Retention Department &mdash; seller performance, sourced from Pancake POS.</p>
        </div>
        <span id="last-updated" class="flex items-center gap-1.5 text-xs text-slate-400">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Updated just now
        </span>
    </div>

    <form method="GET" action="{{ route('deck.index') }}"
        class="mb-8 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @foreach ($selectedSellers as $s)
            <input type="hidden" name="seller[]" value="{{ $s }}">
        @endforeach
        <div>
            <label for="start_date" class="block text-xs font-medium text-slate-500">Start date</label>
            <input type="date" id="start_date" name="start_date" value="{{ $startDate }}"
                class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
        </div>
        <div>
            <label for="end_date" class="block text-xs font-medium text-slate-500">End date</label>
            <input type="date" id="end_date" name="end_date" value="{{ $endDate }}"
                class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
        </div>
        <button type="submit"
            class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-slate-700">
            Apply
        </button>
        @if (!$error && $isComparisonMode)
            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                Comparing {{ count($days) }} days
            </span>
        @else
            <p class="text-xs text-slate-400">Applies to both sections below. Pick sellers inside "Seller Performance". Pick a 2&ndash;3 day range to compare days side by side.</p>
        @endif
    </form>

    <div id="deck-content">
    @if ($error)
        <div class="mb-8 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            {{ $error }}
        </div>
    @else
        @php
            $margin = $totals['sales_value'] - $totals['product_cost'];
        @endphp

        {{-- Department-wide overview: always ALL CRD sellers, unaffected by the seller filter above. --}}
        <div class="mb-10 rounded-xl border border-slate-200 bg-slate-50/70 p-5">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <h2 class="text-lg font-semibold tracking-tight text-slate-900">Department Overview</h2>
                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-200 px-2.5 py-0.5 text-[11px] font-medium text-slate-600">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M17 3.13a4 4 0 0 1 0 7.75M23 21v-2a4 4 0 0 0-3-3.85"/></svg>
                        All CRD Sellers
                    </span>
                </div>
                <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
                    <button type="button" data-view-toggle="chart"
                        class="rounded-md bg-slate-900 px-3 py-1 text-xs font-medium text-white transition">
                        Chart
                    </button>
                    <button type="button" data-view-toggle="table"
                        class="rounded-md px-3 py-1 text-xs font-medium text-slate-500 transition">
                        Table
                    </button>
                </div>
            </div>

            @if ($isComparisonMode)
                <div data-breakdown-grid class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <x-trend-card title="By Product" :series="$productTrend" :days="$days">
                        <x-slot:icon>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.73Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                        </x-slot:icon>
                    </x-trend-card>
                    <x-trend-card title="By Region" :series="$regionTrend" :days="$days">
                        <x-slot:icon>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        </x-slot:icon>
                    </x-trend-card>
                    <x-trend-card title="By Status" :series="$statusTrend" :days="$days">
                        <x-slot:icon>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </x-slot:icon>
                    </x-trend-card>
                </div>
            @else
                <div data-breakdown-grid class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <x-breakdown-card title="By Product" :items="$productBreakdown">
                        <x-slot:icon>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.73Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                        </x-slot:icon>
                    </x-breakdown-card>
                    <x-breakdown-card title="By Region" :items="$regionBreakdown">
                        <x-slot:icon>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        </x-slot:icon>
                    </x-breakdown-card>
                    <x-breakdown-card title="By Status" :items="$statusBreakdown">
                        <x-slot:icon>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </x-slot:icon>
                    </x-breakdown-card>
                </div>
            @endif
        </div>

        {{-- Seller performance: scoped to the seller picker below, independent of the date-range form above. --}}
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <h2 class="text-lg font-semibold tracking-tight text-slate-900">Seller Performance</h2>
                <span class="inline-flex items-center gap-1 rounded-full bg-teal-100 px-2.5 py-0.5 text-[11px] font-medium text-teal-700">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3Z"/></svg>
                    {{ $selectedSellers ? implode(', ', $selectedSellers) : 'All CRD Sellers' }}
                </span>
            </div>

            <form method="GET" action="{{ route('deck.index') }}" class="flex items-center gap-3">
                <input type="hidden" name="start_date" value="{{ $startDate }}">
                <input type="hidden" name="end_date" value="{{ $endDate }}">

                <details class="relative" data-seller-dropdown>
                    <summary class="flex w-56 cursor-pointer list-none items-center justify-between rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                        <span data-seller-dropdown-label class="truncate">
                            {{ $selectedSellers ? implode(', ', $selectedSellers) : 'All CRD Sellers' }}
                        </span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-slate-400"><polyline points="6 9 12 15 18 9"/></svg>
                    </summary>

                    <div class="absolute z-10 mt-1 max-h-64 w-72 overflow-y-auto rounded-md border border-slate-200 bg-white p-2 shadow-lg">
                        @foreach ($sellers as $name)
                            <label class="flex items-center gap-2 rounded px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                                <input type="checkbox" name="seller[]" value="{{ $name }}"
                                    @checked(in_array($name, $selectedSellers, true))
                                    data-seller-checkbox
                                    class="rounded border-slate-300 text-teal-600 focus:border-teal-500 focus:ring-1 focus:ring-teal-500">
                                {{ $name }}
                            </label>
                        @endforeach
                    </div>
                </details>

                <button type="submit"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
                    Apply
                </button>

                @if ($selectedSellers)
                    <a href="{{ route('deck.index', ['start_date' => $startDate, 'end_date' => $endDate]) }}"
                        class="text-xs font-medium text-slate-400 hover:text-slate-600">
                        Clear
                    </a>
                @endif
            </form>

            <script>
                document.querySelectorAll('[data-seller-dropdown]').forEach((details) => {
                    const label = details.querySelector('[data-seller-dropdown-label]');
                    const checkboxes = details.querySelectorAll('[data-seller-checkbox]');

                    const updateLabel = () => {
                        const checked = Array.from(checkboxes).filter((cb) => cb.checked).map((cb) => cb.value);
                        label.textContent = checked.length ? checked.join(', ') : 'All CRD Sellers';
                    };

                    checkboxes.forEach((cb) => cb.addEventListener('change', updateLabel));

                    document.addEventListener('click', (e) => {
                        if (details.open && !details.contains(e.target)) {
                            details.open = false;
                        }
                    });
                });
            </script>
        </div>

        @if ($isComparisonMode)
            @php
                $salesByDay = array_map(fn ($d) => $d['sales_value'], $kpiByDay);
                $qtyByDay = array_map(fn ($d) => $d['parcel_qty'], $kpiByDay);
                $costByDay = array_map(fn ($d) => $d['product_cost'], $kpiByDay);
                $marginByDay = array_map(fn ($d) => $d['sales_value'] - $d['product_cost'], $kpiByDay);
            @endphp
            <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-kpi-sparkline-card label="Sales Value" :values="$salesByDay" :days="$days">
                    <x-slot:icon>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4v16"/><path d="M7 4h6a4 4 0 0 1 0 8H7"/><path d="M3 10h10"/><path d="M3 14h10"/></svg>
                    </x-slot:icon>
                </x-kpi-sparkline-card>
                <x-kpi-sparkline-card label="Parcel Qty." :values="$qtyByDay" :days="$days" :is-currency="false">
                    <x-slot:icon>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                    </x-slot:icon>
                </x-kpi-sparkline-card>
                <x-kpi-sparkline-card label="Product Cost" :values="$costByDay" :days="$days">
                    <x-slot:icon>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41 11 3.83 3.83 11l9.58 9.58a2 2 0 0 0 2.83 0l4.35-4.35a2 2 0 0 0 0-2.82Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
                    </x-slot:icon>
                </x-kpi-sparkline-card>
                <x-kpi-sparkline-card label="Est. Margin" :values="$marginByDay" :days="$days" :diverging="true">
                    <x-slot:icon>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                    </x-slot:icon>
                </x-kpi-sparkline-card>
            </div>

            <div class="mb-8">
                <x-seller-day-comparison :series="$sellerTrend" :days="$days" />
            </div>
        @else
            <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-2 text-slate-400">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4v16"/><path d="M7 4h6a4 4 0 0 1 0 8H7"/><path d="M3 10h10"/><path d="M3 14h10"/></svg>
                        <p class="text-xs font-medium uppercase tracking-wide">Sales Value</p>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">₱{{ number_format($totals['sales_value'], 2) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-2 text-slate-400">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                        <p class="text-xs font-medium uppercase tracking-wide">Parcel Qty.</p>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($totals['parcel_qty']) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-2 text-slate-400">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41 11 3.83 3.83 11l9.58 9.58a2 2 0 0 0 2.83 0l4.35-4.35a2 2 0 0 0 0-2.82Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
                        <p class="text-xs font-medium uppercase tracking-wide">Product Cost</p>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">₱{{ number_format($totals['product_cost'], 2) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-2 text-slate-400">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                        <p class="text-xs font-medium uppercase tracking-wide">Est. Margin</p>
                    </div>
                    <p class="mt-2 text-2xl font-semibold {{ $margin >= 0 ? 'text-teal-600' : 'text-red-600' }}">₱{{ number_format($margin, 2) }}</p>
                </div>
            </div>

            {{-- Side-by-side per-seller totals when 2+ sellers are selected but the date
                 range is too long/short to trigger the day-by-day comparison view above. --}}
            @if (count($selectedSellers) > 1)
                <div class="mb-8">
                    <p class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-500">Selected sellers compared</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-{{ min(count($selectedSellers), 4) }}">
                        @foreach ($sellerTotals as $name => $t)
                            @if (in_array($name, $selectedSellers, true))
                                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                    <p class="text-xs font-medium text-slate-500">{{ $name }}</p>
                                    <p class="mt-1 text-lg font-semibold text-slate-900">₱{{ number_format($t['sales_value'], 2) }}</p>
                                    <p class="text-xs text-slate-400">{{ number_format($t['parcel_qty']) }} parcels &middot; ₱{{ number_format($t['product_cost'], 2) }} cost</p>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        @endif

        <div class="space-y-6">
            @forelse ($report as $seller => $products)
                @php
                    $sellerSalesValue = array_sum(array_column($products, 'sales_value'));
                    $sellerQty = array_sum(array_column($products, 'parcel_qty'));
                    $sellerCost = array_sum(array_column($products, 'product_cost'));
                    $sellerCollapseId = 'seller-collapse-'.md5($seller);
                @endphp
                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <button type="button"
                        class="flex w-full items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3 text-left"
                        onclick="document.getElementById('{{ $sellerCollapseId }}').dispatchEvent(new Event('seller-toggle'))">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-teal-100 text-[11px] font-semibold text-teal-700">
                                {{ strtoupper(substr(trim(str_replace('CRD', '', $seller)), 0, 1) ?: 'C') }}
                            </span>
                            <h2 class="text-sm font-semibold text-slate-900">{{ $seller }}</h2>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-slate-500">{{ count($products) }} products</span>
                            <svg data-seller-chevron width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-slate-400 transition-transform duration-200">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </div>
                    </button>

                    <div id="{{ $sellerCollapseId }}" data-seller-body>
                        @if (empty($products))
                            <p class="px-5 py-6 text-sm text-slate-400">No orders in this date range.</p>
                        @else
                            @php $pageCount = (int) ceil(count($products) / 10); @endphp
                            <div data-paginated-table class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                            <th class="px-5 py-2 font-medium">Product</th>
                                            <th class="px-5 py-2 font-medium text-right">Sales Value</th>
                                            <th class="px-5 py-2 font-medium text-right">Parcel Qty.</th>
                                            <th class="px-5 py-2 font-medium text-right">Product Cost</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($products as $row)
                                            <tr data-page="{{ intdiv($loop->index, 10) + 1 }}" class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                                                <td class="px-5 py-2 text-slate-700">{{ $row['product'] }}</td>
                                                <td class="px-5 py-2 text-right tabular-nums text-slate-700">₱{{ number_format($row['sales_value'], 2) }}</td>
                                                <td class="px-5 py-2 text-right tabular-nums text-slate-700">{{ number_format($row['parcel_qty']) }}</td>
                                                <td class="px-5 py-2 text-right tabular-nums text-slate-700">₱{{ number_format($row['product_cost'], 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-slate-50 font-semibold">
                                            <td class="px-5 py-2">Total</td>
                                            <td class="px-5 py-2 text-right tabular-nums">₱{{ number_format($sellerSalesValue, 2) }}</td>
                                            <td class="px-5 py-2 text-right tabular-nums">{{ number_format($sellerQty) }}</td>
                                            <td class="px-5 py-2 text-right tabular-nums">₱{{ number_format($sellerCost, 2) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>

                                @if ($pageCount > 1)
                                    <div class="flex items-center justify-end gap-3 border-t border-slate-100 px-5 py-2.5">
                                        <button type="button" data-page-prev
                                            class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-30">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                                        </button>
                                        <span data-page-indicator class="text-xs tabular-nums text-slate-500">Page 1 of {{ $pageCount }}</span>
                                        <button type="button" data-page-next
                                            class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-30">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </section>
            @empty
                <div class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
                    No CRD sales data in this date range.
                </div>
            @endforelse
        </div>
    @endif
    </div>

    <script>
        document.querySelectorAll('[data-seller-body]').forEach((body) => {
            if (body.dataset.sellerBound) {
                return;
            }
            body.dataset.sellerBound = 'true';

            const section = body.closest('section');
            const chevron = section.querySelector('[data-seller-chevron]');

            body.addEventListener('seller-toggle', () => {
                const collapsed = body.classList.toggle('hidden');
                chevron.style.transform = collapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
            });
        });
    </script>
@endsection