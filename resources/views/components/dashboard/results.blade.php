@props(['results', 'churn', 'customers' => null, 'query' => []])

@php
    $peso = fn (float $value) => '₱'.number_format($value);
    // Short amounts for tight spots: ₱77k, ₱1.2M.
    $short = fn (float $value) => '₱'.($value >= 1e6 ? rtrim(rtrim(number_format($value / 1e6, 2), '0'), '.').'M' : ($value >= 1e3 ? rtrim(rtrim(number_format($value / 1e3, 1), '0'), '.').'k' : number_format($value)));
    $pct = fn (?float $value, int $decimals = 1) => $value === null ? '—' : number_format($value * 100, $decimals).'%';
    $month = $results['month'];
    $team = $results['team'];
    $ahead = $month['progress'] !== null && $month['pace'] !== null && $month['progress'] >= $month['pace'];
    $own = ! auth()->user()->can('conversion.view_all');
@endphp

{{--
    Results for the dashboard's month (to date) or range: the CRD monthly goal (team gross sales), then
    confirmed orders, conversion rate, AOV and (with Customer Database access) Retained and Repeat
    Customers. A CRA's orders, conversion and AOV are their own. Churn sits apart below: it judges
    customers delivered months earlier whose time to reorder ran out in these dates.
--}}
<div {{ $attributes->merge(['class' => 'space-y-4']) }}>
<section @class(['grid gap-3 sm:grid-cols-2', 'lg:grid-cols-4 2xl:grid-cols-7' => $customers, 'lg:grid-cols-5' => ! $customers]) aria-label="Results">
    {{-- CRD monthly goal --}}
    <article class="relative flex flex-col justify-between gap-3 overflow-hidden rounded-xl bg-gradient-to-br from-brand-600 to-brand-700 p-4 text-white shadow-sm sm:col-span-2">
        <span aria-hidden="true" class="absolute -top-8 -right-8 size-28 rounded-full bg-white/15"></span>
        <div class="relative">
            <div class="flex items-start justify-between gap-2">
                <p class="text-xs font-medium text-white/90">
                    {{ ($month['prorated'] ? 'CRD goal (Gross Sales)' : 'CRD monthly goal (Gross Sales)').' · '.$month['label'] }}
                </p>
                @if ($month['progress'] !== null)
                    <span @class([
                        'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                        'bg-white text-teal-700' => $month['progress'] >= 1 || $ahead,
                        'bg-white text-coral-700' => $month['progress'] < 1 && ! $ahead,
                    ])>{{ $month['progress'] >= 1 ? '✓ Goal hit' : ($month['pace'] === null ? '▼ Below goal' : ($ahead ? '▲ Ahead' : '▼ Behind')) }}</span>
                @endif
            </div>
            <p class="mt-1 text-3xl font-bold tabular-nums">{{ $pct($month['progress']) }}</p>
            {{-- Gross sales so far on the left, the target at the far right --}}
            <p class="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs">
                <span class="text-base font-semibold text-white tabular-nums" title="Gross sales so far">{{ $peso($month['sales']) }}</span>
                <span class="rounded-full bg-white/20 px-2.5 py-0.5 font-medium text-white/90 tabular-nums">Target <span class="font-bold text-white">{{ $peso($month['goal']) }}</span></span>
            </p>
        </div>

        <div class="relative">
            <div class="relative h-2.5 rounded-full bg-white/25" role="progressbar" aria-label="CRD goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round(min(1, $month['progress'] ?? 0) * 100) }}">
                <span class="absolute inset-y-0 left-0 rounded-full bg-white" style="width: {{ min(100, ($month['progress'] ?? 0) * 100) }}%"></span>
                @if ($month['pace'] !== null)
                    {{-- Where sales should be by now if they were spread evenly over the month --}}
                    <span class="absolute -inset-y-1 w-0.5 rounded bg-[#ffe5a0]" style="left: {{ min(100, $month['pace'] * 100) }}%" title="Pace for day {{ $month['day'] }}: {{ $pct($month['pace']) }}"></span>
                @endif
            </div>
            <p class="mt-1.5 flex flex-wrap justify-between gap-x-2 text-[11px] text-white/90">
                @if ($month['pace'] !== null)
                    <span>Day {{ $month['day'] }}/{{ $month['days'] }} · pace <span class="font-semibold text-[#ffe5a0]">{{ $pct($month['pace']) }}</span></span>
                @else
                    <span>Monthly goal prorated to {{ $results['range']->days() }} {{ Str::plural('day', $results['range']->days()) }}</span>
                @endif
                <span @class(['rounded-full bg-white px-2 font-bold', 'text-coral-700' => $month['remaining'] > 0, 'text-teal-700' => $month['remaining'] <= 0])>{{ $month['remaining'] > 0 ? $short($month['remaining']).' to go' : 'Goal reached' }}</span>
            </p>
        </div>
    </article>

    {{-- Confirmed orders (opens the list), conversion rate, AOV: one colour each so they read apart --}}
    @php($tile = 'relative flex min-w-0 flex-col justify-between gap-1 overflow-hidden rounded-xl bg-gradient-to-br p-4 text-white shadow-sm')
    @php($bubble = '<span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/15"></span>')
    @php($label = 'relative text-[11px] font-semibold tracking-wide text-white/95 uppercase')
    @php($note = 'relative text-xs text-white/90 tabular-nums')
    <a href="{{ route('conversion.orders', $query) }}" class="group {{ $tile }} from-[#0e8f7c] to-[#0b6b5d] transition hover:-translate-y-0.5 hover:shadow-lg"
       title="{{ $own ? 'Your' : 'The CRAs\'' }} orders tagged CRD - BROADCAST or CRD - SEGMENTATION (not canceled). Click to see them.">
        {!! $bubble !!}
        <p class="{{ $label }}">{{ $own ? 'Your confirmed orders' : 'Total confirmed orders' }}</p>
        <p class="relative text-3xl font-bold tabular-nums">{{ number_format($team['orders']) }}</p>
        <p class="{{ $note }}">BC {{ number_format($team['bc_orders']) }} · SC {{ number_format($team['sc_orders']) }}</p>
        <p class="relative text-xs font-semibold text-white group-hover:underline">View orders &rarr;</p>
    </a>

    <article class="{{ $tile }} from-[#1f8fb8] to-[#156c8c]" title="(BC + SC orders) ÷ (engagements + leads)">
        {!! $bubble !!}
        <p class="{{ $label }}">{{ $own ? 'Your conversion rate' : 'Conversion rate' }}</p>
        <p class="relative text-3xl font-bold tabular-nums">{{ $pct($team['total_rate'], 2) }}</p>
        <p class="{{ $note }}">{{ number_format($team['orders']) }} of {{ number_format($team['reach']) }} engagements + leads</p>
    </article>

    {{-- Wide on four columns so AOV, Retained and Repeat fill the second row --}}
    <article @class([$tile, 'from-[#d99a0b] to-[#a8740a]', 'lg:col-span-2 2xl:col-span-1' => $customers]) title="Average order value: gross sales ÷ confirmed orders">
        {!! $bubble !!}
        <p class="{{ $label }}">AOV</p>
        <p class="relative text-3xl font-bold tabular-nums">{{ $team['orders'] ? '₱'.number_format($team['gross'] / $team['orders']) : '—' }}</p>
        <p class="{{ $note }}">{{ $peso((float) $team['gross']) }} ÷ {{ number_format($team['orders']) }} orders</p>
    </article>

    @if ($customers)
        {{-- Customers delivered in these dates, counted as in the Customer Database; opens it filtered the same way --}}
        @php($customerRange = ['from' => $results['range']->from->toDateString(), 'to' => $results['range']->to->toDateString()])
        <a href="{{ route('customers.index', [...$customerRange, 'segment' => 'retained']) }}" class="group {{ $tile }} from-[#4f46e5] to-[#3730a3] transition hover:-translate-y-0.5 hover:shadow-lg"
           title="Customers whose delivery in these dates is their first CRA-handled order ever. Click to see them.">
            {!! $bubble !!}
            <p class="{{ $label }}">Retained</p>
            <p class="relative text-3xl font-bold tabular-nums">{{ number_format($customers['retained']) }}</p>
            <p class="{{ $note }}">First CRA-handled order</p>
            <p class="relative text-xs font-semibold text-white group-hover:underline">View customers &rarr;</p>
        </a>

        <a href="{{ route('customers.index', [...$customerRange, 'segment' => 'repeat']) }}" class="group {{ $tile }} from-[#c026d3] to-[#86198f] transition hover:-translate-y-0.5 hover:shadow-lg"
           title="Customers delivered in these dates who had an earlier CRA-handled order too. Click to see them.">
            {!! $bubble !!}
            <p class="{{ $label }}">Repeat customers</p>
            <p class="relative text-3xl font-bold tabular-nums">{{ number_format($customers['repeat']) }}</p>
            <p class="{{ $note }}">Earlier CRA order too</p>
            <p class="relative text-xs font-semibold text-white group-hover:underline">View customers &rarr;</p>
        </a>
    @endif
</section>

{{-- Churn: its own block so it isn't read as these dates' customers; any tile opens the CRD / FSD / overall breakdown --}}
@php($deadlines = $results['range']->label())
@php($monthLabel = fn (string $month) => \Carbon\CarbonImmutable::parse($month.'-01')->format($results['range']->from->isSameYear($month.'-01') ? 'M' : 'M Y'))
@php($churnTeams = ['all' => 'Overall', 'crd' => 'CRD', 'fsd' => 'FSD'])
<section aria-labelledby="churn-title">
    <div class="mb-2 flex flex-wrap items-center gap-2">
        <h3 id="churn-title" class="text-sm font-bold text-ink">Customer churn</h3>
        <span class="rounded-full bg-[#fde0e0] px-2.5 py-0.5 text-xs font-semibold text-coral-700">Reorder deadline {{ $deadlines }}</span>
    </div>

    <div @class(['grid gap-3 sm:grid-cols-2', 'lg:grid-cols-4 2xl:grid-cols-7' => $customers, 'lg:grid-cols-5' => ! $customers])>
        @foreach ($churnTeams as $team => $teamLabel)
            @php($teamChurn = $churn[$team])
            <button type="button" onclick="document.getElementById('churn-dialog').showModal()" aria-haspopup="dialog"
                    @class([$tile, 'group text-left transition hover:-translate-y-0.5 hover:shadow-lg', 'from-[#e05a5f] to-[#c4484c]' => $team === 'all', 'from-[#d9677a] to-[#b04a5c]' => $team !== 'all'])
                    title="{{ $team === 'all' ? 'CRD and FSD' : $teamLabel }}-delivered customers from earlier months whose {{ $churn['grace_days'] }} days to reorder ended {{ $deadlines }}. Click for the breakdown.">
                {!! $bubble !!}
                <p class="{{ $label }}">{{ $team === 'all' ? 'Overall churn rate' : $teamLabel.' churn rate' }}</p>
                <p class="relative text-3xl font-bold tabular-nums">{{ $pct($teamChurn['rate'], 2) }}</p>
                <p class="{{ $note }}">{{ number_format($teamChurn['lost']) }} lost of {{ number_format($teamChurn['customers']) }}</p>
                <p class="relative text-xs font-semibold text-white group-hover:underline">View breakdown &rarr;</p>
            </button>
        @endforeach
    </div>

    <dialog id="churn-dialog" aria-labelledby="churn-dialog-title" onclick="if (event.target === this) this.close()"
            class="m-auto max-h-[90dvh] w-[min(44rem,calc(100%-2rem))] overflow-hidden rounded-2xl p-0 shadow-2xl backdrop:bg-ink/40">
        <div class="flex max-h-[90dvh] flex-col">
            <div class="flex shrink-0 items-start justify-between gap-4 border-b border-line px-5 py-4">
                <div class="min-w-0">
                    <h2 id="churn-dialog-title" class="text-base font-semibold">Customer churn · reorder deadline {{ $deadlines }}</h2>
                    <p class="text-sm text-muted">
                        Not the customers of {{ $deadlines }}: CRD- and FSD-delivered customers from earlier months who ran out, and whose {{ $churn['grace_days'] }} days to reorder ended in these dates.
                        Overall counts a customer on both lists once. Lower is better.
                    </p>
                </div>
                <button type="button" onclick="this.closest('dialog').close()" aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            {{-- Due / came back / lost counts open those customers in Customer Database → Churn, for the same dates --}}
            @php($churnLinks = auth()->user()->can('customers.view'))
            @php($churnStatus = ['Due to reorder' => 'due', 'Came back in time' => 'back', 'Lost' => 'lost'])
            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                <div class="overflow-x-auto rounded-xl border border-line">
                    <table class="w-full text-sm">
                        <thead class="bg-canvas/60 text-xs text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-2 text-left font-semibold"></th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">CRD</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">FSD</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold text-ink">Overall</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line tabular-nums">
                            @foreach ([
                                'Churn rate' => fn ($c) => $pct($c['rate'], 2),
                                'Due to reorder' => fn ($c) => number_format($c['customers']),
                                'Came back in time' => fn ($c) => number_format($c['customers'] - $c['lost']),
                                'Lost' => fn ($c) => number_format($c['lost']),
                            ] as $row => $value)
                                <tr>
                                    <th scope="row" class="px-4 py-2 text-left font-medium">{{ $row }}</th>
                                    @foreach (['crd', 'fsd', 'all'] as $team)
                                        <td @class(['px-4 py-2 text-right', 'font-semibold' => $team === 'all', 'text-coral-700' => $row === 'Churn rate', 'text-teal-700' => $row === 'Came back in time'])>
                                            @if ($churnLinks && isset($churnStatus[$row]))
                                                <a href="{{ route('customers.churn', [...$query, 'status' => $churnStatus[$row], 'list' => $team]) }}" class="hover:underline" title="See these customers">{{ $value($churn[$team]) }}</a>
                                            @else
                                                {{ $value($churn[$team]) }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="max-w-prose text-xs text-muted">Due = their {{ $churn['grace_days'] }} days to reorder ended {{ $deadlines }}. Came back = a Pancake order (not canceled) or a new delivery in that time.</p>
                    @if ($churnLinks)
                        <a href="{{ route('customers.churn', $query) }}" class="shrink-0 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">See customers &rarr;</a>
                    @endif
                </div>

                <div class="rounded-xl border border-line p-4">
                    <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">Delivered in</p>
                    <dl class="mt-2 space-y-2 text-xs">
                        @foreach (['crd' => 'CRD', 'fsd' => 'FSD'] as $team => $teamLabel)
                            <div class="flex flex-wrap items-center gap-1.5">
                                <dt class="w-10 font-semibold text-ink">{{ $teamLabel }}</dt>
                                @forelse ($churn[$team]['delivered_months'] as $month => $count)
                                    <dd class="rounded-full bg-brand-50 px-2 py-0.5 text-ink tabular-nums">{{ $monthLabel($month) }} <span class="font-semibold">{{ number_format($count) }}</span></dd>
                                @empty
                                    <dd class="text-muted">No customers due in these dates.</dd>
                                @endforelse
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        </div>
    </dialog>
</section>
</div>
