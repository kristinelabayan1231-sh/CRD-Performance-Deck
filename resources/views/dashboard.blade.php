<x-layouts.app title="Performance Deck">
    {{--
        Three groups for one month (to date) or From–To range: results (real date) ·
        logistics (company-wide) · leads (the tracker's working date, paired lead days).
    --}}
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
            <x-panel title="Results" icon="target" title-id="results-title">
                <x-slot:badges>
                    <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm">{{ $range->label() }}{{ ! $range->custom && $range->month->isSameMonth($realToday) ? ' · month to date' : '' }}</span>
                    <span class="text-xs text-muted">Pancake sales and conversion by the real date</span>
                </x-slot:badges>
                <x-dashboard.results :results="$results" :churn="$churn" :customers="$customers" :query="array_filter($filters)" />
            </x-panel>
        @endif

        {{-- Segmentation Tracker, then goal & conversion per CRA, both full width --}}
        @if ($segmentation)
            @php($pairedNote = \App\Support\WorkingDate::lagDays() > 0 ? 'Paired with '.$range->label().': lead '.($leadFrom->equalTo($leadTo) ? 'day '.$leadFrom->format('D, M j') : 'days '.\App\Support\WorkingDate::leadDaysLabel($range->from, $range->to)) : null)
            <x-dashboard.segmentation :periods="['range' => $segmentation]" active="range" :note="$pairedNote" />
        @endif

        @if ($results)
            <x-dashboard.per-cra :results="$results" />
        @endif

        @if ($logistics)
            <x-dashboard.logistics :periods="$logisticsPeriod ? ['range' => $logisticsPeriod] : null" :fetched-at="$logisticsFetchedAt" active="range" />
        @endif


        @if (! $segmentation && ! $results)
            <div class="rounded-xl bg-white p-6 text-muted shadow-sm">No modules are available to you yet.</div>
        @endif

        @if ($results || $segmentation)
            @php($grace = config('customers.churn_grace_days'))
            <details class="rounded-xl bg-white p-4 text-sm shadow-sm">
                <summary class="cursor-pointer font-semibold">How the numbers are worked out</summary>
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-muted sm:grid-cols-2">
                    <div class="sm:col-span-2"><dt class="font-semibold text-ink">Dates</dt><dd>Every section uses the month picked (January–December, from the 1st to today for the current month) or the From–To range. Sales, orders and logistics are by the real date; the Segmentation Tracker uses the paired lead days (Settings → Working Date).</dd></div>
                    @if ($results)
                        <div><dt class="font-semibold text-ink">CRD monthly goal (Gross Sales)</dt><dd>Gross sales of every CRA ÷ the CRD monthly goal ({{ '₱'.number_format(\App\Support\SalesGoals::crdMonthly()) }}, Settings → Sales Goals). Pace = day of the month ÷ days in the month. For a From–To range that isn't a whole month, the goal is prorated: monthly goal × days picked ÷ days in the month.</dd></div>
                        <div><dt class="font-semibold text-ink">Gross sales</dt><dd>Totals of the CRAs' own Pancake POS orders tagged CRD - BROADCAST or CRD - SEGMENTATION, using Shecom's sales per order (without the child TSD row) once synced, else Pancake's total.</dd></div>
                        <div><dt class="font-semibold text-ink">Total confirmed orders</dt><dd>The CRAs' own Pancake POS orders tagged CRD - BROADCAST (BC) or CRD - SEGMENTATION (SC), by order date. Canceled and deleted orders don't count. Click the tile to see them.</dd></div>
                        <div><dt class="font-semibold text-ink">Conversion rate</dt><dd>(BC orders + SC orders) ÷ (Pancake engagements + Segmentation Tracker leads) × 100.</dd></div>
                        <div><dt class="font-semibold text-ink">AOV (average order value)</dt><dd>Gross sales ÷ total confirmed orders.</dd></div>
                        <div><dt class="font-semibold text-ink">Churn rate</dt><dd>
                            Customers lost ÷ customers due × 100. A CRD-delivered customer runs out on delivered date + qty × consumption days − 1, then has {{ $grace }} days to order again.
                            Due = customers whose {{ $grace }} days ended in the dates picked. Lost = no Pancake order (not canceled) and no new delivery in that time.
                            Example: 6,000 due and 50 lost = 50 ÷ 6,000 × 100 = 0.83%. Lower is better.
                        </dd></div>
                        @if ($customers)
                            <div><dt class="font-semibold text-ink">Retained and Repeat Customers</dt><dd>As in the Customer Database, for customers delivered in the dates picked: Retained = their delivery handled by a CRA is their first CRA-handled order ever; Repeat = they had an earlier CRA-handled order too (any year). Click a tile to see them.</dd></div>
                        @endif
                        <div><dt class="font-semibold text-ink">Goal per CRA</dt><dd>The CRA's gross sales ÷ (their daily goal × days in the dates picked). Daily goal = their own, else the general {{ '₱'.number_format(\App\Support\SalesGoals::craDaily()) }}. Left = goal − sales.</dd></div>
                        <div><dt class="font-semibold text-ink">Conv % per CRA</dt><dd>The CRA's (BC + SC orders) ÷ (their engagements + their leads) × 100. Supervisors see every CRA; a CRA sees only their own numbers.</dd></div>
                    @endif
                    @if ($segmentation)
                        <div><dt class="font-semibold text-ink">Live from Logistics</dt><dd>From the logistics retention report, by delivered date. Retention rate = Retained by CRD ÷ FB delivered. Repeat rate = Actual Order (CRD-delivered customers who placed another CRD order) ÷ CRD delivered.</dd></div>
                        <div><dt class="font-semibold text-ink">Segmentation Tracker</dt><dd>Leads = leads for the lead days. Catered = leads with a status ÷ leads. Converted = Repeat Purchase Yes, or No with feedback PURCHASED, ÷ leads. Went cold = leads tagged Cold or CanPro Cold ÷ leads. Each is compared with the same number of days just before.</dd></div>
                    @endif
                </dl>
            </details>
        @endif
    </div>
</x-layouts.app>
