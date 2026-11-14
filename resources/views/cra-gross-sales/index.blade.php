@php
    $link = fn (int $y, ?bool $edit = null) => route('cra-gross-sales.index', array_filter(['year' => $y, 'edit' => ($edit ?? $canEdit) ? 1 : null]));
@endphp

<x-layouts.app title="CRA Gross Sales & IBSC">
    <div class="space-y-4" data-cra-gross-sales data-url="{{ route('cra-gross-sales.update') }}" data-table-url="{{ $link($year) }}">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">CRA Gross Sales &amp; IBSC <span class="text-sm font-normal text-muted">· Gross Sales and Net Income per month</span></h1>

            <div class="flex items-center gap-2">
                <div class="flex h-9 items-center rounded-lg border border-line bg-white text-sm">
                    <a href="{{ $link($year - 1) }}" class="grid h-full w-8 place-items-center rounded-l-lg text-muted hover:text-brand-600" aria-label="Previous year">‹</a>
                    <span class="px-2 font-semibold">{{ $year }}</span>
                    <a href="{{ $link($year + 1) }}" class="grid h-full w-8 place-items-center rounded-r-lg text-muted hover:text-brand-600" aria-label="Next year">›</a>
                </div>
                @if ($canManage)
                    @if ($canEdit)
                        <a href="{{ $link($year, false) }}" class="inline-flex h-9 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Done</a>
                    @else
                        <a href="{{ $link($year, true) }}" class="inline-flex h-9 items-center rounded-lg border border-line bg-white px-4 text-sm font-semibold text-brand-700 hover:border-brand-400">Edit</a>
                    @endif
                @endif
            </div>
        </header>

        <div role="note" class="rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-2 text-sm text-[#473821]">
            <span class="font-semibold">How it fills in:</span> each month's Gross and Net are typed in by the CRA Supervisor or Team Lead
            @if ($canEdit)
                — type in the boxes; each saves when you leave the box. Empty both to clear a month. Click Done when finished.
            @elseif ($canManage)
                (click Edit).
            @else
                .
            @endif
            A month shows — until something is typed. TOTAL adds up the CRAs; the {{ $year }} columns add up the months.
            ACB = (positive + negative Net Income so far this year) × 1.4%; January–May 2026 count only months with Net Income below ₱250,000, June 2026 on count every month.
        </div>

        <ul class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted" aria-label="Colour key">
            <li class="flex items-center gap-1.5"><span aria-hidden="true" class="size-2.5 rounded-full bg-sky-700"></span><span><span class="font-bold text-sky-700">Blue</span>: the month's top seller (most Gross Sales)</span></li>
            <li class="flex items-center gap-1.5"><span aria-hidden="true" class="size-2.5 rounded-full bg-coral-700"></span><span><span class="font-bold text-coral-700">Red</span>: Net Income below the ₱{{ number_format($goal) }} CRA Scorecard (Settings → Sales Goals)</span></li>
        </ul>

        <div data-cra-gross-sales-table>
            @include('cra-gross-sales._table')
        </div>
    </div>
</x-layouts.app>
