@php
    $peso = fn (float $value) => '₱'.number_format($value);
    $pct = fn (?float $value) => $value === null ? '—' : number_format(min($value, 9.99) * 100).'%';
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $first = strtok($cra->displayName(), ' ') ?: $cra->displayName();
    $isOwn = $cra->id === auth()->id();
    $hasOpened = ! $realToday->lessThan($firstOpening);
    $query = fn (array $extra) => route('cra-dashboard', array_filter([...$filters, ...$extra], fn ($v) => $v !== null && $v !== '' && $v !== 'all'));
@endphp

<x-layouts.app title="CRA Dashboard">
    <div class="space-y-6">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $isOwn ? 'Hi, '.$first.'!' : $cra->displayName() }}</h1>
                <p class="text-sm text-muted">{{ $realToday->format('l, F j') }} · leads of {{ $pending['lead_day']->format('M j') }}</p>
            </div>
            @if ($cras->isNotEmpty())
                <form method="GET" action="{{ route('cra-dashboard') }}" class="flex items-center gap-2">
                    <label for="cra" class="text-sm text-muted">CRA</label>
                    <select id="cra" name="cra" class="{{ $control }}" onchange="this.form.submit()">
                        @foreach ($cras as $option)
                            <option value="{{ $option->id }}" @selected($option->id === $cra->id)>{{ $option->displayName() }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
        </header>

        {{-- Supervisors: customers whose CRAs disagree (LeadConflicts) --}}
        @if ($conflicts !== [])
            <x-panel title="Check who gets these leads" icon="users" accent="coral" bodyClass="p-0">
                <x-slot:actions>
                    <span class="font-semibold text-coral-700">{{ number_format(count($conflicts)) }} {{ str('customer')->plural(count($conflicts)) }}</span>
                </x-slot:actions>
                <p class="p-4 text-sm text-muted">These customers have an open lead, but their CRAs disagree: their High AOV / VIP CRA isn't the CRA with the most orders, or someone else claimed them. The rules go claim first, then High AOV / VIP CRA, then most orders; check the lead is with the right CRA.@if ($canAssign) Pick the CRA and press Assign (choose the CRA who has it to keep it there); the customer then leaves the list.@endif Updates every 10 minutes.</p>
                @error('assign')
                    <div role="alert" class="mx-4 mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $message }}</div>
                @enderror
                <div class="max-h-[28rem] overflow-auto border-t border-line">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 bg-[#fff7f7] text-xs whitespace-nowrap text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Customer</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Contact #</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Lead day</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Lead is with</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Claimed by</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">High AOV / VIP CRA</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Most orders</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Rules give it to</th>
                                @if ($canAssign)
                                    <th scope="col" class="px-4 py-2.5 font-semibold">Assign to</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line bg-white">
                            @foreach ($conflicts as $row)
                                <tr>
                                    <td class="px-4 py-2.5 font-medium text-ink">{{ $row['customer_name'] }}</td>
                                    <td class="px-4 py-2.5 tabular-nums">{{ $row['phone_number'] }}</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap tabular-nums">{{ \Carbon\CarbonImmutable::parse($row['listed_on'])->format('M j, Y') }}</td>
                                    <td @class(['px-4 py-2.5', 'font-semibold text-coral-700' => $row['holder_id'] !== $row['rules_id']])>{{ $craNames[$row['holder_id']] ?? 'Pool' }}</td>
                                    <td class="px-4 py-2.5">{{ $craNames[$row['claim_id']] ?? '—' }}</td>
                                    <td class="px-4 py-2.5">{{ isset($row['high_value_id']) ? ($craNames[$row['high_value_id']] ?? 'Former CRA').' · '.$row['high_value_label'] : '—' }}</td>
                                    <td class="px-4 py-2.5">{{ isset($row['orders_id']) ? ($craNames[$row['orders_id']] ?? 'Former CRA').' · '.$row['orders_count'].' '.str('order')->plural($row['orders_count']) : '—' }}</td>
                                    <td class="px-4 py-2.5 font-semibold text-ink">{{ $craNames[$row['rules_id']] ?? '—' }}</td>
                                    @if ($canAssign)
                                        <td class="px-4 py-2">
                                            <form method="POST" action="{{ route('cra-dashboard.assign', $row['lead_id']) }}" class="flex items-center gap-2">
                                                @csrf
                                                <label for="assign-{{ $row['lead_id'] }}" class="sr-only">Assign {{ $row['customer_name'] }}'s lead to</label>
                                                <select id="assign-{{ $row['lead_id'] }}" name="to" required class="h-8 rounded-lg border border-line bg-white px-2 text-xs text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                                    <option value="" disabled @selected(! $assignCras->contains('id', $row['rules_id']))>Choose CRA</option>
                                                    @foreach ($assignCras as $option)
                                                        <option value="{{ $option->id }}" @selected($option->id === $row['rules_id'])>{{ $option->displayName() }}{{ $option->id === $row['holder_id'] ? ' (has it)' : '' }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="h-8 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white hover:bg-brand-700">Assign</button>
                                            </form>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endif

        {{-- Today --}}
        <section aria-label="Today" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <a href="{{ $pending['url'] }}" class="relative flex flex-col justify-between gap-3 overflow-hidden rounded-xl bg-gradient-to-br from-brand-600 to-brand-700 p-4 text-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg">
                <span aria-hidden="true" class="absolute -top-8 -right-8 size-28 rounded-full bg-white/15"></span>
                <p class="relative text-xs font-medium text-white/90">Pending today</p>
                <p class="relative text-3xl font-bold tabular-nums">{{ number_format($pending['unprocessed']) }}</p>
                <p class="relative text-xs text-white/90">of {{ number_format($pending['total']) }} leads · open {{ $pending['tracker'] }} →</p>
            </a>

            <article class="flex flex-col justify-between gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-line">
                <p class="text-xs font-medium text-muted">Running sales today</p>
                <p class="text-3xl font-bold text-ink tabular-nums">{{ $peso($sales['today']) }}</p>
                <div>
                    <div class="h-2 overflow-hidden rounded-full bg-brand-50" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round(min($sales['progress'] ?? 0, 1) * 100) }}" aria-label="Daily goal progress">
                        <div class="h-full rounded-full bg-teal" style="width: {{ min($sales['progress'] ?? 0, 1) * 100 }}%"></div>
                    </div>
                    <p class="mt-1.5 text-xs text-muted">
                        @if ($sales['daily_goal'] <= 0)
                            No daily goal today
                        @elseif ($sales['left_today'] <= 0)
                            <span class="font-semibold text-teal-700">✓ Daily goal of {{ $peso($sales['daily_goal']) }} hit</span>
                        @else
                            <span class="font-semibold text-coral-700">{{ $peso($sales['left_today']) }} to go</span> · goal {{ $peso($sales['daily_goal']) }}
                        @endif
                    </p>
                </div>
            </article>

            <article class="flex flex-col justify-between gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-line">
                <p class="text-xs font-medium text-muted">Net income</p>
                <p @class(['text-3xl font-bold tabular-nums', 'text-ink' => $netIncome['today'] >= 0, 'text-coral-700' => $netIncome['today'] < 0])>{{ $peso($netIncome['today']) }}</p>
                <p class="text-xs text-muted">today · {{ $peso($netIncome['month']) }} this month</p>
            </article>

            <article class="flex flex-col justify-between gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-line">
                <p class="text-xs font-medium text-muted">Sales this month</p>
                <p class="text-3xl font-bold text-ink tabular-nums">{{ $peso($sales['month']) }}</p>
                <p class="text-xs text-muted">{{ $pct($sales['month_progress']) }} of {{ $peso($sales['month_goal']) }} goal so far</p>
            </article>
        </section>

        <section aria-label="Your customers" class="grid gap-3 sm:grid-cols-3">
            <a href="{{ route('customers.high-value', ['list' => 'high_aov', 'cra' => $cra->id]) }}" class="relative flex items-center justify-between gap-3 overflow-hidden rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 p-4 text-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg">
                <span class="text-[0.6875rem] font-semibold tracking-wide uppercase">High AOV customers</span>
                <span class="text-2xl font-bold tabular-nums">{{ number_format($highValue['high_aov']) }}</span>
            </a>
            <a href="{{ route('customers.high-value', ['list' => 'vip', 'cra' => $cra->id]) }}" class="relative flex items-center justify-between gap-3 overflow-hidden rounded-xl bg-gradient-to-br from-[#2a2723] to-[#121110] p-4 text-[#f3dca3] shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg">
                <span class="text-[0.6875rem] font-semibold tracking-wide uppercase">VIP customers</span>
                <span class="text-2xl font-bold tabular-nums">{{ number_format($highValue['vip']) }}</span>
            </a>
            <article @class(['flex items-center justify-between gap-3 rounded-xl p-4 shadow-sm ring-1', 'bg-[#fff7f7] ring-[#f8c9ca]' => $atRisk['open'] + $atRisk['soon'] > 0, 'bg-white ring-line' => $atRisk['open'] + $atRisk['soon'] === 0])>
                <span>
                    <span class="block text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">Your customers at risk</span>
                    <span class="block text-xs text-muted">{{ number_format($atRisk['open']) }} open to other CRAs · {{ number_format($atRisk['soon']) }} within {{ \App\Services\CustomerReassignment::SOON_DAYS }} days</span>
                </span>
                <span class="text-2xl font-bold text-coral-700 tabular-nums">{{ number_format($atRisk['open'] + $atRisk['soon']) }}</span>
            </article>
        </section>

        {{-- Up for reassignment --}}
        <x-panel title="Up for reassignment" icon="users" accent="coral" bodyClass="p-0">
            <div class="space-y-3 p-4">
                <p class="text-sm text-muted">Customers who didn't order again within {{ $afterDays }} days of their last delivery. Claim one and they're yours, with their leads nobody has contacted yet, until they go {{ $afterDays }} days without a reorder again. Updates every 10 minutes.</p>

                @error('claim')
                    <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $message }}</div>
                @enderror

                <form method="GET" action="{{ route('cra-dashboard') }}" class="flex flex-wrap items-center gap-2">
                    @if ($filters['cra'])
                        <input type="hidden" name="cra" value="{{ $filters['cra'] }}">
                    @endif
                    <label for="q" class="sr-only">Search name or contact number</label>
                    <input id="q" name="q" type="search" value="{{ $filters['q'] }}" placeholder="Search name or contact #" class="{{ $control }} w-full sm:w-64">
                    <label for="show" class="sr-only">Show</label>
                    <select id="show" name="show" class="{{ $control }}" onchange="this.form.submit()">
                        @foreach (\App\Services\HighValueCustomers::SHOW as $value => $label)
                            <option value="{{ $value }}" @selected($filters['show'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
                </form>
            </div>

            @if ($customers->isEmpty())
                <p class="border-t border-line px-4 py-8 text-center text-sm text-muted">
                    @if (! $hasOpened)
                        No customers yet. The {{ $afterDays }}-day rule counts deliveries from {{ \App\Services\CustomerReassignment::startsOn()->format('M j') }} on, so the first customers open up on {{ $firstOpening->format('M j, Y') }}.
                    @elseif ($filters['q'] || $filters['show'] !== 'all')
                        No customers up for reassignment match.
                    @else
                        No customers up for reassignment right now.
                    @endif
                </p>
            @else
                <div class="overflow-x-auto border-t border-line">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-brand-50/60 text-xs text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Customer</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Contact #</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">CRA now</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Last delivered</th>
                                <th scope="col" class="px-4 py-2.5 font-semibold">Open since</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-semibold">Total spent</th>
                                <th scope="col" class="px-4 py-2.5"><span class="sr-only">Claim</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line bg-white">
                            @foreach ($customers as $row)
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <span class="font-medium text-ink">{{ $row['customer_name'] }}</span>
                                        @if ($row['vip'])
                                            <span class="ml-1 rounded-full bg-[#2a2723] px-2 py-0.5 text-[0.625rem] font-semibold text-[#f3dca3]">VIP</span>
                                        @endif
                                        @if ($row['high_aov'])
                                            <span class="ml-1 rounded-full bg-brand-100 px-2 py-0.5 text-[0.625rem] font-semibold text-brand-700">HIGH AOV</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 tabular-nums">{{ $row['phone_number'] }}</td>
                                    <td class="px-4 py-2.5">{{ $craNames[$row['cra_id']] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 tabular-nums">{{ $row['last_delivered'] ? \Carbon\CarbonImmutable::parse($row['last_delivered'])->format('M j, Y') : '—' }}</td>
                                    <td class="px-4 py-2.5 tabular-nums">{{ \Carbon\CarbonImmutable::parse($row['opens_on'])->format('M j, Y') }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $peso($row['total_spent']) }}</td>
                                    <td class="px-4 py-2.5 text-right">
                                        @if ($canClaim)
                                            <form method="POST" action="{{ route('cra-dashboard.claim') }}" onsubmit="return confirm('Claim {{ addslashes($row['customer_name']) }}? They and their uncontacted leads become yours.')">
                                                @csrf
                                                <input type="hidden" name="phone_key" value="{{ $row['phone_key'] }}">
                                                <input type="hidden" name="name" value="{{ $row['customer_name'] }}">
                                                <button type="submit" class="h-8 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white hover:bg-brand-700">Claim</button>
                                            </form>
                                        @endif
                                    </td>
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
    </div>
</x-layouts.app>
