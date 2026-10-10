@php
    $query = fn (array $extra) => route('customers.churn', array_filter([...$filters, ...$extra], fn ($v) => $v !== null && $v !== ''));
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $status = $filters['status'];
    $date = fn (?string $day) => $day ? \Carbon\CarbonImmutable::parse($day)->format('M j, Y') : '—';
    $deadlines = $range->custom
        ? ($range->from->equalTo($range->to) ? $range->from->format('M j, Y') : $range->from->format('M j').' – '.$range->to->format('M j, Y'))
        : $range->month->format('F Y').($range->month->isSameMonth($today) ? ' (to date)' : '');
    $extra = ['due' => 'Ran out → reorder by', 'back' => 'Came back with', 'lost' => 'Last ordered'][$status];
@endphp

<x-layouts.app title="Customer Churn">
    <div class="space-y-4">
        @include('customers._tabs')

        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Customer churn <span class="text-sm font-normal text-muted">· reorder deadline {{ $deadlines }}</span></h1>
                <p class="text-xs text-muted">Customers delivered in earlier months who ran out, and whose {{ $graceDays }} days to reorder ended in these dates. Same numbers as the dashboard's churn rate.</p>
            </div>

            {{-- Same month / From–To dates as the dashboard --}}
            <form method="GET" action="{{ route('customers.churn') }}" class="flex flex-wrap items-center gap-2" aria-label="Churn month and dates">
                <input type="hidden" name="status" value="{{ $status }}">
                <input type="hidden" name="list" value="{{ $filters['list'] }}">
                <label>
                    <span class="sr-only">Month</span>
                    <select name="month" onchange="this.form.from.value = ''; this.form.to.value = ''; this.form.submit()"
                            @class([$control, 'font-semibold', 'border-brand-500 ring-2 ring-brand-200' => ! $range->custom])>
                        @for ($m = 1; $m <= 12; $m++)
                            @php($option = $today->setDate($today->year, $m, 1))
                            <option value="{{ $option->format('Y-m') }}" @selected(! $range->custom && $range->month->isSameMonth($option)) @disabled($option->greaterThan($today))>{{ $option->format('F') }}</option>
                        @endfor
                        @if ($range->custom)
                            <option value="" selected disabled>Custom dates</option>
                        @endif
                    </select>
                </label>
                <div class="flex items-center gap-1.5">
                    <input type="date" name="from" value="{{ $range->custom ? $range->from->toDateString() : '' }}" max="{{ $today->toDateString() }}" aria-label="From"
                           onchange="this.form.month.value = ''; this.form.submit()" @class([$control, 'border-brand-500 ring-2 ring-brand-200' => $range->custom])>
                    <span class="text-sm text-muted">to</span>
                    <input type="date" name="to" value="{{ $range->custom ? $range->to->toDateString() : '' }}" max="{{ $today->toDateString() }}" aria-label="To"
                           onchange="this.form.month.value = ''; this.form.submit()" @class([$control, 'border-brand-500 ring-2 ring-brand-200' => $range->custom])>
                </div>
                <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white">Apply</button></noscript>
            </form>
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        {{-- Tiles filter the list; the one in use sits raised with a "Showing" pill --}}
        <section aria-label="Churn counts" class="grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['due', 'Due to reorder', $counts['due'], 'from-[#4f5563] to-[#343a40]', "Their {$graceDays} days to reorder ended in these dates"],
                ['back', 'Came back in time', $counts['back'], 'from-[#0e8f7c] to-[#0b6b5d]', 'A Pancake order (not canceled) or a new delivery in time'],
                ['lost', 'Lost', $counts['lost'], 'from-[#e05a5f] to-[#c4484c]', 'No order and no delivery in time'],
            ] as [$key, $label, $value, $gradient, $hint])
                <a href="{{ $query(['status' => $key, 'page' => null]) }}" title="{{ $hint }}. Click to filter." @if ($status === $key) aria-current="true" @endif
                   @class(['relative flex flex-col gap-0.5 overflow-hidden rounded-xl bg-gradient-to-br p-4 text-white transition', $gradient, '-translate-y-1.5 shadow-xl' => $status === $key, 'shadow-sm hover:-translate-y-0.5 hover:shadow-lg' => $status !== $key])>
                    <span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/15"></span>
                    <span class="relative flex items-center justify-between text-[11px] font-semibold tracking-wide uppercase">
                        {{ $label }}
                        @if ($status === $key)
                            <span class="rounded-full bg-white px-1.5 text-[10px] text-ink normal-case">Showing</span>
                        @endif
                    </span>
                    <span class="relative text-2xl font-bold tabular-nums">{{ number_format($value) }}</span>
                    <span class="relative text-xs text-white/90">
                        {{ $key === 'due' ? $hint : ($counts['due'] ? number_format($value / $counts['due'] * 100, 2).'% of due' : '—') }}
                    </span>
                </a>
            @endforeach
        </section>

        <x-panel :title="\App\Services\ChurnCustomerList::STATUSES[$status]" icon="users" title-id="churn-list-title" body-class="p-0">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-brand-700 tabular-nums shadow-sm">{{ number_format($customers->total()) }}</span>
            </x-slot:badges>
            <x-slot:actions>
                {{-- Overall, or only the CRD or FSD list (as in the dashboard's three tiles) --}}
                <nav class="flex overflow-hidden rounded-lg border border-line bg-white" aria-label="Team">
                    @foreach (\App\Services\ChurnCustomerList::LISTS as $key => $label)
                        <a href="{{ $query(['list' => $key, 'page' => null]) }}" @if ($filters['list'] === $key) aria-current="true" @endif
                           @class(['px-3 py-1.5 font-semibold', 'bg-brand-600 text-white' => $filters['list'] === $key, 'text-muted hover:text-brand-600' => $filters['list'] !== $key])>{{ $label }}</a>
                    @endforeach
                </nav>
            </x-slot:actions>

            @if ($customers->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">No customers here for these dates.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[960px] text-left text-sm">
                        <thead class="bg-[#f7f4f8] text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Customer name</th>
                                <th class="px-4 py-3 font-semibold">Products ordered</th>
                                <th class="px-4 py-3 text-right font-semibold">Total spent</th>
                                <th class="px-4 py-3 font-semibold">CRD / FSD</th>
                                <th class="px-4 py-3 font-semibold">{{ $extra }}</th>
                                @if ($status === 'lost')
                                    <th class="px-4 py-3 font-semibold">Why no reorder (tracker)</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($customers as $customer)
                                <tr class="align-top">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('customers.index', ['search' => $customer['phone_key']]) }}" class="font-medium hover:text-brand-600 hover:underline"
                                           title="Open in the Customer Database">{{ $customer['name'] }}</a>
                                        <span class="block text-xs text-muted tabular-nums">{{ $customer['phone'] }}</span>
                                        @if ($status === 'due')
                                            <span @class(['mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold', 'bg-teal/15 text-teal-700' => $customer['back'], 'bg-coral/15 text-coral-700' => ! $customer['back']])>{{ $customer['back'] ? 'Came back' : 'Lost' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="flex flex-wrap gap-1">
                                            @foreach ($customer['products'] as $product)
                                                <span class="rounded-full bg-canvas px-2 py-0.5 text-xs">{{ $product }}</span>
                                            @endforeach
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">₱{{ number_format($customer['total_spent'], 2) }}</td>
                                    <td class="px-4 py-3">
                                        <span @class(['rounded-full px-2 py-0.5 text-[11px] font-semibold', 'bg-brand-100 text-brand-700' => $customer['team'] === 'crd', 'bg-sky/20 text-[#156c8c]' => $customer['team'] === 'fsd'])>{{ strtoupper($customer['team']) }}</span>
                                        <span class="mt-1 block text-xs text-muted">Delivered {{ $date($customer['delivered']) }}</span>
                                    </td>
                                    <td class="px-4 py-3 tabular-nums">
                                        @if ($status === 'due')
                                            {{ $date($customer['ran_out']) }} → <span class="font-semibold">{{ $date($customer['deadline']) }}</span>
                                        @elseif ($status === 'back')
                                            <span class="font-semibold">{{ $customer['back']['source'] === 'order' ? 'Order' : 'Delivery' }} {{ $customer['back']['order_id'] }}</span>
                                            <span class="block text-xs text-muted">{{ $date($customer['back']['date']) }} · deadline {{ $date($customer['deadline']) }}</span>
                                        @else
                                            <span class="font-semibold">{{ $date($customer['last_order']['date']) }}</span>
                                            <span class="block text-xs text-muted">{{ $customer['last_order']['source'] === 'order' ? 'Order' : 'Delivery' }} {{ $customer['last_order']['order_id'] }}{{ $customer['last_order']['date'] > $customer['deadline'] ? ' · after the deadline' : '' }}</span>
                                        @endif
                                    </td>
                                    @if ($status === 'lost')
                                        <td class="px-4 py-3 text-xs">
                                            @if ($lead = $customer['lead'])
                                                @if ($lead['feedback'] || $lead['status'])
                                                    <span class="font-semibold text-ink">{{ $lead['feedback'] ?? $lead['status'] }}</span>
                                                    @if ($lead['feedback'] && $lead['status'])
                                                        <span class="text-muted">· {{ $lead['status'] }}</span>
                                                    @endif
                                                    <span class="block text-muted">{{ collect([$lead['tag'], $lead['contacted'] ? 'contacted '.$date($lead['contacted']) : null])->filter()->join(' · ') }}</span>
                                                    @if ($lead['notes'])
                                                        <span class="mt-0.5 block text-muted italic" title="{{ $lead['notes'] }}">“{{ Str::limit($lead['notes'], 80) }}”</span>
                                                    @endif
                                                @else
                                                    <span class="text-muted">Lead not worked yet</span>
                                                @endif
                                            @else
                                                <span class="text-muted">No tracker lead</span>
                                            @endif
                                        </td>
                                    @endif
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

        <details class="rounded-xl bg-white p-4 text-sm shadow-sm">
            <summary class="cursor-pointer font-semibold">How the numbers are worked out</summary>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-muted sm:grid-cols-2">
                <div><dt class="font-semibold text-ink">Due to reorder</dt><dd>A delivered customer runs out on delivered date + qty × consumption days − 1, then has {{ $graceDays }} days to order again. Due = customers whose {{ $graceDays }} days ended in the dates picked, by their latest such delivery.</dd></div>
                <div><dt class="font-semibold text-ink">Came back in time / Lost</dt><dd>Came back = a Pancake order (not canceled) or a new delivery after that delivery and by the deadline; the first one is shown. Lost = neither.</dd></div>
                <div><dt class="font-semibold text-ink">CRD / FSD / Overall</dt><dd>CRD = the CRD-delivered list; FSD = FSD deliveries whose qty is known from Pancake; Overall counts a customer on both lists once, by their latest delivery.</dd></div>
                <div><dt class="font-semibold text-ink">Last ordered</dt><dd>The customer's latest order or delivery since: the delivery judged if nothing came after, or a later one placed after the deadline.</dd></div>
                <div><dt class="font-semibold text-ink">Why no reorder</dt><dd>What the CRA recorded on the customer's latest Segmentation Tracker lead from that delivery on: Customer's feedback, status, tag, contact date and notes.</dd></div>
                <div><dt class="font-semibold text-ink">Products ordered · Total spent</dt><dd>Every product delivered to the customer since the Customer Database's first day, and their delivered orders' Pancake totals.</dd></div>
            </dl>
        </details>
    </div>
</x-layouts.app>
