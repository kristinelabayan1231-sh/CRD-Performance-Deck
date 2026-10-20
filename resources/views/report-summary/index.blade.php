@php
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm text-ink normal-case focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $state = array_filter([
        'period' => $mode, 'month' => $month->format('Y-m'), 'week' => $period['number'] ?? null, 'date' => $day->toDateString(),
    ]);
    $dayLink = fn (\Carbon\CarbonImmutable $d) => route('report-summary.index', [...$state, 'date' => $d->toDateString()]);
@endphp

<x-layouts.app title="Report Summary">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Report Summary <span class="text-sm font-normal text-muted">· Customer Retention Department</span></h1>
            <span class="text-xs text-muted">Pancake synced {{ $lastSync ? $lastSync->timezone(config('segmentation.timezone'))->format('M j, g:i A') : 'never for '.$today->format('M j') }}</span>
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        @if ($missingAccounts->isNotEmpty())
            <div role="status" class="rounded-lg border border-line bg-white px-4 py-3 text-sm">
                No Pancake account set for {{ $missingAccounts->map->displayName()->join(', ', ' and ') }}, so their orders and sales show as 0.
                @can('user_access.manage') <a href="{{ route('user-access.index') }}" class="font-semibold text-brand-600 hover:underline">Set it in User Access</a>. @endcan
            </div>
        @endif

        {{-- Every control below belongs to this one form, so changing the week keeps the day and the other way round. --}}
        <form id="report-filters" method="GET" action="{{ route('report-summary.index') }}" class="hidden"></form>

        {{-- Weekly (or month to date) --}}
        <x-panel :title="$period['title'].' · '.$period['label']" icon="calendar" title-id="period-title" :tinted="false" body-class="">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm">{{ $mode === 'mtd' ? 'Month to date' : 'Weekly' }}</span>
            </x-slot:badges>
            <x-slot:actions>
                <div class="flex rounded-lg border border-line bg-white p-0.5 text-xs font-semibold" role="radiogroup" aria-label="Weekly section shows">
                    @foreach (['week' => 'Week', 'mtd' => 'Month to date'] as $value => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="period" value="{{ $value }}" form="report-filters" class="peer sr-only" onchange="this.form.submit()" @checked($mode === $value)>
                            <span class="block rounded-md px-3 py-1 text-muted peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <label class="flex items-center gap-1.5 font-semibold text-muted">
                    <span class="sr-only">Month</span>
                    <input type="month" name="month" form="report-filters" value="{{ $month->format('Y-m') }}" max="{{ $today->format('Y-m') }}" onchange="this.form.submit()" class="{{ $control }}">
                </label>
                @if ($mode === 'week')
                    <label>
                        <span class="sr-only">Week</span>
                        <select name="week" form="report-filters" onchange="this.form.submit()" class="{{ $control }}">
                            @foreach ($weeks as $w)
                                <option value="{{ $w['number'] }}" @selected($w['number'] === $period['number']) @disabled($w['start']->greaterThan($today))>Week {{ $w['number'] }} · {{ $w['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </x-slot:actions>

            @include('report-summary._table', ['report' => $periodReport, 'perCra' => false])
        </x-panel>

        <div class="flex items-center gap-3 text-[11px] font-semibold tracking-widest text-muted uppercase" aria-hidden="true">
            <span class="h-px flex-1 bg-line"></span>compare with<span class="h-px flex-1 bg-line"></span>
        </div>

        {{-- Daily --}}
        <x-panel :title="'Daily sales report · '.$day->format('D, M j, Y')" accent="sky" icon="clock" title-id="day-title" :tinted="false" body-class="">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-[#156c8c] shadow-sm">{{ $day->equalTo($today) ? 'Today' : 'Daily' }}</span>
            </x-slot:badges>
            <x-slot:actions>
                <a href="{{ $dayLink($day->subDay()) }}" class="grid size-9 place-items-center rounded-lg border border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600" aria-label="Day before">‹</a>
                <label>
                    <span class="sr-only">Day</span>
                    <input type="date" name="date" form="report-filters" value="{{ $day->toDateString() }}" max="{{ $today->toDateString() }}" onchange="this.form.submit()" class="{{ $control }}">
                </label>
                @if ($day->lessThan($today))
                    <a href="{{ $dayLink($day->addDay()) }}" class="grid size-9 place-items-center rounded-lg border border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600" aria-label="Day after">›</a>
                @endif
            </x-slot:actions>

            @include('report-summary._table', ['report' => $dayReport, 'perCra' => true])
        </x-panel>

        <noscript><button type="submit" form="report-filters" class="h-9 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">Apply</button></noscript>

        <details class="rounded-xl bg-white p-4 text-sm shadow-sm">
            <summary class="cursor-pointer font-semibold">How the numbers are worked out</summary>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-muted sm:grid-cols-2">
                <div><dt class="font-semibold text-ink">Orders and gross sales (Broadcast, Segmentation)</dt><dd>The same numbers as Conversion Breakdown: the CRA's own Pancake POS orders tagged CRD - BROADCAST or CRD - SEGMENTATION. Order counts leave canceled and deleted orders out; gross sales count every status.</dd></div>
                <div><dt class="font-semibold text-ink">Total gross sales (BC &amp; SC)</dt><dd>Gross sales (Broadcast) + Gross sales (Segmentation).</dd></div>
                <div><dt class="font-semibold text-ink">AOV</dt><dd>Total gross sales ÷ (Orders BC + Orders SC). Shows — for a CRA with no orders.</dd></div>
                <div><dt class="font-semibold text-ink">Leads, Engagement, Conv %</dt><dd>Leads and Engagement are each CRA's Daily I.S. totals: the PC Report's Total Inquiries and Total Engagement on the product pages, added up over the days shown. Conv % = (Orders BC + Orders SC) ÷ Engagement.</dd></div>
                <div><dt class="font-semibold text-ink">Net income (coming soon)</dt><dd>Will come from the Daily I.S. once the MNI is in. Until then, I.BSC % = Net income ÷ Total gross sales and Average IBSC per CRA = Net income ÷ CRAs on duty stay empty.</dd></div>
                <div><dt class="font-semibold text-ink">No. of Customer Retention Associates (On Duty)</dt><dd>CRAs who clicked "Log in" in the Segmentation Tracker that day (the real date).</dd></div>
                <div><dt class="font-semibold text-ink">Average Sales per Customer Retention Associate</dt><dd>Gross sales (Broadcast) ÷ CRAs on duty. The per-CRA lines are under the daily table only, as in the CRD sheet.</dd></div>
                <div class="sm:col-span-2"><dt class="font-semibold text-ink">Weeks and month to date</dt><dd>Weeks run 1–7, 8–14… from the 1st, as in Conversion Breakdown. Month to date runs from the 1st to today (the whole month for a past month). The daily section picks its day on its own, so any day can be compared with any week.</dd></div>
            </dl>
        </details>
    </div>
</x-layouts.app>
