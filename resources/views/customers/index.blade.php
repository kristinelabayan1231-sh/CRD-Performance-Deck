@extends('layouts.app')

@section('title', 'Customers — ' . config('app.name'))

@php
    $sortLabels = ['ltv_desc' => 'Highest LTV', 'ltv_asc' => 'Lowest LTV', 'orders_desc' => 'Most Orders', 'period_desc' => 'Period Spend'];
    $periodLabels = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly'];
    $periodPrefixes = ['day' => '', 'week' => 'Week of ', 'month' => 'Month of ', 'year' => 'Year '];
    $sellerParams = $selectedSellers ? ['seller' => $selectedSellers] : [];
@endphp

@section('content')
    <x-page-header title="Customers" subtitle="CRD customer base — customers handled by CRD sellers, with purchase history and lifetime value, sourced from Pancake POS." />

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
                <a href="{{ route('customers.index', ($period === 'day' ? ['period' => $period, 'date' => $selectedDate] : ['period' => $period]) + $sellerParams) }}" class="text-xs font-medium text-slate-400 hover:text-slate-600">Clear</a>
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
                            <a href="{{ route('customers.index', ($key === 'day' ? ['period' => 'day', 'date' => $selectedDate] : ['period' => $key]) + $sellerParams) }}"
                                class="rounded-md px-3 py-1 text-xs font-medium transition {{ $period === $key ? 'bg-slate-900 text-white' : 'text-slate-500' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                    @if ($period === 'day')
                        <form method="GET" action="{{ route('customers.index') }}">
                            <input type="hidden" name="period" value="day">
                            @foreach ($selectedSellers as $s)
                                <input type="hidden" name="seller[]" value="{{ $s }}">
                            @endforeach
                            <input type="date" name="date" value="{{ $selectedDate }}" max="{{ now()->toDateString() }}" onchange="this.form.submit()"
                                class="rounded-md border border-slate-300 px-2 py-1.5 text-xs focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                        </form>
                    @endif

                    @if ($sellers)
                        <form method="GET" action="{{ route('customers.index') }}" class="flex items-center gap-2">
                            <input type="hidden" name="period" value="{{ $period }}">
                            @if ($period === 'day')
                                <input type="hidden" name="date" value="{{ $selectedDate }}">
                            @endif

                            <details class="relative" data-seller-dropdown>
                                <summary class="flex w-52 cursor-pointer list-none items-center justify-between rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
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
                                class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50">
                                Apply
                            </button>

                            @if ($selectedSellers)
                                <a href="{{ route('customers.index', $period === 'day' ? ['period' => 'day', 'date' => $selectedDate] : ['period' => $period]) }}"
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
            <div class="rounded-xl bg-white p-10 text-center shadow-sm">
                @if (session('status'))
                    <p class="mb-3 text-sm font-medium text-teal-700">{{ session('status') }}</p>
                @endif
                <p class="text-sm text-slate-400">
                    {{ $periodLabels[$period] }} data for {{ $periodLabel }} hasn't been computed yet — it refreshes hourly in the background.
                </p>
                @if ($building ?? false)
                    <p class="mt-4 inline-flex items-center gap-2 text-sm font-medium text-slate-600">
                        <span class="h-2 w-2 animate-pulse rounded-full bg-amber-400"></span>
                        Building now — the weekly view fills in first (a minute or two). Refresh to check.
                    </p>
                @else
                    <form method="POST" action="{{ route('customers.build-dashboard') }}" class="mt-4">
                        @csrf
                        <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
                            Build now
                        </button>
                    </form>
                @endif
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
                        <span class="text-xs font-medium uppercase tracking-wide">Gross Sales</span>
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

            {{-- Per-CRD performance: sales-share donut + scorecard table.
                 Slice colors are keyed to the full CRD roster (not display
                 rank) so each seller keeps its hue across filters/periods. --}}
            @if (count($dashboard['sellerBreakdown']))
                @php
                    // Fixed-order categorical palette (validated: node scripts/validate_palette.js).
                    $sellerPalette = ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#e34948', '#e87ba4', '#eb6834'];
                    $sellerColors = collect($sellers)->values()->mapWithKeys(fn ($name, $i) => [$name => $sellerPalette[$i % count($sellerPalette)]])->all();
                    $donutItems = $dashboard['sellerBreakdown']->map(fn ($s) => [
                        'label' => $s['name'],
                        'sales_value' => $s['gross_sales'],
                        'color' => $sellerColors[$s['name']] ?? '#94a3b8',
                    ])->all();
                    $repeatTotal = $dashboard['customers']->filter(fn ($c) => ($c['order_count'] ?? 0) > 1)->count();
                @endphp
                <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <section class="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div class="border-b border-slate-200 bg-slate-50 px-5 py-3">
                            <h3 class="text-sm font-semibold text-slate-900">Gross Sales Share</h3>
                        </div>
                        <x-donut-chart :items="$donutItems" :total="$dashboard['revenueTotal']" />
                    </section>

                    <section class="overflow-hidden rounded-xl bg-white shadow-sm lg:col-span-2">
                        <div class="border-b border-slate-200 bg-slate-50 px-5 py-3">
                            <h3 class="text-sm font-semibold text-slate-900">CRD Scorecard</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                        <th class="px-5 py-2 font-medium">CRD</th>
                                        <th class="px-4 py-2 font-medium text-right">Gross Sales</th>
                                        <th class="px-4 py-2 font-medium text-right">Share</th>
                                        <th class="px-4 py-2 font-medium text-right">Orders</th>
                                        <th class="px-4 py-2 font-medium text-right">Customers</th>
                                        <th class="px-4 py-2 font-medium text-right">Avg Order</th>
                                        <th class="px-5 py-2 font-medium text-right">Repeat Buyers</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($dashboard['sellerBreakdown'] as $s)
                                        <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                                            <td class="px-5 py-2.5">
                                                <span class="flex items-center gap-2">
                                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $sellerColors[$s['name']] ?? '#94a3b8' }}"></span>
                                                    <span class="font-medium text-slate-700">{{ $s['name'] }}</span>
                                                </span>
                                            </td>
                                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">₱{{ number_format($s['gross_sales']) }}</td>
                                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ $dashboard['revenueTotal'] > 0 ? round($s['gross_sales'] / $dashboard['revenueTotal'] * 100) : 0 }}%</td>
                                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format($s['order_count']) }}</td>
                                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format($s['customer_count']) }}</td>
                                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">₱{{ number_format($s['avg_order']) }}</td>
                                            <td class="px-5 py-2.5 text-right tabular-nums text-slate-700">
                                                {{ number_format($s['repeat_customers']) }}
                                                <span class="text-xs text-slate-400">({{ $s['customer_count'] > 0 ? round($s['repeat_customers'] / $s['customer_count'] * 100) : 0 }}%)</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="bg-slate-50 text-xs font-semibold text-slate-700">
                                        <td class="px-5 py-2">All CRDs</td>
                                        <td class="px-4 py-2 text-right tabular-nums">₱{{ number_format($dashboard['revenueTotal']) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">100%</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($dashboard['orderCount']) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums" title="Distinct customers — one customer can buy from several CRDs">{{ number_format($dashboard['customerCount']) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">₱{{ $dashboard['orderCount'] > 0 ? number_format($dashboard['revenueTotal'] / $dashboard['orderCount']) : 0 }}</td>
                                        <td class="px-5 py-2 text-right tabular-nums">
                                            {{ number_format($repeatTotal) }}
                                            <span class="font-normal text-slate-400">({{ $dashboard['customerCount'] > 0 ? round($repeatTotal / $dashboard['customerCount'] * 100) : 0 }}%)</span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </section>
                </div>
            @endif

            <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Customers per page --}}
                <div class="rounded-xl bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customers per Page</p>
                    @php
                        $pagesAll = collect($dashboard['customersPerPage']);
                        $topPages = $pagesAll->take(8);
                        $restPages = $pagesAll->slice(8);
                        $maxCount = $topPages->first()['count'] ?? 1;
                    @endphp
                    <div class="mt-3 space-y-2.5">
                        @forelse ($topPages as $p)
                            <div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="truncate text-slate-600">{{ $p['page_name'] }}</span>
                                    <span class="ml-2 shrink-0 font-medium tabular-nums text-slate-900">{{ number_format($p['count']) }}</span>
                                </div>
                                <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-teal-400" style="width: {{ $maxCount > 0 ? round(($p['count'] / $maxCount) * 100) : 0 }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-400">No activity in this period.</p>
                        @endforelse
                        @if ($restPages->isNotEmpty())
                            <p class="pt-1 text-xs text-slate-400">
                                + {{ $restPages->count() }} more page{{ $restPages->count() === 1 ? '' : 's' }} &middot; {{ number_format($restPages->sum('count')) }} customers
                            </p>
                        @endif
                    </div>
                </div>

                {{-- Top products by revenue --}}
                <div class="rounded-xl bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Top Products by Revenue</p>
                    @php $maxRevenue = max(1, $dashboard['topProducts']->max('revenue') ?? 1); @endphp
                    <div class="mt-3 space-y-3.5">
                        @forelse ($dashboard['topProducts'] as $p)
                            <div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="truncate font-medium text-slate-700">{{ $p['name'] }}</span>
                                    <span class="ml-2 shrink-0 font-semibold tabular-nums text-slate-900">₱{{ number_format($p['revenue']) }}</span>
                                </div>
                                <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-teal-400" style="width: {{ round(($p['revenue'] / $maxRevenue) * 100) }}%"></div>
                                </div>
                                <p class="mt-1 text-xs text-slate-400">
                                    {{ number_format($p['quantity']) }} sold
                                    @if ($p['top_customer'])
                                        &middot; Top buyer: <span class="font-medium text-slate-600">{{ $p['top_customer'] }}</span> — ₱{{ number_format($p['top_customer_value'] ?? 0) }}
                                    @endif
                                </p>
                            </div>
                        @empty
                            <p class="text-sm text-slate-400">No sales in this period.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Top spenders this period --}}
                <div class="rounded-xl bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Top Spenders This Period</p>
                    <div class="mt-3 space-y-3">
                        @forelse ($dashboard['topCustomers'] as $i => $c)
                            <a href="{{ route('customers.show', $c['customer_id']) }}" class="flex items-center gap-3 hover:opacity-80">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-[11px] font-bold text-white">{{ $i + 1 }}</span>
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-100 text-xs font-semibold text-teal-700">
                                    {{ strtoupper(substr($c['name'] ?? '?', 0, 1)) }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-slate-800">{{ $c['name'] ?? '—' }}</span>
                                    <span class="block text-xs text-slate-400">{{ $c['period_orders'] ?? 0 }} order{{ ($c['period_orders'] ?? 0) === 1 ? '' : 's' }} this period &middot; ₱{{ number_format($c['purchased_amount'] ?? 0) }} lifetime</span>
                                </span>
                                <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">₱{{ number_format($c['period_revenue'] ?? 0) }}</span>
                            </a>
                        @empty
                            <p class="text-sm text-slate-400">No purchases in this period.</p>
                        @endforelse
                    </div>
                </div>

                {{-- Auto-generated observations from the data above --}}
                <div class="rounded-xl bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Insights</p>
                    <ul class="mt-3 space-y-3">
                        @forelse ($insights as $insight)
                            <li class="flex items-start gap-2.5 text-sm text-slate-600">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 shrink-0 text-teal-500"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>
                                <span>{{ $insight }}</span>
                            </li>
                        @empty
                            <li class="text-sm text-slate-400">Not enough activity in this period to draw conclusions.</li>
                        @endforelse
                    </ul>
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
                    @foreach ($selectedSellers as $s)
                        <input type="hidden" name="seller[]" value="{{ $s }}">
                    @endforeach
                    <label for="sort" class="text-slate-400">Sort by</label>
                    <select id="sort" name="sort" onchange="this.form.submit()"
                        class="rounded-md border border-slate-300 px-2 py-1 text-xs focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                        @foreach ($sortLabels as $key => $label)
                            @continue($key === 'period_desc' && $mode === 'search')
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
                            @if ($mode === 'range')
                                <th class="px-5 py-2 font-medium">CRD</th>
                                <th class="px-5 py-2 font-medium text-right">Period Spend</th>
                            @endif
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
                                @if ($mode === 'range')
                                    <td class="px-5 py-3 text-slate-500">{{ implode(', ', $customer['crd_sellers'] ?? []) ?: '—' }}</td>
                                    <td class="px-5 py-3 text-right text-slate-500">₱{{ number_format($customer['period_revenue'] ?? 0) }}</td>
                                @endif
                                <td class="px-5 py-3 text-right text-slate-500">{{ $customer['order_count'] ?? 0 }}</td>
                                <td class="px-5 py-3 text-right font-medium text-slate-900">₱{{ number_format($customer['purchased_amount'] ?? 0) }}</td>
                                <td class="px-5 py-3 text-slate-500">{{ !empty($customer['last_order_at']) ? \Illuminate\Support\Carbon::parse($customer['last_order_at'])->format('M j, Y') : '—' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('customers.show', $customer['customer_id']) }}" class="text-xs font-medium text-teal-600 hover:text-teal-800">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $mode === 'range' ? 9 : 7 }}" class="px-5 py-6 text-center text-slate-400">
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
                    @php $pageParams = ['q' => $search, 'period' => $period, 'sort' => $sort, 'date' => $period === 'day' ? $selectedDate : null] + $sellerParams; @endphp
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
