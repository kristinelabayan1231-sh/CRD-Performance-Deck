@php
    $query = fn (array $extra) => route('customers.index', array_filter([...$filters, ...$extra], fn ($v) => $v !== null && $v !== ''));
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $segment = $filters['segment'] ?? null;
@endphp

<x-layouts.app title="Customer Database">
    <div class="space-y-4">
        @include('customers._tabs')

        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Customer Database <span class="text-sm font-normal text-muted">· {{ $range['label'] }}</span></h1>
            <span class="text-xs text-muted">Updated {{ $fetchedAt ? $fetchedAt->timezone(config('segmentation.timezone'))->format('M j, g:i A') : 'never' }}</span>
        </header>

        @if ($history['checked'] < $history['total'])
            <p role="status" class="text-xs text-muted" title="Until a customer's older orders are checked, they may show as Retained instead of Repeat.">
                Checking older orders in Pancake: <span class="font-semibold tabular-nums">{{ number_format($history['checked']) }} of {{ number_format($history['total']) }}</span> done.
            </p>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        {{-- Tiles: click to filter; the one in use sits raised with a "Showing" pill --}}
        <section aria-label="Customer counts" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                [null, 'Customers', $counts['all'], 'from-[#4f5563] to-[#343a40]', 'Everyone with an FSD or CRD delivery'],
                ['crd', 'CRD Leads', $counts['crd'], 'from-brand-500 to-brand-700', 'A delivery handled by a CRA'],
                ['retained', 'Retained', $counts['retained'], 'from-[#0e8f7c] to-[#0b6b5d]', 'Their first CRA-handled order ever'],
                ['repeat', 'Repeat Customers', $counts['repeat'], 'from-[#1f8fb8] to-[#156c8c]', 'An earlier CRA-handled order too'],
            ] as [$key, $label, $value, $gradient, $hint])
                <a href="{{ $query(['segment' => $key, 'page' => null]) }}" title="{{ $hint }}. Click to filter." @if ($segment === $key) aria-current="true" @endif
                   @class(['relative flex flex-col gap-0.5 overflow-hidden rounded-xl bg-gradient-to-br p-4 text-white transition', $gradient, '-translate-y-1.5 shadow-xl' => $segment === $key, 'shadow-sm hover:-translate-y-0.5 hover:shadow-lg' => $segment !== $key])>
                    <span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/15"></span>
                    <span class="relative flex items-center justify-between text-[11px] font-semibold tracking-wide uppercase">
                        {{ $label }}
                        @if ($segment === $key)
                            <span class="rounded-full bg-white px-1.5 text-[10px] text-ink normal-case">Showing</span>
                        @endif
                    </span>
                    <span class="relative text-2xl font-bold tabular-nums">{{ number_format($value) }}</span>
                </a>
            @endforeach
        </section>

        {{-- Filters: search, show and sort; below it Delivered on the left and the date range on the right --}}
        <form method="GET" action="{{ route('customers.index') }}" class="flex flex-col gap-4 rounded-xl bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-end gap-x-5 gap-y-4">
                <div class="flex min-w-64 flex-1 flex-col gap-1.5">
                    <label for="customer-search" class="text-xs font-semibold tracking-wide text-muted uppercase">Search</label>
                    <div class="flex">
                        <input id="customer-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Customer name or contact number"
                               class="h-9 min-w-0 flex-1 rounded-l-lg border border-r-0 border-line bg-white px-2.5 text-sm text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                        <button type="submit" class="flex h-9 shrink-0 items-center gap-1.5 rounded-r-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                            Search
                        </button>
                    </div>
                </div>

                <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    Show
                    <select name="segment" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                        <option value="">All customers</option>
                        @foreach ($segments as $value => $label)
                            <option value="{{ $value }}" @selected($segment === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    Sort by
                    <select name="sort" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                        @foreach ($sorts as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['sort'] ?? 'spent') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="flex flex-wrap items-end gap-x-5 gap-y-4">
                <fieldset>
                    <legend class="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">Delivered</legend>
                    <div class="flex h-9 items-center rounded-lg border border-line p-0.5 text-sm font-semibold">
                        @foreach ($periods as $value => $label)
                            <label class="h-full cursor-pointer">
                                {{-- A button clears the From–To range, which would otherwise win. --}}
                                <input type="radio" name="period" value="{{ $value }}" class="peer sr-only" onchange="this.form.from.value = ''; this.form.to.value = ''; this.form.submit()" @checked($filters['period'] === $value)>
                                <span class="grid h-full place-items-center rounded-md px-3 text-muted peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- Date range on the right --}}
                <div class="ml-auto flex flex-wrap items-end gap-x-5 gap-y-4">
                    <fieldset>
                        <legend class="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">Or date range</legend>
                        <div class="flex items-center gap-1.5">
                            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" min="{{ \App\Models\LogisticsOrder::coveredFrom() }}" max="{{ $today->toDateString() }}"
                                   aria-label="From" onchange="this.form.submit()" @class([$control, 'text-ink', 'border-brand-500 ring-2 ring-brand-200' => $filters['period'] === 'range'])>
                            <span class="text-sm text-muted">to</span>
                            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" min="{{ \App\Models\LogisticsOrder::coveredFrom() }}" max="{{ $today->toDateString() }}"
                                   aria-label="To" onchange="this.form.submit()" @class([$control, 'text-ink', 'border-brand-500 ring-2 ring-brand-200' => $filters['period'] === 'range'])>
                        </div>
                    </fieldset>
                @if (filled($filters['search'] ?? null) || $segment || $filters['period'] !== 'all')
                    <a href="{{ route('customers.index') }}" class="h-9 content-center text-sm font-medium text-muted hover:text-brand-600">Clear</a>
                @endif
                </div>
            </div>
            @if ($errors->has('to'))
                <p role="alert" class="w-full text-sm text-coral-700">{{ $errors->first('to') }}</p>
            @endif
        </form>

        {{-- Customers --}}
        <x-panel :title="$segment ? $segments[$segment] : 'All customers'" icon="database" :tinted="false" body-class="">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-brand-700 tabular-nums shadow-sm">{{ number_format($customers->total()) }}</span>
            </x-slot:badges>

            @if ($customers->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">
                    No customers match.
                    @if (! $fetchedAt) Delivered orders load from the logistics API with the next hourly lead sync. @endif
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead class="bg-[#f7f4f8] text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Customer name</th>
                                <th class="px-4 py-3 font-semibold">Contact number</th>
                                <th class="px-4 py-3 text-right font-semibold">QTY</th>
                                <th class="px-4 py-3 text-right font-semibold">Total spent</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($customers as $customer)
                                @php($label = \App\Services\CustomerDatabase::segmentFor((int) $customer->cra_orders, (int) $customer->cra_in_period))
                                <tr tabindex="0" role="button" data-customer-url="{{ route('customers.show', $customer->phone_key) }}"
                                    aria-label="Open {{ $customer->customer_name ?: 'customer' }}"
                                    class="cursor-pointer hover:bg-brand-50/60 focus:bg-brand-50 focus:outline-none">
                                    <td class="px-4 py-3">
                                        <span class="font-medium">{{ $customer->customer_name ?: 'Unknown' }}</span>
                                        @if ($label)
                                            <span @class([
                                                'ml-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                                                'bg-teal/15 text-teal-700' => $label === 'Retained',
                                                'bg-sky/20 text-[#156c8c]' => $label !== 'Retained',
                                            ])>{{ $label }}</span>
                                        @endif
                                        @if ($customer->possible_matches ?? 0)
                                            <span class="ml-1.5 rounded-full bg-[#ffe5a0] px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap text-[#473821]"
                                                  title="{{ $customer->possible_matches }} other {{ Str::plural('customer', $customer->possible_matches) }} with the same name. Open to check if it's the same person.">Possible match</span>
                                        @endif
                                    </td>
                                    {{-- Every number of the customer, main number first --}}
                                    <td class="px-4 py-3 tabular-nums text-muted">{{ implode(' / ', $customer->phone_numbers ?? [$customer->phone_number]) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($customer->purchases) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">₱{{ number_format($customer->total_spent, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($customers->hasPages())
                    <div class="border-t border-line px-4 py-3">{{ $customers->links() }}</div>
                @endif
            @endif
        </x-panel>

        @php($since = \Carbon\CarbonImmutable::parse(\App\Models\LogisticsOrder::coveredFrom())->format('M j, Y'))
        <details class="rounded-xl bg-white p-4 text-sm shadow-sm">
            <summary class="cursor-pointer font-semibold">How the numbers are worked out</summary>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-muted sm:grid-cols-2">
                <div class="sm:col-span-2"><dt class="font-semibold text-ink">Who is listed</dt><dd>Everyone with an FSD- or CRD-delivered order since {{ $since }}, one row per customer: each contact number (last 10 digits) is its own customer until numbers are merged as the same person, then they show together (number 1 / number 2) with their orders combined. From {{ \Carbon\CarbonImmutable::parse(config('customers.logistics_from'))->format('M j') }} the deliveries come from the logistics API; before that, from Pancake POS deliveries. Amounts, statuses and products come from Pancake POS.</dd></div>
                <div><dt class="font-semibold text-ink">QTY</dt><dd>The customer's delivered orders since {{ $since }}.</dd></div>
                <div><dt class="font-semibold text-ink">Total spent (CLTV overall)</dt><dd>The Pancake POS totals of their CRA-handled delivered orders (FSD-only orders don't count).</dd></div>
                <div><dt class="font-semibold text-ink">Handled by a CRA</dt><dd>An order logistics lists as CRD-delivered, or one sold by a CRD Pancake account (the CRD team's accounts, past CRAs too) or a CRA's own Pancake account.</dd></div>
                <div><dt class="font-semibold text-ink">CRD Leads</dt><dd>Customers with a CRA-handled delivery in the dates picked (any time for All).</dd></div>
                <div><dt class="font-semibold text-ink">Retained</dt><dd>That CRA-handled order is their first one ever: none earlier, including before {{ $since }} (each CRD customer's full order history is checked in Pancake).</dd></div>
                <div><dt class="font-semibold text-ink">Repeat Customers</dt><dd>They had at least one earlier CRA-handled order (any year), plus the one in the dates picked.</dd></div>
                <div><dt class="font-semibold text-ink">Product CLTV</dt><dd>A product's CLTV = SRP × {{ config('customers.cltv_units') }} (SRP from Settings → Product Consumption). The customer's spend on it = delivered units × SRP; "Reached CLTV" once that reaches the CLTV.</dd></div>
                <div><dt class="font-semibold text-ink">Possible match</dt><dd>Another customer has exactly the same name (generic names like Facebook User don't count). Open the customer to compare and, if it's the same person, press Same customer to merge them; Separate undoes it.</dd></div>
                <div><dt class="font-semibold text-ink">Today / Week / Month / range</dt><dd>Pick customers by delivered date. QTY and Total spent still count all their orders since {{ $since }}. Weeks run 1–7, 8–14… from the 1st.</dd></div>
            </dl>
        </details>
    </div>

    @include('customers._dialog')
</x-layouts.app>
