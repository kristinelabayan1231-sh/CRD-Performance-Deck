<x-layouts.app title="Performance Deck">
    {{--
        Three groups for one month (to date) or From–To range: results (real date) ·
        logistics (company-wide) · leads (the tracker's working date, paired lead days).
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

            {{-- One month (to date by default) or From–To range for every section below. --}}
            @php($control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')
            <form method="GET" action="{{ route('dashboard') }}" class="flex flex-wrap items-center gap-2" aria-label="Dashboard month and dates">
                <label>
                    <span class="sr-only">Month</span>
                    {{-- Picking a month clears the range, which would otherwise win. --}}
                    <select name="month" onchange="this.form.from.value = ''; this.form.to.value = ''; this.form.submit()"
                            @class([$control, 'font-semibold', 'border-brand-500 ring-2 ring-brand-200' => ! $range->custom])>
                        @for ($m = 1; $m <= 12; $m++)
                            @php($option = $realToday->setDate($realToday->year, $m, 1))
                            <option value="{{ $option->format('Y-m') }}" @selected(! $range->custom && $range->month->isSameMonth($option)) @disabled($option->greaterThan($realToday))>{{ $option->format('F') }}</option>
                        @endfor
                        @if ($range->custom)
                            <option value="" selected disabled>Custom dates</option>
                        @endif
                    </select>
                </label>
                <div class="flex items-center gap-1.5">
                    <input type="date" name="from" value="{{ $range->custom ? $range->from->toDateString() : '' }}" max="{{ $realToday->toDateString() }}" aria-label="From"
                           onchange="this.form.month.value = ''; this.form.submit()" @class([$control, 'border-brand-500 ring-2 ring-brand-200' => $range->custom])>
                    <span class="text-sm text-muted">to</span>
                    <input type="date" name="to" value="{{ $range->custom ? $range->to->toDateString() : '' }}" max="{{ $realToday->toDateString() }}" aria-label="To"
                           onchange="this.form.month.value = ''; this.form.submit()" @class([$control, 'border-brand-500 ring-2 ring-brand-200' => $range->custom])>
                </div>
                @if ($range->custom || ! $range->month->isSameMonth($realToday))
                    <a href="{{ route('dashboard') }}" class="grid h-9 place-items-center rounded-lg border border-line bg-white px-3 text-xs font-semibold text-muted hover:border-brand-400 hover:text-brand-600">This month</a>
                @endif
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

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        @if ($results)
            <div class="space-y-4">
                <p class="{{ $eyebrow }} !mb-0">
                    <span>Results</span>
                    <span class="font-medium normal-case tracking-normal">· Pancake sales and conversion by the real date, {{ $range->label() }}{{ ! $range->custom && $range->month->isSameMonth($realToday) ? ' (month to date)' : '' }}</span>
                </p>
                <x-dashboard.results :results="$results" :churn="$churn" :query="array_filter($filters)" />
                <x-dashboard.per-cra :results="$results" />
            </div>
        @endif

        @if ($logistics)
            <div>
                <p class="{{ $eyebrow }}">
                    <span>Logistics</span>
                    <span class="font-medium normal-case tracking-normal">· Company-wide, by delivery date</span>
                </p>
                <x-dashboard.logistics :periods="$logisticsPeriod ? ['range' => $logisticsPeriod] : null" :fetched-at="$logisticsFetchedAt" active="range" />
            </div>
        @endif

        @if ($segmentation)
            <div>
                <p class="{{ $eyebrow }}">
                    <span>Leads</span>
                    <span class="font-medium normal-case tracking-normal">· Segmentation Tracker by lead day{{ $lagging ? ', paired with '.$range->label().': lead '.($leadFrom->equalTo($leadTo) ? 'day '.$leadFrom->format('D, M j') : 'days '.\App\Support\WorkingDate::leadDaysLabel($range->from, $range->to)) : '' }}</span>
                </p>
                <x-dashboard.segmentation :periods="['range' => $segmentation]" active="range" />
            </div>
        @endif

        @if (! $segmentation && ! $results)
            <div class="rounded-xl bg-white p-6 text-muted shadow-sm">No modules are available to you yet.</div>
        @endif
    </div>
</x-layouts.app>
