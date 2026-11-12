@php
    $link = fn (int $y) => route('cra-gross-sales.index', ['year' => $y]);
    $name = 'sticky left-0 z-10 min-w-48 border-r border-line bg-white px-4 text-left';
    $yearCell = 'bg-brand-50 font-bold';
@endphp

<x-layouts.app title="CRA Gross Sales & IBSC">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">CRA Gross Sales &amp; IBSC <span class="text-sm font-normal text-muted">· Gross Sales and Net Income per month</span></h1>

            <div class="flex h-9 items-center rounded-lg border border-line bg-white text-sm">
                <a href="{{ $link($year - 1) }}" class="grid h-full w-8 place-items-center rounded-l-lg text-muted hover:text-brand-600" aria-label="Previous year">‹</a>
                <span class="px-2 font-semibold">{{ $year }}</span>
                <a href="{{ $link($year + 1) }}" class="grid h-full w-8 place-items-center rounded-r-lg text-muted hover:text-brand-600" aria-label="Next year">›</a>
            </div>
        </header>

        <div role="note" class="rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-2 text-sm text-[#473821]">
            <span class="font-semibold">How it fills in:</span> each month is the CRA's Gross Sales and NET INCOME on their Actual Income card for that month.
            A month shows — until something is typed on the CRA's Actual Income card. TOTAL adds up the CRAs; the {{ $year }} columns add up the months.
        </div>

        <ul class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted" aria-label="Colour key">
            <li class="flex items-center gap-1.5"><span aria-hidden="true" class="size-2.5 rounded-full bg-sky-700"></span><span><span class="font-bold text-sky-700">Blue</span>: the month's top seller (most Gross Sales)</span></li>
            <li class="flex items-center gap-1.5"><span aria-hidden="true" class="size-2.5 rounded-full bg-coral-700"></span><span><span class="font-bold text-coral-700">Red</span>: Net Income below the ₱{{ number_format($goal) }} CRA Scorecard (Settings → Sales Goals)</span></li>
        </ul>

        <div class="overflow-hidden rounded-2xl bg-white shadow-sm">
            <div class="x-scrollbar overflow-x-auto">
                <table class="min-w-full border-separate border-spacing-0 text-[0.8125rem] tabular-nums">
                    <thead class="text-[0.6875rem] tracking-wide text-muted uppercase">
                        <tr>
                            <th rowspan="2" class="{{ $name }} border-b border-line py-2.5 font-bold">CRA name</th>
                            @foreach ($data['months'] as $month)
                                <th colspan="2" class="border-b border-l border-line px-3 pt-2.5 pb-1 text-center font-bold text-ink">{{ $month->format('M') }}</th>
                            @endforeach
                            <th colspan="2" class="border-b border-l-2 border-line border-l-brand-200 bg-brand-50 px-3 pt-2.5 pb-1 text-center font-bold text-brand-700">{{ $year }}</th>
                        </tr>
                        <tr class="text-[0.625rem]">
                            @foreach ($data['months'] as $month)
                                <th class="border-b border-l border-line px-3 py-1.5 text-right font-semibold">Gross</th>
                                <th class="border-b border-line px-3 py-1.5 text-right font-semibold">Net</th>
                            @endforeach
                            <th class="border-b border-l-2 border-line border-l-brand-200 bg-brand-50 px-3 py-1.5 text-right font-semibold text-brand-700">Gross</th>
                            <th class="border-b border-line bg-brand-50 px-3 py-1.5 text-right font-semibold text-brand-700">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($cras as $cra)
                            @php
                                $row = $data['cras'][$cra->id];
                            @endphp
                            <tr>
                                <td class="{{ $name }} border-b border-line py-2 font-semibold whitespace-nowrap">{{ $cra->displayName() }}</td>
                                @foreach ($row['months'] as $index => $cell)
                                    @include('cra-gross-sales._cells', ['cell' => $cell, 'class' => 'border-b border-line', 'first' => 'border-l', 'top' => $data['top'][$index] === $cra->id, 'goal' => $goal])
                                @endforeach
                                @include('cra-gross-sales._cells', ['cell' => $row['year'], 'class' => 'border-b border-line '.$yearCell, 'first' => 'border-l-2 border-l-brand-200'])
                            </tr>
                        @empty
                            <tr><td colspan="27" class="px-4 py-10 text-center text-muted">No active CRAs to show.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="font-bold">
                        <tr>
                            <td class="{{ $name }} border-t-2 border-ink py-2">TOTAL</td>
                            @foreach ($data['totals'] as $cell)
                                @include('cra-gross-sales._cells', ['cell' => $cell, 'class' => 'border-t-2 border-ink', 'first' => 'border-l border-l-line'])
                            @endforeach
                            @include('cra-gross-sales._cells', ['cell' => $data['year'], 'class' => 'border-t-2 border-ink '.$yearCell, 'first' => 'border-l-2 border-l-brand-200'])
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
