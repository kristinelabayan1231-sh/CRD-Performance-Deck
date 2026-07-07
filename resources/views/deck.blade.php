@extends('layouts.app')

@section('title', 'Performance Deck — ' . config('app.name'))

@section('content')
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Performance Deck</h1>
        <p class="mt-1 text-sm text-slate-500">Customer Retention Department &mdash; seller performance, sourced from Pancake POS.</p>
    </div>

    <form method="GET" action="{{ route('deck.index') }}"
        class="mb-8 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div>
            <label for="seller" class="block text-xs font-medium text-slate-500">CRD Seller</label>
            <select id="seller" name="seller" onchange="this.form.submit()"
                class="mt-1 w-56 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                <option value="">All CRD Sellers</option>
                @foreach ($sellers as $name)
                    <option value="{{ $name }}" @selected($selectedSeller === $name)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
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

        @if ($selectedSeller)
            <a href="{{ route('deck.index', ['start_date' => $startDate, 'end_date' => $endDate]) }}"
                class="text-sm font-medium text-slate-400 hover:text-slate-600">
                Clear seller filter
            </a>
        @endif
    </form>

    @if ($error)
        <div class="mb-8 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            {{ $error }}
        </div>
    @else
        @php
            $margin = $totals['sales_value'] - $totals['product_cost'];
        @endphp
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

        <div class="mb-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold tracking-tight text-slate-900">Breakdowns</h2>
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

            <div data-breakdown-grid class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <x-breakdown-card title="By Product" :items="$productBreakdown" />
                <x-breakdown-card title="By Region" :items="$regionBreakdown" />
                <x-breakdown-card title="By Status" :items="$statusBreakdown" />
            </div>
        </div>

        <div class="space-y-6">
            @forelse ($report as $seller => $products)
                @php
                    $sellerSalesValue = array_sum(array_column($products, 'sales_value'));
                    $sellerQty = array_sum(array_column($products, 'parcel_qty'));
                    $sellerCost = array_sum(array_column($products, 'product_cost'));
                @endphp
                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-teal-100 text-[11px] font-semibold text-teal-700">
                                {{ strtoupper(substr(trim(str_replace('CRD', '', $seller)), 0, 1) ?: 'C') }}
                            </span>
                            <h2 class="text-sm font-semibold text-slate-900">{{ $seller }}</h2>
                        </div>
                        <span class="text-xs text-slate-500">{{ count($products) }} products</span>
                    </div>

                    @if (empty($products))
                        <p class="px-5 py-6 text-sm text-slate-400">No orders in this date range.</p>
                    @else
                        <div class="overflow-x-auto">
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
                                        <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
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
                        </div>
                    @endif
                </section>
            @empty
                <div class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
                    No CRD sales data in this date range.
                </div>
            @endforelse
        </div>
    @endif
@endsection
