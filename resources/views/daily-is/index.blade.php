@php
    $dayLink = fn (\Carbon\CarbonImmutable $d) => route('daily-is.index', array_filter(['date' => $d->toDateString(), 'cra' => $craId]));
    $dayLabel = $day->format('M j');
    // Each row: the department's or CRA's own card stays put; their product cards scroll sideways.
    $rows = collect($showDepartment ? [['label' => 'Customer Retention Department', 'hint' => 'every CRA added up', 'title' => 'Customer Retention Department', 'variant' => 'department', 'statement' => $data['department'], 'by' => 'All CRAs']] : [])
        ->concat($cras->map(fn ($cra) => ['label' => $cra->displayName(), 'hint' => null, 'title' => $cra->displayName(), 'variant' => 'cra', 'statement' => $data['cras'][$cra->id], 'by' => $cra->displayName()]));
@endphp

<x-layouts.app title="Daily I.S.">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Daily I.S. <span class="text-sm font-normal text-muted">· Income statement per CRA and product</span></h1>

            <form method="GET" action="{{ route('daily-is.index') }}" class="flex flex-wrap items-center gap-2">
                @if ($allCras)
                    <label>
                        <span class="sr-only">CRA</span>
                        <select name="cra" onchange="this.form.submit()" class="h-9 rounded-lg border border-line bg-white px-2.5 text-sm font-semibold text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                            <option value="">All CRAs</option>
                            @foreach ($allCras as $c)
                                <option value="{{ $c->id }}" @selected($craId === $c->id)>{{ $c->displayName() }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <div class="flex h-9 items-center rounded-lg border border-line bg-white text-sm">
                <a href="{{ $dayLink($day->subDay()) }}" class="grid h-full w-8 place-items-center rounded-l-lg text-muted hover:text-brand-600" aria-label="Previous day">‹</a>
                <label>
                    <span class="sr-only">Day</span>
                    <input type="date" name="date" value="{{ $day->toDateString() }}" max="{{ $today->toDateString() }}" onchange="this.form.submit()"
                           class="h-8 border-0 bg-transparent px-1 text-sm font-semibold focus:ring-0 focus:outline-none">
                </label>
                @if ($day->lessThan($today))
                    <a href="{{ $dayLink($day->addDay()) }}" class="grid h-full w-8 place-items-center rounded-r-lg text-muted hover:text-brand-600" aria-label="Next day">›</a>
                @else
                    <span class="grid h-full w-8 place-items-center text-muted/40" aria-hidden="true">›</span>
                @endif
                </div>
            </form>
        </header>

        <div role="note" class="rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-2 text-sm text-[#473821]">
            <span class="font-semibold">How it fills in:</span> leads and engagements are the PC Report's Total Inquiries and Total Engagement on each product's pages; orders are the CRA's Pancake orders on those pages that day (canceled and deleted left out), and Gross Sales those orders' sales with the canceled ones too.
            Actual Cancelled = the orders that are Canceled or Waiting for goods now, so it changes when an order is resolved.
            Projected Returns = Gross Sales × the month's % Projected Returns; Projected Delivered = Gross Sales − Actual Cancelled − Projected Returns.
            Product Cost is each item's cost in Shecom × qty (₱0 when Shecom has no cost). Shipping Fee = orders × ₱75, COD Fee = Projected Delivered × 2.24%, Fulfillment Fee = orders × ₱25; Advertising Cost, Ads VAT and VAT on COD Fee are 0.
            Tax Allocation and Salaries to HMO Expense = the CRA's 1M Monthly MNI ÷ 24, then ÷ the day's {{ count($data['products']) }} {{ Str::plural('product', count($data['products'])) }} on a product card.
        </div>

        {{-- % Projected Returns: one figure for the month, every product card --}}
        <section class="flex flex-wrap items-center gap-3 rounded-xl bg-white px-4 py-3 text-sm shadow-sm" aria-labelledby="returns-title">
            <h2 id="returns-title" class="font-semibold">% Projected Returns · {{ $day->format('F Y') }}</h2>
            @if ($canSetReturns)
                <form method="POST" action="{{ route('daily-is.returns.update') }}" class="flex items-center gap-2">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="month" value="{{ $day->format('Y-m') }}">
                    <input type="hidden" name="date" value="{{ $day->toDateString() }}">
                    @if ($craId)
                        <input type="hidden" name="cra" value="{{ $craId }}">
                    @endif
                    <label class="flex items-center gap-1.5">
                        <span class="sr-only">% Projected Returns for {{ $day->format('F Y') }}</span>
                        <input type="number" id="returns_pct" name="returns_pct" min="0" max="100" step="0.01" inputmode="decimal" value="{{ $data['returnsPct'] }}" placeholder="0"
                               class="h-9 w-24 rounded-lg border border-line bg-white px-2.5 text-right tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                        <span class="text-muted">%</span>
                    </label>
                    <button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white hover:bg-brand-700">Save</button>
                </form>
                @error('returns_pct')
                    <p class="text-xs text-coral-700">{{ $message }}</p>
                @enderror
                <p class="text-xs text-muted">Applies to every product card this month.</p>
            @else
                <span class="font-bold tabular-nums">{{ $data['returnsPct'] === null ? 'Not set (0%)' : rtrim(rtrim(number_format($data['returnsPct'], 2), '0'), '.').'%' }}</span>
                <span class="text-xs text-muted">Set by your CRA Supervisor.</span>
            @endif
        </section>

        @if ($data['missingPage'])
            <div role="status" class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm text-brand-700">
                Some of this day's orders were saved before their Pancake page was, so orders and Gross Sales may be short until they're fetched again (open the PC Report for this day to fetch them).
            </div>
        @endif

        @if ($data['products'] === [])
            <p class="rounded-xl bg-white px-4 py-10 text-center text-sm text-muted shadow-sm">No product groups yet. Add them in Settings → Pancake Pages → Product groups.</p>
        @endif

        @forelse ($rows as $row)
            <section class="space-y-2" aria-label="{{ $row['label'] }}">
                <h2 class="flex items-center gap-2 text-sm font-bold">
                    {{ $row['label'] }}
                    @if ($row['hint'])
                        <span class="font-normal text-muted">· {{ $row['hint'] }}</span>
                    @endif
                </h2>
                <div class="grid items-start gap-3 md:grid-cols-[310px_minmax(0,1fr)]">
                    @include('daily-is._card', ['title' => $row['title'], 'sub' => $dayLabel, 'variant' => $row['variant'], 'f' => $row['statement']['total']])
                    <div class="x-scrollbar flex snap-x gap-3 overflow-x-auto pb-2" tabindex="0" aria-label="{{ $row['label'] }}'s products, scroll sideways">
                        @foreach ($data['products'] as $product)
                            <div class="w-[19.375rem] shrink-0 snap-start">
                                @include('daily-is._card', ['title' => $product['name'], 'deactivated' => $product['deactivated'], 'sub' => $row['by'], 'variant' => 'product', 'f' => $row['statement']['products'][$product['slug']]])
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @empty
            <p class="rounded-xl bg-white px-4 py-10 text-center text-sm text-muted shadow-sm">No active CRAs to show.</p>
        @endforelse
    </div>
</x-layouts.app>
