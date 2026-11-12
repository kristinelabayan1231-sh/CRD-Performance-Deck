@use('Carbon\CarbonImmutable')
@php
    $link = fn (CarbonImmutable $m) => route('actual-income.index', ['month' => $m->format('Y-m')]);
@endphp

<x-layouts.app title="Actual Income">
    <div class="space-y-4" data-actual-income data-url="{{ route('actual-income.update') }}" data-cards-url="{{ $link($month) }}" data-month="{{ $month->format('Y-m') }}">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Actual Income <span class="text-sm font-normal text-muted">· The month's CRD and CRA income statements</span></h1>

            <div class="flex h-9 items-center rounded-lg border border-line bg-white text-sm">
                <a href="{{ $link($month->subMonth()) }}" class="grid h-full w-8 place-items-center rounded-l-lg text-muted hover:text-brand-600" aria-label="Previous month">‹</a>
                <span class="px-2 font-semibold">{{ $month->format('F Y') }}</span>
                <a href="{{ $link($month->addMonth()) }}" class="grid h-full w-8 place-items-center rounded-r-lg text-muted hover:text-brand-600" aria-label="Next month">›</a>
            </div>
        </header>

        <div role="note" class="rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-2 text-sm text-[#473821]">
            <span class="font-semibold">How it works:</span>
            @if ($canEdit)
                type the amounts in the boxes; each saves when you leave the box and the cards update.
            @else
                the amounts are typed in by the CRA Supervisor or Team Lead.
            @endif
            On a CRA card: Unfulfilled Sales = Gross − Cancelled − Fulfilled − Returns/Returning − Delivered; Conversion Rate = Orders ÷ Target Leads; AOV = Gross Sales ÷ Orders;
            Shipping Fee = Orders × ₱75, COD Fee = Delivered × 2%, Fulfillment Fee = Orders × ₱25; Product Research = the CRD's ÷ 3;
            Salaries, 13th Month, SIL and Government Benefits are the CRA's 1M Monthly MNI figures.
            Gross Profit = Delivered − Tax Allocation − Product Cost. NET INCOME = Gross Profit − Total Selling Costs − Total Operating Costs.
        </div>


        <div data-actual-income-cards>
            @include('actual-income._cards')
        </div>
    </div>
</x-layouts.app>
