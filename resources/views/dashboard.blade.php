<x-layouts.app title="Performance Deck">
    {{--
        Three groups, each module in its own frame with its own period tabs:
        results (real date) · logistics (company-wide) · leads (the tracker's working date).
    --}}
    @php($lagging = \App\Support\WorkingDate::lagDays() > 0)
    @php($eyebrow = 'mb-2 flex flex-wrap items-baseline gap-x-2 text-[11px] font-semibold tracking-wider text-muted uppercase')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold">Performance Deck</h1>
                <x-dashboard.poster />
                {{-- Checks every minute and reloads when a sync has saved new data (resources/js/live.js). --}}
                <span data-live-url="{{ route('dashboard.live') }}" data-live-version="{{ $version }}"
                      title="Updates by itself when new data comes in. Pancake syncs every 10 minutes."
                      class="inline-flex items-center gap-1.5 rounded-full bg-teal/10 px-2.5 py-1 text-xs font-semibold text-teal-700">
                    <span class="size-2 animate-pulse rounded-full bg-teal" aria-hidden="true"></span>
                    Live{{ $pancakeSyncedAt ? ' · Pancake '.$pancakeSyncedAt->timezone(config('segmentation.timezone'))->format('g:i A') : '' }}
                </span>
            </div>

            {{-- One date and one period for every section below. --}}
            <form method="GET" action="{{ route('dashboard') }}" class="flex flex-wrap items-center gap-2" aria-label="Dashboard date and period">
                <input type="hidden" name="period" value="{{ $period }}">
                <label class="relative" title="Date">
                    <span class="sr-only">Date</span>
                    <svg class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"/></svg>
                    <input type="date" name="date" value="{{ $day->toDateString() }}" max="{{ $realToday->toDateString() }}" onchange="this.form.submit()"
                           class="h-9 rounded-lg border border-line bg-white pr-2 pl-8 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                </label>
                <div class="flex h-9 items-center rounded-lg border border-line bg-white p-0.5 text-xs font-semibold" role="group" aria-label="Period">
                    @foreach (['today' => 'Today', 'week' => 'Week', 'month' => 'Month'] as $value => $label)
                        <a href="{{ request()->fullUrlWithQuery(['period' => $value]) }}" @if ($period === $value) aria-current="true" @endif
                           @class(['grid h-full place-items-center rounded-md px-3 transition', 'bg-brand-600 text-white' => $period === $value, 'text-muted hover:text-brand-600' => $period !== $value])>{{ $label }}</a>
                    @endforeach
                </div>
                @unless ($day->isSameDay($realToday))
                    <a href="{{ route('dashboard', ['period' => $period]) }}" class="grid h-9 place-items-center rounded-lg border border-line bg-white px-3 text-xs font-semibold text-muted hover:border-brand-400 hover:text-brand-600">Back to today</a>
                @endunless
                <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white">Apply</button></noscript>
            </form>
        </div>

        @if ($qtyUnknown->isNotEmpty())
            <details role="alert" class="rounded-xl border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-3 text-sm text-[#473821]">
                <summary class="cursor-pointer font-semibold">
                    {{ $qtyUnknown->count() }} FSD {{ Str::plural('lead', $qtyUnknown->count()) }} with no quantity in Pancake: qty 1 assumed, so the out-of-stock date may be early.
                </summary>
                <ul class="mt-2 space-y-1">
                    @foreach ($qtyUnknown->take(50) as $lead)
                        <li>{{ $lead->est_out_of_stock_date->format('M j') }} · {{ $lead->customer_name }} · {{ $lead->product_name }} · order {{ $lead->order_id }} · {{ $lead->assignee?->displayName() ?? 'Unassigned' }}</li>
                    @endforeach
                    @if ($qtyUnknown->count() > 50)
                        <li class="text-muted">…and {{ $qtyUnknown->count() - 50 }} more.</li>
                    @endif
                </ul>
            </details>
        @endif

        @if ($salesGoals || $conversion)
            <div>
                <p class="{{ $eyebrow }}">
                    <span>Results</span>
                    <span class="font-medium normal-case tracking-normal">· Pancake sales and conversion by the real date, {{ $day->format('D, M j') }}</span>
                </p>
                <div class="grid gap-4 lg:grid-cols-12">
                    @if ($salesGoals)
                        <x-dashboard.sales-goals :goals="$salesGoals" :class="$conversion ? 'lg:col-span-8' : 'lg:col-span-12'" />
                    @endif
                    @if ($conversion)
                        <x-dashboard.conversion :periods="$conversion" :active="$period" class="lg:col-span-4" />
                    @endif
                </div>
            </div>
        @endif

        @if ($logistics)
            <div>
                <p class="{{ $eyebrow }}">
                    <span>Logistics</span>
                    <span class="font-medium normal-case tracking-normal">· Company-wide, by delivery date</span>
                </p>
                <x-dashboard.logistics :periods="$logisticsPeriods" :fetched-at="$logisticsFetchedAt" :active="$period" />
            </div>
        @endif

        @if ($segmentation)
            <div>
                <p class="{{ $eyebrow }}">
                    <span>Leads</span>
                    <span class="font-medium normal-case tracking-normal">· Segmentation Tracker by lead day{{ $lagging ? ', paired with '.$day->format('M j').': lead day '.$leadDay->format('D, M j') : '' }}</span>
                </p>
                <x-dashboard.segmentation :periods="$segmentation" :active="$period" />
            </div>
        @endif

        @if (! $segmentation && ! $salesGoals)
            <div class="rounded-xl bg-white p-6 text-muted shadow-sm">No modules are available to you yet.</div>
        @endif
    </div>
</x-layouts.app>
