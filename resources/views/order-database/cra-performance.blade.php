@php
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $kept = array_filter(['seller' => $filters['seller'] ?? null]);
    $badges = collect(config('customers.pos_statuses'))->map(fn (array $status) => $status[1]);
    $peso = fn (float $amount) => '₱'.number_format($amount);
    $sticky = 'sticky left-0 z-10';
    $tabTitle = "CRA's Performance";
@endphp

<x-layouts.app title="Order Database">
    <div class="space-y-4">
        <header>
            <h1 class="text-xl font-semibold">Order Database <span class="text-sm font-normal text-muted">· {{ $label }} · Pancake POS orders sold by a CRD account (Settings → Pancake Accounts)</span></h1>
        </header>

        <nav class="flex gap-1 border-b-2 border-line" aria-label="Order Database">
            <a href="{{ route('order-database.cra-performance') }}" aria-current="page" class="-mb-0.5 rounded-t-lg border-b-2 border-brand-600 bg-white px-4 py-2 text-sm font-semibold text-brand-600">CRA's Performance</a>
        </nav>

        {{-- Day, week (Monday–Sunday), month or From–To; switching keeps the dates shown as the starting point --}}
        <form method="GET" action="{{ route('order-database.cra-performance') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-brand-200 bg-gradient-to-r from-brand-100 to-brand-50 p-4 shadow-sm" aria-label="Dates and filters">
            @if ($period !== 'range')
                <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                <input type="hidden" name="to" value="{{ $to->toDateString() }}">
            @endif

            {{-- The period buttons submit their own; the other controls switch this on to keep the current one --}}
            <input type="hidden" name="period" value="{{ $period }}" disabled data-period>
            @php($submit = "this.form.querySelector('[data-period]').disabled = false; this.form.submit()")

            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold tracking-wide text-muted uppercase">Per</span>
                <div class="flex rounded-lg border border-line bg-white p-0.5" role="group" aria-label="Period">
                    @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'range' => 'Range'] as $value => $name)
                        <button type="submit" name="period" value="{{ $value }}" aria-pressed="{{ $period === $value ? 'true' : 'false' }}"
                                @class(['h-8 rounded-md px-3 text-sm font-semibold', 'bg-brand-600 text-white' => $period === $value, 'text-muted hover:text-brand-600' => $period !== $value])>{{ $name }}</button>
                    @endforeach
                </div>
            </div>

            @switch ($period)
                @case ('day')
                @case ('week')
                    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                        {{ $period === 'day' ? 'Day' : 'Week of' }}
                        <input type="date" name="date" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}"
                               onchange="{{ $submit }}" class="{{ $control }} text-ink normal-case">
                    </label>
                    @break
                @case ('month')
                    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                        Month
                        <select name="month" onchange="{{ $submit }}" class="{{ $control }} font-semibold text-ink normal-case">
                            @for ($m = 0; $m < 12; $m++)
                                @php($option = $today->startOfMonth()->subMonths($m))
                                <option value="{{ $option->format('Y-m') }}" @selected($from->isSameMonth($option))>{{ $option->format('F Y') }}</option>
                            @endfor
                        </select>
                    </label>
                    @break
                @default
                    <div class="flex flex-col gap-1.5">
                        <span class="text-xs font-semibold tracking-wide text-muted uppercase">From – to</span>
                        <div class="flex items-center gap-1.5">
                            <input type="date" name="from" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="From" onchange="{{ $submit }}" class="{{ $control }}">
                            <span class="text-sm text-muted">to</span>
                            <input type="date" name="to" value="{{ $to->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="To" onchange="{{ $submit }}" class="{{ $control }}">
                        </div>
                    </div>
            @endswitch

            @if ($canViewAll)
                <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    CRA
                    <select name="seller" onchange="{{ $submit }}" class="{{ $control }} text-ink normal-case">
                        <option value="">All CRAs</option>
                        @foreach ($accounts as $key => $name)
                            <option value="{{ $key }}" @selected(($filters['seller'] ?? null) === $key)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white">Apply</button></noscript>
        </form>

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
