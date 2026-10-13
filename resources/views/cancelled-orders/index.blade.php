@php
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    // Kept when the period, dates or status change.
    $kept = array_filter(['cra' => $filters['cra'] ?? null, 'status' => $filters['status'] ?? null]);
    $dayLink = fn (string $day) => route('cancelled-orders.index', [...$kept, 'period' => 'day', 'date' => $day]);
@endphp

<x-layouts.app title="Cancelled / Waiting Orders">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Cancelled / Waiting Orders <span class="text-sm font-normal text-muted">· {{ $label }} · tagged CRD - BROADCAST or CRD - SEGMENTATION</span></h1>
            <a href="{{ route('dashboard', $dashboardQuery) }}" class="text-sm font-semibold text-brand-600 hover:underline">&larr; Dashboard</a>
        </header>

        <section aria-label="Totals" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([['Cancelled + waiting', number_format($totals['total'])], ['Cancelled', number_format($totals['cancelled'])], ['Waiting for goods', number_format($totals['waiting'])], ['Amount', '₱'.number_format($totals['amount'], 2)]] as [$title, $value])
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">{{ $title }}</p>
                    <p class="text-xl font-bold tabular-nums">{{ $value }}</p>
                </div>
            @endforeach
        </section>

        {{-- Day, week (Monday–Sunday), month or From–To; switching keeps the dates shown as the starting point --}}
        <form method="GET" action="{{ route('cancelled-orders.index') }}" class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm" aria-label="Dates and filters">
            @foreach ($kept as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            @if ($period !== 'range')
                <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                <input type="hidden" name="to" value="{{ $to->toDateString() }}">
            @endif

            {{-- The period buttons submit their own; the other controls switch this on to keep the current one --}}
            <input type="hidden" name="period" value="{{ $period }}" disabled data-period>
            @php($submit = "this.form.querySelector('[data-period]').disabled = false; this.form.submit()")

            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold tracking-wide text-muted uppercase">Per</span>
                <div class="flex rounded-lg border border-line p-0.5" role="group" aria-label="Period">
                    @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'range' => 'Range'] as $value => $name)
                        <button type="submit" name="period" value="{{ $value }}" aria-pressed="{{ $period === $value ? 'true' : 'false' }}"
                                @class(['h-8 rounded-md px-3 text-sm font-semibold', 'bg-brand-600 text-white' => $period === $value, 'text-muted hover:text-brand-600' => $period !== $value])>{{ $name }}</button>
                    @endforeach
                </div>
            </div>

            @switch ($period)
                @case ('day')
                @case ('week')
                    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                        {{ $period === 'day' ? 'Day' : 'Week of' }}
                        <input type="date" name="date" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}"
                               onchange="{{ $submit }}" class="{{ $control }} text-ink normal-case">
                    </label>
                    @break
                @case ('month')
                    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                        Month
                        <select name="month" onchange="{{ $submit }}" class="{{ $control }} font-semibold text-ink normal-case">
                            @for ($m = 0; $m < 12; $m++)
                                @php($option = $today->startOfMonth()->subMonths($m))
                                <option value="{{ $option->format('Y-m') }}" @selected($from->isSameMonth($option))>{{ $option->format('F Y') }}</option>
                            @endfor
                        </select>
                    </label>
                    @break
                @default
                    <div class="flex flex-col gap-1.5">
                        <span class="text-xs font-semibold tracking-wide text-muted uppercase">From – to</span>
                        <div class="flex items-center gap-1.5">
                            <input type="date" name="from" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="From" onchange="{{ $submit }}" class="{{ $control }}">
                            <span class="text-sm text-muted">to</span>
                            <input type="date" name="to" value="{{ $to->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="To" onchange="{{ $submit }}" class="{{ $control }}">
                        </div>
                    </div>
            @endswitch

            @if ($canViewAll)
                <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    CRA
                    <select name="cra" onchange="{{ $submit }}" class="{{ $control }} text-ink normal-case">
                        <option value="">All CRAs</option>
                        @foreach ($allCras as $cra)
                            <option value="{{ $cra->id }}" @selected((int) ($filters['cra'] ?? 0) === $cra->id)>{{ $cra->displayName() }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold tracking-wide text-muted uppercase">Status</span>
                <div class="flex gap-1.5">
                    @foreach (['' => 'All', 'cancelled' => 'Cancelled', 'waiting' => 'Waiting'] as $value => $name)
                        <a href="{{ request()->fullUrlWithQuery(['status' => $value ?: null, 'page' => null]) }}"
                           @class(['grid h-9 place-items-center rounded-lg border px-3 text-xs font-semibold', 'border-brand-500 bg-brand-50 text-brand-700' => ($filters['status'] ?? '') === $value, 'border-line text-muted hover:border-brand-400 hover:text-brand-600' => ($filters['status'] ?? '') !== $value])>{{ $name }}</a>
                    @endforeach
                </div>
            </div>
            <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white">Apply</button></noscript>
        </form>

        <div class="grid items-start gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
            {{-- Per day; a day opens that day's orders --}}
            <section aria-labelledby="per-day-title" class="overflow-hidden rounded-xl bg-white shadow-sm">
                <h2 id="per-day-title" class="border-b border-line px-4 py-3 text-sm font-semibold">Per day</h2>
                @if ($days->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-muted">None in these dates.</p>
                @else
                    <div class="max-h-[32rem] overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-canvas text-xs text-muted">
                                <tr>
                                    <th scope="col" class="px-4 py-2 text-left font-semibold">Day</th>
                                    <th scope="col" class="px-2 py-2 text-right font-semibold">Cancelled</th>
                                    <th scope="col" class="px-2 py-2 text-right font-semibold">Waiting</th>
                                    <th scope="col" class="px-4 py-2 text-right font-semibold">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line tabular-nums">
                                @foreach ($days as $day => $count)
                                    <tr>
                                        <th scope="row" class="px-4 py-2 text-left font-medium">
                                            <a href="{{ $dayLink($day) }}" class="hover:text-brand-600 hover:underline">{{ \Carbon\CarbonImmutable::parse($day)->format('D, M j') }}</a>
                                        </th>
                                        <td class="px-2 py-2 text-right">{{ number_format($count['cancelled']) }}</td>
                                        <td class="px-2 py-2 text-right">{{ number_format($count['waiting']) }}</td>
                                        <td class="px-4 py-2 text-right font-semibold" title="₱{{ number_format($count['amount'], 2) }}">{{ number_format($count['total']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section aria-label="Orders" class="overflow-hidden rounded-xl bg-white shadow-sm">
                @if ($orders->isEmpty())
                    <p class="px-4 py-10 text-center text-sm text-muted">No cancelled or waiting orders in these dates.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-left text-sm">
                            <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Ordered</th>
                                    <th class="px-4 py-3 font-semibold">Order #</th>
                                    <th class="px-4 py-3 font-semibold">CRA</th>
                                    <th class="px-4 py-3 font-semibold">Customer</th>
                                    <th class="px-4 py-3 font-semibold">Tag</th>
                                    <th class="px-4 py-3 font-semibold">Status</th>
                                    <th class="px-4 py-3 text-right font-semibold">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($orders as $order)
                                    @php($status = $statuses[$order->status] ?? null)
                                    <tr>
                                        <td class="px-4 py-3 whitespace-nowrap tabular-nums">{{ ($order->ordered_at?->timezone(config('segmentation.timezone')) ?? $order->ordered_on)->format($order->ordered_at ? 'M j, g:i A' : 'M j') }}</td>
                                        <td class="px-4 py-3 tabular-nums">{{ $order->pancake_order_id }}</td>
                                        <td class="px-4 py-3">{{ $craFor($order)?->displayName() ?? '—' }}</td>
                                        <td class="px-4 py-3">
                                            {{ $order->customer_name ?: '—' }}
                                            @if ($order->phone_number)
                                                <span class="block text-xs text-muted tabular-nums">{{ $order->phone_number }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap', 'bg-[#e3f3f9] text-[#0c5a7d]' => $order->conversion_type === 'broadcast', 'bg-brand-100 text-brand-700' => $order->conversion_type !== 'broadcast'])>
                                                {{ $order->conversion_type === 'broadcast' ? 'Broadcast' : 'Segmentation' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap {{ $status[1] ?? 'bg-canvas text-muted' }}">{{ $status[0] ?? 'Status '.($order->status ?? '—') }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">₱{{ number_format($order->sales(), 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($orders->hasPages())
                        <div class="border-t border-line px-4 py-3">{{ $orders->links() }}</div>
                    @endif
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
