@php
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $state = array_filter(['product' => $group['slug'], 'date' => $day->toDateString(), 'cra' => $craId]);
    $dayLink = fn (\Carbon\CarbonImmutable $d) => route('pc-report.index', [...$state, 'date' => $d->toDateString()]);
    $dash = '<span class="text-muted">—</span>';
    $columns = ['Total Inquiries', 'Total Engagement', '# of Orders', 'Conversion Rate', 'Amount'];
    $countInput = 'h-8 w-24 rounded-lg border border-line bg-white px-2 text-right text-sm tabular-nums placeholder:text-muted/60 focus:border-brand-400 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    // '2026-03'–'2026-08' → "Mar – Aug 2026"; across years "Nov 2025 – Feb 2026"; no end → "Jan 2026 onwards".
    $range = function (string $from, string $to): string {
        if ($from === '') {
            return 'Not set';
        }
        $start = \Carbon\CarbonImmutable::parse($from.'-01');
        if ($to === '') {
            return $start->format('M Y').' onwards';
        }
        $end = \Carbon\CarbonImmutable::parse($to.'-01');

        return match (true) {
            $from === $to => $start->format('M Y'),
            $start->year === $end->year => $start->format('M').' – '.$end->format('M Y'),
            default => $start->format('M Y').' – '.$end->format('M Y'),
        };
    };
@endphp

<x-layouts.app title="PC Report">
    <div class="space-y-4" data-pc-report data-rows-url="{{ route('pc-report.rows.update') }}" data-entries-url="{{ route('pc-report.entries.update') }}" data-date="{{ $day->toDateString() }}">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">PC Report <span class="text-sm font-normal text-muted">· Each CRA's pages per product</span></h1>

            <form method="GET" action="{{ route('pc-report.index') }}" class="flex flex-wrap items-center gap-2">
                <label>
                    <span class="sr-only">Product</span>
                    <select name="product" onchange="this.form.submit()" class="{{ $control }} font-semibold">
                        @foreach ($groups as $g)
                            <option value="{{ $g['slug'] }}" @selected($g['slug'] === $group['slug'])>{{ $g['name'] }} · {{ count($g['pages']) }} pages</option>
                        @endforeach
                    </select>
                </label>

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

                @if ($allCras)
                    <label>
                        <span class="sr-only">CRA</span>
                        <select name="cra" onchange="this.form.submit()" class="{{ $control }}">
                            <option value="">All CRAs</option>
                            @foreach ($allCras as $cra)
                                <option value="{{ $cra->id }}" @selected((int) $craId === $cra->id)>{{ $cra->displayName() }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </form>
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        <div role="note" class="rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-2 text-sm text-[#473821]">
            <span class="font-semibold">How it fills in:</span> type each row's Total Inquiries and Total Engagement for the day picked; they save when you leave the box. # of Orders and Amount come from the CRA's CVR Tracker for the day picked: their tagged orders on that page (canceled and deleted left out); a split page's Dormant and Active rows share them. Conversion Rate = # of Orders ÷ Total Engagement (a split page's two rows together).
        </div>

        @if ($ordersMissingPage)
            <div role="status" class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm text-brand-700">
                Some of this day's orders were saved before their Pancake page was, so # of Orders and Amount may be short. Fetching them again from Pancake now; reload in a few minutes.
            </div>
        @endif

        <p class="flex items-center gap-1.5 text-xs text-muted">
            @if ($canEdit)
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L19 9l-4-4L4 16z"/></svg>
                Click a Creation/Update Time to set the months each CRA's page row covers on the day picked (by when the conversations were created); each day is set on its own. + splits a page into Dormant and Active.
            @else
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                The Creation/Update Time is set by your CRA Supervisor.
            @endif
        </p>

        @forelse ($cras as $cra)
            @php($lines = $sets[$cra->id])
            <x-panel :title="$group['name'].' ('.$cra->displayName().')'" icon="table" :tinted="false" body-class="">
                <x-slot:badges>
                    <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm">{{ $day->format('D, M j') }}</span>
                </x-slot:badges>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1040px] text-sm tabular-nums">
                        <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Page</th>
                                @foreach ($columns as $column)
                                    <th class="px-4 py-3 text-right font-semibold">{{ $column }}</th>
                                @endforeach
                                <th class="px-4 py-3 text-left font-semibold">Tagging</th>
                                <th class="px-4 py-3 text-left font-semibold">Creation/Update Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($lines as $line)
                                @php($name = $line['page'].($line['category'] ? ' - '.$line['category'] : ''))
                                <tr>
                                    <td class="px-4 py-2.5 font-medium whitespace-nowrap">
                                        <span class="inline-flex items-center gap-2">
                                            @if ($canEdit)
                                                @if ($line['category'] === '')
                                                    <button type="button" data-pc-split data-cra="{{ $cra->id }}" data-page="{{ $line['page'] }}" aria-label="Split {{ $line['page'] }} into Dormant and Active" title="Split into Dormant and Active"
                                                            class="grid size-5 shrink-0 place-items-center rounded-md border border-line text-sm leading-none text-muted transition hover:border-brand-400 hover:text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none">+</button>
                                                @elseif ($line['category'] === \App\Models\PcReportRow::DORMANT)
                                                    <button type="button" data-pc-merge data-cra="{{ $cra->id }}" data-page="{{ $line['page'] }}" aria-label="Put {{ $line['page'] }} back into one row" title="Put Dormant and Active back together"
                                                            class="grid size-5 shrink-0 place-items-center rounded-md border border-line text-sm leading-none text-muted transition hover:border-brand-400 hover:text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none">−</button>
                                                @else
                                                    <span class="size-5 shrink-0"></span>
                                                @endif
                                            @endif
                                            <span>PC - {{ $name }}</span>
                                        </span>
                                    </td>
                                    @foreach (['inquiries' => 'Total Inquiries', 'engagements' => 'Total Engagement'] as $field => $label)
                                        <td class="px-4 py-2 text-right">
                                            <input type="number" min="0" step="1" inputmode="numeric" data-pc-count="{{ $field }}" data-cra="{{ $cra->id }}" data-page="{{ $line['page'] }}" data-category="{{ $line['category'] }}"
                                                   value="{{ $line[$field] }}" placeholder="—" aria-label="{{ $label }} for {{ $cra->displayName() }}, {{ $name }}" class="{{ $countInput }}">
                                        </td>
                                    @endforeach
                                    @if ($line['orders_rows'] > 0)
                                        <td @if ($line['orders_rows'] > 1) rowspan="{{ $line['orders_rows'] }}" @endif class="px-4 py-2.5 text-right align-middle" data-pc-orders="{{ $line['orders'] }}" data-page="{{ $line['page'] }}">{!! $line['orders'] === null ? $dash : number_format($line['orders']) !!}</td>
                                        {{-- # of Orders ÷ Total Engagement; a split page's two rows together --}}
                                        <td @if ($line['orders_rows'] > 1) rowspan="{{ $line['orders_rows'] }}" @endif class="px-4 py-2.5 text-right align-middle font-semibold" data-pc-conversion data-page="{{ $line['page'] }}">{!! $line['conversion'] === null ? $dash : number_format($line['conversion'] * 100, 2).'%' !!}</td>
                                        {{-- From the CVR Tracker, like # of Orders --}}
                                        <td @if ($line['orders_rows'] > 1) rowspan="{{ $line['orders_rows'] }}" @endif class="px-4 py-2.5 text-right align-middle" data-pc-amount data-page="{{ $line['page'] }}">{!! $line['amount'] === null ? $dash : '₱'.number_format($line['amount'], 2) !!}</td>
                                    @endif
                                    <td class="px-4 py-2"><input type="text" data-pc-tagging data-cra="{{ $cra->id }}" data-page="{{ $line['page'] }}" data-category="{{ $line['category'] }}" value="{{ $line['tagging'] }}" maxlength="50" placeholder="e.g. SW1, SJJ" autocomplete="off" spellcheck="false" aria-label="Tagging for {{ $cra->displayName() }}, {{ $name }}" class="h-8 w-32 rounded-lg border border-line bg-white px-2 text-xs font-semibold uppercase placeholder:font-normal placeholder:normal-case focus:border-brand-400 focus:ring-2 focus:ring-brand-200 focus:outline-none"></td>
                                    <td class="px-4 py-2.5">
                                        @php($rangeLabel = $range($line['from'], $line['to']))
                                        @if ($canEdit)
                                            <button type="button" data-pc-window data-cra-id="{{ $cra->id }}" data-cra="{{ $cra->displayName() }}" data-page="{{ $line['page'] }}" data-category="{{ $line['category'] }}" data-from="{{ $line['from'] }}" data-to="{{ $line['to'] }}" @if ($line['from']) data-set @endif
                                                    aria-label="Creation/Update Time for {{ $cra->displayName() }}, {{ $name }}"
                                                    class="inline-flex items-center gap-1.5 rounded-full bg-[#fff6d6] px-2.5 py-1 text-xs font-semibold whitespace-nowrap text-[#7a5c00] transition hover:ring-2 hover:ring-brand-200 focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none data-[set]:bg-brand-50 data-[set]:text-brand-700">
                                                <span data-pc-window-label>{{ $rangeLabel }}</span>
                                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L19 9l-4-4L4 16z"/></svg>
                                            </button>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-canvas px-2.5 py-1 text-xs font-semibold whitespace-nowrap text-muted" title="Set by your CRA Supervisor">
                                                {{ $rangeLabel }}
                                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            @php($counted = collect($lines)->pluck('inquiries')->filter(fn ($n) => $n !== null))
                            @php($engaged = collect($lines)->pluck('engagements')->filter(fn ($n) => $n !== null))
                            @php($ordered = collect($lines)->pluck('orders')->filter(fn ($n) => $n !== null))
                            @php($amounts = collect($lines)->pluck('amount')->filter(fn ($n) => $n !== null))
                            <tr class="border-t-2 border-line bg-brand-50/50 font-bold" data-pc-totals>
                                <td class="px-4 py-2.5">TOTAL</td>
                                <td class="px-4 py-2.5 text-right" data-pc-total="inquiries">{!! $counted->isEmpty() ? $dash : number_format($counted->sum()) !!}</td>
                                <td class="px-4 py-2.5 text-right" data-pc-total="engagements">{!! $engaged->isEmpty() ? $dash : number_format($engaged->sum()) !!}</td>
                                <td class="px-4 py-2.5 text-right" data-pc-total="orders">{!! $ordered->isEmpty() ? $dash : number_format($ordered->sum()) !!}</td>
                                <td class="px-4 py-2.5 text-right" data-pc-total="conversion">{!! $ordered->isEmpty() || $engaged->sum() == 0 ? $dash : number_format($ordered->sum() / $engaged->sum() * 100, 2).'%' !!}</td>
                                <td class="px-4 py-2.5 text-right" data-pc-total="amount">{!! $amounts->isEmpty() ? $dash : '₱'.number_format($amounts->sum(), 2) !!}</td>
                                <td class="px-4 py-2.5"></td>
                                <td class="px-4 py-2.5"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @empty
            <p class="rounded-xl bg-white px-4 py-10 text-center text-sm text-muted shadow-sm">No active CRAs to show.</p>
        @endforelse
    </div>

    @if ($canEdit)
        {{-- Creation/Update Time: the month range a CRA's page pulls inquiries and engagement from (preview: kept on this screen only) --}}
        <dialog id="pc-window-dialog" data-url="{{ route('pc-report.rows.update') }}" data-split-url="{{ route('pc-report.split') }}" data-year="{{ $today->year }}" aria-labelledby="pc-window-title" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
            <div class="space-y-4 p-5">
                <div>
                    <h2 id="pc-window-title" class="text-base font-bold">Creation/Update Time</h2>
                    <p class="text-sm text-muted" data-pc-window-for></p>
                </div>
                <p class="text-xs text-muted">For {{ $day->format('M j, Y') }} only: the row covers the page's conversations created from the first month to the end of the last. Click the first month, then the last; the range can cross years.</p>

                <div class="flex items-center justify-between">
                    <button type="button" data-pc-year="-1" class="grid size-8 place-items-center rounded-lg border border-line hover:border-brand-400 hover:text-brand-600" aria-label="Previous year">‹</button>
                    <span class="font-bold" data-pc-year-label></span>
                    <button type="button" data-pc-year="1" class="grid size-8 place-items-center rounded-lg border border-line hover:border-brand-400 hover:text-brand-600" aria-label="Next year">›</button>
                </div>

                <div class="grid grid-cols-4 gap-1.5">
                    @foreach (['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $i => $month)
                        <button type="button" data-pc-month="{{ $i + 1 }}" aria-pressed="false"
                                class="h-9 rounded-lg border border-line text-sm font-medium transition hover:border-brand-400 data-[range]:border-brand-200 data-[range]:bg-brand-50 data-[range]:text-brand-700 aria-pressed:border-brand-600 aria-pressed:bg-brand-600 aria-pressed:text-white">{{ $month }}</button>
                    @endforeach
                </div>

                <p class="text-sm"><span class="font-semibold">Range:</span> <span data-pc-window-summary>None</span></p>

                <div class="flex items-center justify-between gap-2">
                    <button type="button" data-pc-clear class="h-10 rounded-lg border border-line px-3 font-medium hover:border-brand-400 hover:text-brand-600">Clear</button>
                    <div class="flex gap-2">
                        <button type="button" data-pc-cancel class="h-10 rounded-lg border border-line px-3 font-medium hover:border-brand-400 hover:text-brand-600">Cancel</button>
                        <button type="button" data-pc-save class="h-10 rounded-lg bg-brand-600 px-4 font-semibold text-white shadow-sm hover:bg-brand-700">Save</button>
                    </div>
                </div>
            </div>
        </dialog>
    @endif
</x-layouts.app>
