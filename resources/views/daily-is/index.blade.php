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
            <span class="font-semibold">How it fills in:</span> leads and engagements are the PC Report's Total Inquiries and Total Engagement on each product's pages; orders and Gross Sales are the CRA's Pancake orders on those pages that day (canceled and deleted left out). Conversion rate = orders ÷ engagements; AOV = Gross Sales ÷ orders. The other lines are 0.00 until the MNI is built.
        </div>

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
                            <div class="w-[310px] shrink-0 snap-start">
                                @include('daily-is._card', ['title' => $product['name'], 'sub' => $row['by'], 'variant' => 'product', 'f' => $row['statement']['products'][$product['slug']]])
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
