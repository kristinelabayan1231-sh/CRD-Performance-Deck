@extends('layouts.app')

@section('title', 'Customers — ' . config('app.name'))

@php
    $sortLabels = ['ltv_desc' => 'Highest LTV', 'ltv_asc' => 'Lowest LTV', 'orders_desc' => 'Most Orders'];
    $periodLabels = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly'];
    $periodPrefixes = ['day' => '', 'week' => 'Week of ', 'month' => 'Month of ', 'year' => 'Year '];
@endphp

@section('content')
    <x-page-header title="Customers" subtitle="Customer database — details, purchase history, and lifetime value, sourced from Pancake POS." />

    @unless ($posCredential)
        <div class="rounded-xl bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
            POS credentials aren't configured yet. An admin needs to set the Shop ID and API Key under Admin → Facebook Pages.
        </div>
    @else
        <form method="GET" action="{{ route('customers.index') }}" class="mb-6 flex flex-wrap items-end gap-3 rounded-xl bg-white p-5 shadow-sm">
            <input type="hidden" name="period" value="{{ $period }}">
            <div class="flex-1 min-w-[240px]">
                <label for="q" class="block text-xs font-medium text-slate-500">Search by name or phone number</label>
                <input type="text" id="q" name="q" value="{{ $search }}" placeholder="e.g. Carlos or 0912..."
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
            </div>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                Search
            </button>
            @if ($search)
                <a href="{{ route('customers.index', $period === 'day' ? ['period' => $period, 'date' => $selectedDate] : ['period' => $period]) }}" class="text-xs font-medium text-slate-400 hover:text-slate-600">Clear</a>
            @endif
        </form>

        @if ($search)
            {{-- Search mode: unbounded full-database lookup, no dashboard/period. --}}
            <p class="mb-3 text-xs text-slate-400">{{ number_format($totalEntries) }} customer{{ $totalEntries === 1 ? '' : 's' }} matched, sorted among the first results scanned.</p>
        @else
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="inline-flex rounded-lg bg-white p-1 shadow-sm">
                        @foreach ($periodLabels as $key => $label)
                            <a href="{{ route('customers.index', $key === 'day' ? ['period' => 'day', 'date' => $selectedDate] : ['period' => $key]) }}"
                                class="rounded-md px-3 py-1 text-xs font-medium transition {{ $period === $key ? 'bg-slate-900 text-white' : 'text-slate-500' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                    @if ($period === 'day')
                        <form method="GET" action="{{ route('customers.index') }}">
                            <input type="hidden" name="period" value="day">
                            <input type="date" name="date" value="{{ $selectedDate }}" max="{{ now()->toDateString() }}" onchange="this.form.submit()"
                                class="rounded-md border border-slate-300 px-2 py-1.5 text-xs focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                        </form>
                    @endif
                </div>
                @if ($periodLabel)
                    <span class="text-sm font-medium text-slate-700">
                        {{ $periodPrefixes[$period] }}{{ $periodLabel }}
                        @if ($computedAt)
                            <span class="text-xs font-normal text-slate-400">&middot; as of {{ $computedAt->diffForHumans() }}</span>
                        @endif
                    </span>
                @endif
            </div>
        @endif

        @if ($mode === 'pending')
            <div class="rounded-xl bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
                {{ $periodLabels[$period] }} data for {{ $periodLabel }} hasn't been computed yet — it refreshes hourly in the background.
                <br>Run <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">php artisan pancake:sync-customer-dashboard</code> to build it now.
            </div>
        @endif

        @if ($mode === 'range' && $dashboard)
            @if ($dashboard['truncated'] ?? false)
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-700">
                    This {{ strtolower($periodLabels[$period]) }} view only scanned the most recent {{ number_format($dashboard['orderCount']) }} of {{ number_format($dashboard['totalEntries']) }} orders in {{ $periodLabel }} — the numbers below are a partial, not complete, picture.
                </div>
            @endif

            {{-- KPI cards --}}
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-slate-400">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span class="text-xs font-medium uppercase tracking-wide">Customers</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($dashboard['customerCount']) }}</p>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-slate-400">
                        <span class="flex h-[15px] w-[15px] items-center justify-center text-[13px] font-bold leading-none">₱</span>
                        <span class="text-xs font-medium uppercase tracking-wide">Revenue</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900">₱{{ number_format($dashboard['revenueTotal']) }}</p>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-slate-400">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                        <span class="text-xs font-medium uppercase tracking-wide">Orders</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($dashboard['orderCount']) }}</p>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2 text-slate-400">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
                        <span class="text-xs font-medium uppercase tracking-wide">Avg Order</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900">₱{{ $dashboard['orderCount'] > 0 ? number_format($dashboard['revenueTotal'] / $dashboard['orderCount']) : 0 }}</p>
                </div>
            </div>

            <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                {{-- Top 5 customers by LTV --}}
                <div class="rounded-xl bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Top 5 Customers</p>
                    <div class="mt-3 space-y-3">
                        @forelse ($dashboard['topCustomers'] as $i => $c)
                            <a href="{{ route('customers.show', $c['customer_id']) }}" class="flex items-center gap-3 hover:opacity-80">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-[11px] font-bold text-white">{{ $i + 1 }}</span>
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-100 text-xs font-semibold text-teal-700">
                                    {{ strtoupper(substr($c['name'] ?? '?', 0, 1)) }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-800">{{ $c['name'] ?? '—' }}</span>
                                    <span class="block text-xs text-slate-400">{{ $c['order_count'] ?? 0 }} orders</span>
                                </span>
                                <span class="shrink-0 text-sm font-semibold text-slate-900">₱{{ number_format($c['purchased_amount'] ?? 0) }}</span>
                            </a>
                        @empty
                            <p class="text-sm text-slate-400">No purchases in this period.</p>
                        @endforelse
                    </div>
                </div>

                {{-- Customers per page --}}
                <div class="rounded-xl bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customers per Page</p>
                    @php $maxCount = $dashboard['customersPerPage'][0]['count'] ?? 1; @endphp
                    <div class="mt-3 space-y-2.5">
                        @forelse ($dashboard['customersPerPage'] as $p)
                            <div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="truncate text-slate-600">{{ $p['page_name'] }}</span>
                                    <span class="ml-2 shrink-0 font-medium text-slate-900">{{ number_format($p['count']) }}</span>
                                </div>
                                <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-teal-400" style="width: {{ $maxCount > 0 ? round(($p['count'] / $maxCount) * 100) : 0 }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-400">No activity in this period.</p>
                        @endforelse
                    </div>
                </div>

                {{-- Top 5 products --}}
                <div class="rounded-xl bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Top 5 Products</p>
                    <div class="mt-3 space-y-4">
                        @forelse ($dashboard['topProducts'] as $i => $p)
                            <div class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-[11px] font-bold text-white">{{ $i + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="text-sm font-medium text-slate-800">{{ $p['name'] }}</span>
                                        <span class="shrink-0 text-sm font-semibold text-slate-900">₱{{ number_format($p['revenue']) }}</span>
                                    </div>
                                    <p class="text-xs text-slate-400">{{ $p['quantity'] }} sold</p>
                                    @if ($p['top_customer'])
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            Top buyer: <span class="font-medium text-slate-700">{{ $p['top_customer'] }}</span>
                                            <span class="text-slate-400">— ₱{{ number_format($p['top_customer_value'] ?? 0) }}</span>
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-400">No sales in this period.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        @if ($mode === 'search' || $mode === 'range')
            <div class="mb-3 flex items-center justify-end">
                <form method="GET" action="{{ route('customers.index') }}" class="flex items-center gap-2 text-xs">
                    <input type="hidden" name="q" value="{{ $search }}">
                    <input type="hidden" name="period" value="{{ $period }}">
                    @if ($period === 'day')
                        <input type="hidden" name="date" value="{{ $selectedDate }}">
                    @endif
                    <label for="sort" class="text-slate-400">Sort by</label>
                    <select id="sort" name="sort" onchange="this.form.submit()"
                        class="rounded-md border border-slate-300 px-2 py-1 text-xs focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                        @foreach ($sortLabels as $key => $label)
                            <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-5 py-2 font-medium">Name</th>
                            <th class="px-5 py-2 font-medium">Phone</th>
                            <th class="px-5 py-2 font-medium">Gender</th>
                            <th class="px-5 py-2 font-medium text-right">Orders</th>
                            <th class="px-5 py-2 font-medium text-right">LTV (Purchased)</th>
                            <th class="px-5 py-2 font-medium">Last Order</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customers as $customer)
                            <tr class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-3 font-medium text-slate-700">{{ $customer['name'] ?? '—' }}</td>
                                <td class="px-5 py-3 text-slate-500">{{ implode(', ', $customer['phone_numbers'] ?? []) ?: '—' }}</td>
                                <td class="px-5 py-3 text-slate-500">{{ ucfirst($customer['gender'] ?? '') ?: '—' }}</td>
                                <td class="px-5 py-3 text-right text-slate-500">{{ $customer['order_count'] ?? 0 }}</td>
                                <td class="px-5 py-3 text-right font-medium text-slate-900">₱{{ number_format($customer['purchased_amount'] ?? 0) }}</td>
                                <td class="px-5 py-3 text-slate-500">{{ !empty($customer['last_order_at']) ? \Illuminate\Support\Carbon::parse($customer['last_order_at'])->format('M j, Y') : '—' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('customers.show', $customer['customer_id']) }}" class="text-xs font-medium text-teal-600 hover:text-teal-800">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-6 text-center text-slate-400">
                                    {{ $search ? 'No customers matched your search.' : 'No customers active in this period.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($totalPages > 1)
                <div class="mt-4 flex items-center justify-between text-xs text-slate-500">
                    <span>Page {{ $page }} of {{ number_format($totalPages) }}</span>
                    @php $pageParams = ['q' => $search, 'period' => $period, 'sort' => $sort, 'date' => $period === 'day' ? $selectedDate : null]; @endphp
                    <div class="flex gap-2">
                        @if ($page > 1)
                            <a href="{{ route('customers.index', array_merge($pageParams, ['page' => $page - 1])) }}"
                                class="rounded-md border border-slate-200 px-3 py-1.5 font-medium text-slate-600 hover:bg-slate-50">Previous</a>
                        @endif
                        @if ($page < $totalPages)
                            <a href="{{ route('customers.index', array_merge($pageParams, ['page' => $page + 1])) }}"
                                class="rounded-md border border-slate-200 px-3 py-1.5 font-medium text-slate-600 hover:bg-slate-50">Next</a>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    @endunless
@endsection
