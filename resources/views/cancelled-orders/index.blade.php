@php
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    // Kept when the period, dates or status change.
    $kept = array_filter(['cra' => $filters['cra'] ?? null, 'status' => $filters['status'] ?? null]);
    $dayLink = fn (string $day) => route('cancelled-orders.index', [...$kept, 'period' => 'day', 'date' => $day]);
    // The dashboard graphs' colours: cancelled red, waiting amber.
    $cancelledColor = '#e0564f';
    $waitingColor = '#d99a0b';
    $busiestDay = max(1, $days->max('total') ?? 0);
@endphp

<x-layouts.app title="Cancelled / Waiting Orders">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Cancelled / Waiting Orders <span class="text-sm font-normal text-muted">· {{ $label }} · tagged CRD - BROADCAST or CRD - SEGMENTATION (before {{ \Carbon\CarbonImmutable::parse(config('segmentation.conversion_tags_since'))->format('M j') }}: sold by a CRD account)</span></h1>
            <a href="{{ route('dashboard', $dashboardQuery) }}" class="text-sm font-semibold text-brand-600 hover:underline">&larr; Dashboard</a>
        </header>

        <section aria-label="Totals" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {{-- Coloured as on the dashboard: one colour each so they read apart --}}
            @foreach ([
                ['Cancelled + waiting', number_format($totals['total']), 'from-brand-600 to-brand-700'],
                ['Cancelled', number_format($totals['cancelled']), 'from-[#e0564f] to-[#ad3530]'],
                ['Waiting for goods', number_format($totals['waiting']), 'from-[#d99a0b] to-[#a8740a]'],
                ['Amount', '₱'.number_format($totals['amount'], 2), 'from-[#1f8fb8] to-[#156c8c]'],
            ] as [$title, $value, $colors])
                <div class="relative overflow-hidden rounded-xl bg-gradient-to-br p-4 text-white shadow-sm {{ $colors }}">
                    <span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/15"></span>
                    <p class="relative text-[11px] font-semibold tracking-wide text-white/95 uppercase">{{ $title }}</p>
                    <p class="relative text-2xl font-bold tabular-nums">{{ $value }}</p>
                </div>
            @endforeach
        </section>

        {{-- Day, week (Monday–Sunday), month or From–To; switching keeps the dates shown as the starting point --}}
        <form method="GET" action="{{ route('cancelled-orders.index') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-brand-200 bg-gradient-to-r from-brand-100 to-brand-50 p-4 shadow-sm" aria-label="Dates and filters">
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
                <div class="flex rounded-lg border border-line bg-white p-0.5" role="group" aria-label="Period">
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
                           @class(['grid h-9 place-items-center rounded-lg border px-3 text-xs font-semibold', 'border-brand-500 bg-brand-50 text-brand-700' => ($filters['status'] ?? '') === $value, 'border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600' => ($filters['status'] ?? '') !== $value])>{{ $name }}</a>
                    @endforeach
                </div>
            </div>
            <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white">Apply</button></noscript>
        </form>

        {{-- Orders that left Canceled / Waiting in these dates (recovered, e.g. to Shipped): they drop off the list below, so they show here --}}
        <x-panel title="Status changes" accent="teal" icon="check" title-id="changes-title" body-class="p-0" :tinted="false">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-teal-700 shadow-sm tabular-nums">{{ number_format($changes->count()) }}</span>
                <span class="text-xs text-muted">Cancelled or waiting orders that moved to another status, as Pancake syncs</span>
            </x-slot:badges>
            @if ($popup->isNotEmpty())
                <x-slot:actions>
                    {{-- Opens the pop-up again: the changes from the last time it showed --}}
                    <button type="button" onclick="document.getElementById('changes-dialog').showModal()" aria-haspopup="dialog"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-[#b9e8de] bg-white px-3 py-1.5 text-xs font-semibold text-teal-700 shadow-sm hover:border-teal-700">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-5-5.9V4a1 1 0 1 0-2 0v1.1A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0a3 3 0 1 1-6 0"/></svg>
                        {{ $unseen->isNotEmpty() ? 'New changes' : 'Last pop-up' }} ({{ $popup->count() }})
                    </button>
                </x-slot:actions>
            @endif
            @if ($changes->isEmpty())
                <p class="px-4 py-5 text-center text-sm text-muted">No cancelled or waiting order changed status in these dates.</p>
            @else
                <div class="max-h-[22rem] overflow-y-auto">
                    @include('cancelled-orders._changes-table', ['changes' => $changes, 'unseenIds' => $unseen->pluck('id')])
                </div>
            @endif
        </x-panel>

        @if ($popup->isNotEmpty())
            {{-- Supervisors and leads: opens by itself with the changes since their last visit; the button above opens it again --}}
            <dialog id="changes-dialog" aria-labelledby="changes-dialog-title" onclick="if (event.target === this) this.close()"
                    class="m-auto max-h-[90dvh] w-[min(56rem,calc(100%-2rem))] overflow-hidden rounded-2xl p-0 shadow-2xl backdrop:bg-ink/40">
                <div class="flex max-h-[90dvh] flex-col">
                    <div class="flex shrink-0 items-start justify-between gap-4 border-b border-[#b9e8de] bg-gradient-to-r from-[#d3f3ec] to-[#ecfaf6] px-5 py-4">
                        <div class="min-w-0">
                            <h2 id="changes-dialog-title" class="text-base font-semibold">{{ $popup->count() }} {{ Str::plural('order', $popup->count()) }} left Cancelled / Waiting {{ $unseen->isNotEmpty() ? 'since your last visit' : '(last pop-up)' }}</h2>
                            <p class="text-sm text-muted">Moved to another status in Pancake (e.g. recovered to Shipped). They stay listed under Status changes.</p>
                        </div>
                        <button type="button" onclick="this.closest('dialog').close()" aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-white hover:text-ink">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        @include('cancelled-orders._changes-table', ['changes' => $popup])
                    </div>
                    <div class="flex shrink-0 justify-end border-t border-line px-5 py-3">
                        <button type="button" onclick="this.closest('dialog').close()" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-[#0b6b5d]">Got it</button>
                    </div>
                </div>
            </dialog>
            @if ($unseen->isNotEmpty())
                <script>document.getElementById('changes-dialog').showModal();</script>
            @endif
        @endif

        <div class="grid items-start gap-4 xl:grid-cols-[25rem_minmax(0,1fr)]">
            {{-- Per day; a day opens that day's orders --}}
            <x-panel title="Per day" accent="coral" icon="calendar" title-id="per-day-title" body-class="p-0" :tinted="false">
                @if ($days->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-muted">None in these dates.</p>
                @else
                    <div class="max-h-[32rem] overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-[#fff7f7] text-xs whitespace-nowrap text-muted">
                                <tr>
                                    <th scope="col" class="px-4 py-2 text-left font-semibold">Day</th>
                                    <th scope="col" class="px-2 py-2 text-right font-semibold"><span aria-hidden="true" class="mr-1 inline-block size-2 rounded-full" style="background: {{ $cancelledColor }}"></span>Cancelled</th>
                                    <th scope="col" class="px-2 py-2 text-right font-semibold"><span aria-hidden="true" class="mr-1 inline-block size-2 rounded-full" style="background: {{ $waitingColor }}"></span>Waiting</th>
                                    <th scope="col" class="px-4 py-2 text-right font-semibold">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line tabular-nums">
                                @foreach ($days as $day => $count)
                                    <tr>
                                        <th scope="row" class="px-4 py-2 text-left font-medium whitespace-nowrap">
                                            <a href="{{ $dayLink($day) }}" class="hover:text-brand-600 hover:underline">{{ \Carbon\CarbonImmutable::parse($day)->format('D, M j') }}</a>
                                        </th>
                                        <td @class(['px-2 py-2 text-right', 'font-semibold text-[#c4484c]' => $count['cancelled'], 'text-muted/60' => ! $count['cancelled']])>{{ number_format($count['cancelled']) }}</td>
                                        <td @class(['px-2 py-2 text-right', 'font-semibold text-[#8a6008]' => $count['waiting'], 'text-muted/60' => ! $count['waiting']])>{{ number_format($count['waiting']) }}</td>
                                        {{-- The day's total, with a small bar (cancelled then waiting) against the busiest day shown --}}
                                        <td class="px-4 py-2 text-right font-semibold" title="₱{{ number_format($count['amount'], 2) }}">
                                            <span class="flex items-center justify-end gap-2">
                                                <span aria-hidden="true" class="flex h-1.5 w-14 gap-px overflow-hidden rounded-full bg-canvas">
                                                    <span class="h-full" style="width: {{ $count['cancelled'] / $busiestDay * 100 }}%; background: {{ $cancelledColor }}"></span>
                                                    <span class="h-full" style="width: {{ $count['waiting'] / $busiestDay * 100 }}%; background: {{ $waitingColor }}"></span>
                                                </span>
                                                {{ number_format($count['total']) }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-panel>

            <x-panel title="Orders" icon="table" title-id="orders-title" body-class="p-0" :tinted="false">
                <x-slot:badges>
                    <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm tabular-nums">{{ number_format($orders->total()) }}</span>
                </x-slot:badges>
                @if ($orders->isEmpty())
                    <p class="px-4 py-10 text-center text-sm text-muted">No cancelled or waiting orders in these dates.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-left text-sm">
                            <thead class="bg-brand-50/60 text-xs tracking-wide text-muted uppercase">
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
                                        <td class="px-4 py-3">{{ $craFor($order)?->displayName() ?? $order->seller_name ?? '—' }}</td>
                                        <td class="px-4 py-3">
                                            {{ $order->customer_name ?: '—' }}
                                            @if ($order->phone_number)
                                                <span class="block text-xs text-muted tabular-nums">{{ $order->phone_number }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            {{-- Orders from before the CRD tags have none --}}
                                            @if ($order->conversion_type)
                                                <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap', 'bg-[#e3f3f9] text-[#0c5a7d]' => $order->conversion_type === 'broadcast', 'bg-brand-100 text-brand-700' => $order->conversion_type !== 'broadcast'])>
                                                    {{ $order->conversion_type === 'broadcast' ? 'Broadcast' : 'Segmentation' }}
                                                </span>
                                            @else
                                                <span class="text-xs text-muted">No tag</span>
                                            @endif
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
            </x-panel>
        </div>
    </div>
</x-layouts.app>
