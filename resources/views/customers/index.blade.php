@php
    $query = fn (array $extra) => route('customers.index', array_filter([...$filters, ...$extra], fn ($v) => $v !== null && $v !== ''));
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $segment = $filters['segment'] ?? null;
@endphp

<x-layouts.app title="Customer Database">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Customer Database <span class="text-sm font-normal text-muted">· FSD and CRD delivered customers · {{ $range['label'] }}</span></h1>
            <span class="text-xs text-muted">
                Logistics updated {{ $fetchedAt ? $fetchedAt->timezone(config('segmentation.timezone'))->format('M j, g:i A') : 'never' }} · amounts from Pancake POS
            </span>
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        {{-- Tiles: click to filter --}}
        <section aria-label="Customer counts" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                [null, 'Customers', $counts['all'], 'text-ink', 'Everyone with an FSD or CRD delivery'.($range['from'] ? ' in the period' : '')],
                ['crd', 'CRD Leads', $counts['crd'], 'text-brand-600', 'A delivery handled by a CRA'.($range['from'] ? ' in the period' : '')],
                ['retained', 'Retained', $counts['retained'], 'text-teal-700', 'Their only CRA-handled order so far'],
                ['repeat', 'Repeat Customers', $counts['repeat'], 'text-violet', 'An earlier CRA-handled order too'],
            ] as [$key, $label, $value, $color, $hint])
                <a href="{{ $query(['segment' => $key, 'page' => null]) }}" @if ($segment === $key) aria-current="true" @endif
                   @class(['flex flex-col gap-0.5 rounded-xl bg-white p-4 shadow-sm ring-2 transition hover:ring-brand-200', 'ring-brand-500' => $segment === $key, 'ring-transparent' => $segment !== $key])>
                    <span class="text-[11px] font-semibold tracking-wide text-muted uppercase">{{ $label }}</span>
                    <span class="text-2xl font-bold tabular-nums {{ $color }}">{{ number_format($value) }}</span>
                    <span class="text-xs text-muted">{{ $hint }}</span>
                </a>
            @endforeach
        </section>

        {{-- Filters --}}
        <form method="GET" action="{{ route('customers.index') }}" class="flex flex-wrap items-end gap-x-6 gap-y-4 rounded-xl bg-white p-4 shadow-sm">
            <label class="flex min-w-56 flex-1 flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                Search
                <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Customer name or contact number" class="{{ $control }} text-ink normal-case">
            </label>

            <fieldset>
                <legend class="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">Delivered</legend>
                <div class="flex rounded-lg border border-line p-0.5 text-sm font-semibold">
                    @foreach ($periods as $value => $label)
                        <label class="cursor-pointer">
                            {{-- A button clears the From–To range, which would otherwise win. --}}
                            <input type="radio" name="period" value="{{ $value }}" class="peer sr-only" onchange="this.form.from.value = ''; this.form.to.value = ''; this.form.submit()" @checked($filters['period'] === $value)>
                            <span class="block rounded-md px-3 py-1 text-muted peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

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

            <button type="submit" class="h-9 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
            @if ($errors->has('to'))
                <p role="alert" class="w-full text-sm text-coral-700">{{ $errors->first('to') }}</p>
            @endif
            @if (filled($filters['search'] ?? null) || $segment || $filters['period'] !== 'all')
                <a href="{{ route('customers.index') }}" class="h-9 content-center text-sm font-medium text-muted hover:text-brand-600">Clear</a>
            @endif
        </form>

        {{-- Customers --}}
        <section class="overflow-hidden rounded-xl bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-line px-4 py-3">
                <h2 class="text-base font-semibold">{{ $segment ? $segments[$segment] : 'All customers' }}</h2>
                <span class="text-sm text-muted">{{ number_format($customers->total()) }} {{ Str::plural('customer', $customers->total()) }} · click a row for details</span>
            </div>

            @if ($customers->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">
                    No customers match.
                    @if (! $fetchedAt) Delivered orders load from the logistics API with the next hourly lead sync. @endif
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Customer name</th>
                                <th class="px-4 py-3 font-semibold">Contact number</th>
                                <th class="px-4 py-3 text-right font-semibold">QTY</th>
                                <th class="px-4 py-3 text-right font-semibold">Total spent <span class="font-normal normal-case">(CLTV overall)</span></th>
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
                                                'bg-brand-100 text-brand-700' => $label !== 'Retained',
                                            ])>{{ $label }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-muted">{{ $customer->phone_number }}</td>
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
        </section>
    </div>

    {{-- Customer details, loaded when a row is clicked --}}
    <dialog id="customer-dialog" aria-label="Customer details" class="m-auto max-h-[90dvh] w-[min(56rem,calc(100%-2rem))] overflow-hidden rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
        <div class="flex max-h-[90dvh] flex-col">
            <div class="flex shrink-0 items-center justify-end border-b border-line px-5 py-2">
                <button type="button" data-customer-close aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <div data-customer-body class="min-h-0 overflow-y-auto p-5"></div>
        </div>
    </dialog>
</x-layouts.app>
