@use('Carbon\CarbonImmutable')
@php
    $link = fn (CarbonImmutable $m, ?bool $edit = null) => route('actual-income.index', array_filter(['month' => $m->format('Y-m'), 'edit' => ($edit ?? $canEdit) ? 1 : null]));
@endphp

<x-layouts.app title="Actual Income">
    <div class="space-y-4" data-actual-income data-url="{{ route('actual-income.update') }}" data-cards-url="{{ $link($month) }}" data-month="{{ $month->format('Y-m') }}">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Actual Income <span class="text-sm font-normal text-muted">· The month's CRD and CRA income statements</span></h1>

            <div class="flex items-center gap-2">
                <form method="GET" action="{{ route('actual-income.index') }}" class="flex h-9 items-center rounded-lg border border-line bg-white text-sm">
                    <a href="{{ $link($month->subMonth()) }}" class="grid h-full w-8 place-items-center rounded-l-lg text-muted hover:text-brand-600" aria-label="Previous month">‹</a>
                    @if ($canEdit)
                        <input type="hidden" name="edit" value="1">
                    @endif
                    <input type="month" name="month" value="{{ $month->format('Y-m') }}" required onchange="this.value && this.form.submit()"
                           aria-label="Month" class="h-full border-0 bg-transparent px-1 text-sm font-semibold text-ink focus:ring-2 focus:ring-brand-200 focus:outline-none">
                    <a href="{{ $link($month->addMonth()) }}" class="grid h-full w-8 place-items-center rounded-r-lg text-muted hover:text-brand-600" aria-label="Next month">›</a>
                </form>
                @if ($canManage)
                    @if ($canEdit)
                        <a href="{{ $link($month, false) }}" class="inline-flex h-9 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Done</a>
                    @else
                        <a href="{{ $link($month, true) }}" class="inline-flex h-9 items-center rounded-lg border border-line bg-white px-4 text-sm font-semibold text-brand-700 hover:border-brand-400">Edit</a>
                    @endif
                @endif
            </div>
        </header>

        <div role="note" class="rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-2 text-sm text-[#473821]">
            @if ($data['fromSheet'])
                <span class="font-semibold">From the CRD Sales Report sheet:</span> {{ $month->format('F Y') }} shows the sheet's Actual Income as it is, every line.
                @if ($canEdit)
                    Type over a line in its box; it saves when you leave the box, and that card's AOV, Unfulfilled Sales, Gross Profit, totals and NET INCOME move with it.
                @elseif ($canManage)
                    Click Edit to correct a line.
                @endif
            @else
            <span class="font-semibold">How it works:</span>
            @if ($canEdit)
                type the amounts in the boxes; each saves when you leave the box and the cards update. Click Done when finished.
            @elseif ($canManage)
                click Edit to type the amounts.
            @else
                the amounts are typed in by the CRA Supervisor or Team Lead.
            @endif
            On a CRA card: Unfulfilled Sales = Gross − Cancelled − Fulfilled − Returns/Returning − Delivered; Conversion Rate = Orders ÷ Target Leads; AOV = Gross Sales ÷ Orders;
            Shipping Fee = Orders × ₱75, COD Fee = Delivered × 2%, Fulfillment Fee = Orders × ₱25; Product Research = the CRD's ÷ 3;
            Salaries, 13th Month, SIL and Government Benefits are the CRA's 1M Monthly MNI figures.
            Gross Profit = Delivered − Tax Allocation − Product Cost. NET INCOME = Gross Profit − Total Selling Costs − Total Operating Costs.
            @endif
        </div>


        <div data-actual-income-cards>
            @include('actual-income._cards')
        </div>
    </div>
</x-layouts.app>
