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
    $cancelled = $results['cancelled'];

    // The graphs on the tiles' backs, one value per day of these dates.
    $daily = collect($results['daily']);
    $formatCount = fn (float $value) => number_format($value);
    $graphShort = fn (float $value) => $short($value);
    $graphPct = fn (float $value) => $pct($value, $value < 0.1 ? 1 : 0);
    // CRD goal: every CRA's sales added up day by day against an even pace to the goal; a month runs to its last day.
    $goalDays = $month['prorated'] ? $daily->keys() : collect(range(1, $month['days']))->map(fn ($day) => $results['range']->month->setDay($day)->toDateString());
    $running = 0;
    $cumulative = $goalDays->mapWithKeys(function ($day) use ($daily, &$running) {
        if (! $daily->has($day)) {
            return [$day => null];
        }
        $running += $daily[$day]['all_gross'];

        return [$day => $running];
    })->all();
    $pace = $goalDays->values()->mapWithKeys(fn ($day, $i) => [$day => $month['goal'] * ($i + 1) / $goalDays->count()])->all();
    $cancelledDays = $daily->keys()->mapWithKeys(fn ($day) => [$day => $cancelled['days'][$day] ?? ['cancelled' => 0, 'waiting' => 0]]);
@endphp

{{--
    Results for the dashboard's month (to date) or range: the CRD monthly goal (team gross sales), then
    confirmed orders, conversion rate, AOV beside Cancelled / Waiting, and (with Customer Database access) Retained and Repeat
    Customers. A CRA's orders, conversion and AOV are their own. Churn sits apart in a small, light card
    on the right (below on narrow screens), so the results stay the focus: it judges customers delivered
    months earlier whose time to reorder ran out in these dates.
--}}
<div {{ $attributes->merge(['class' => 'grid gap-4 xl:grid-cols-[minmax(0,1fr)_15rem]']) }}>
{{-- With customers: eight columns, so the goal, orders and conversion fill the first row and AOV (a quarter),
     Retained and Repeat (three eighths each) the second; six on tablets, so orders, conversion and AOV share a row --}}
<section @class(['grid content-start gap-3', 'sm:grid-cols-6 lg:grid-cols-8' => $customers, 'sm:grid-cols-2 lg:grid-cols-5' => ! $customers]) aria-label="Results">
    {{-- CRD monthly goal --}}
    <x-dashboard.flip :class="$customers ? 'sm:col-span-6 lg:col-span-4' : 'sm:col-span-2'">
    <article class="relative flex flex-col justify-between gap-3 overflow-hidden rounded-xl bg-gradient-to-br from-brand-600 to-brand-700 p-4 text-white shadow-sm">
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
        <x-slot:back>
            <x-dashboard.trend title="Gross sales vs goal" :series="[['label' => 'Gross sales', 'color' => '#8b3ff0', 'values' => $cumulative]]" :format="$graphShort"
                :target="['label' => 'Even pace to goal', 'values' => $pace]"
                :legend="[['Sales', '#8b3ff0', $short($month['sales']), false], ['Goal', '#4f5563', $short($month['goal']), true]]" />
        </x-slot:back>
    </x-dashboard.flip>

    {{-- Confirmed orders (opens the list), conversion rate, AOV: one colour each so they read apart --}}
    @php($tile = 'relative flex min-w-0 flex-col justify-between gap-1 overflow-hidden rounded-xl bg-gradient-to-br p-4 text-white shadow-sm')
    @php($bubble = '<span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/15"></span>')
    @php($label = 'relative text-[11px] font-semibold tracking-wide text-white/95 uppercase')
    @php($note = 'relative text-xs text-white/90 tabular-nums')
    @php($span = $customers ? 'sm:col-span-2' : '')
    <x-dashboard.flip :class="$span">
    <a href="{{ route('conversion.orders', $query) }}" class="group {{ $tile }} from-[#0e8f7c] to-[#0b6b5d] transition hover:-translate-y-0.5 hover:shadow-lg"
       title="{{ $own ? 'Your' : 'The CRAs\'' }} orders tagged CRD - BROADCAST or CRD - SEGMENTATION (not canceled). Click to see them.">
        {!! $bubble !!}
        <p class="{{ $label }}">{{ $own ? 'Your confirmed orders' : 'Total confirmed orders' }}</p>
        <p class="relative text-3xl font-bold tabular-nums">{{ number_format($team['orders']) }}</p>
        <p class="{{ $note }}">BC {{ number_format($team['bc_orders']) }} · SC {{ number_format($team['sc_orders']) }}</p>
        <p class="relative text-xs font-semibold text-white group-hover:underline">View orders &rarr;</p>
    </a>
        <x-slot:back>
            <x-dashboard.trend title="Confirmed orders per day" type="bars" :format="$formatCount"
                :series="[['label' => 'BC', 'color' => '#1f8fb8', 'values' => $daily->map->bc_orders->all()], ['label' => 'SC', 'color' => '#8b3ff0', 'values' => $daily->map->sc_orders->all()]]"
                :legend="[['BC', '#1f8fb8', number_format($team['bc_orders']), false], ['SC', '#8b3ff0', number_format($team['sc_orders']), false]]" />
        </x-slot:back>
    </x-dashboard.flip>

    <x-dashboard.flip :class="$span">
    <article class="{{ $tile }} from-[#1f8fb8] to-[#156c8c]" title="(BC + SC orders) ÷ (engagements + leads)">
        {!! $bubble !!}
        <p class="{{ $label }}">{{ $own ? 'Your conversion rate' : 'Conversion rate' }}</p>
        <p class="relative text-3xl font-bold tabular-nums">{{ $pct($team['total_rate'], 2) }}</p>
        <p class="{{ $note }}">{{ number_format($team['orders']) }} of {{ number_format($team['reach']) }} engagements + leads</p>
    </article>
        <x-slot:back>
            <x-dashboard.trend title="Conversion rate per day" :format="$graphPct"
                :series="[['label' => 'Conversion', 'color' => '#1f8fb8', 'values' => $daily->map->total_rate->all()]]"
                :legend="[['Overall', '#1f8fb8', $pct($team['total_rate'], 2), false]]" />
        </x-slot:back>
    </x-dashboard.flip>

    {{-- AOV and Cancelled / Waiting share one slot, stacked as two short, wide tiles (label at the top left as on the
         other tiles, then the figure left and details right);
         Retained and Repeat take the rest of the row for their goals --}}
    @php($wide = 'relative flex min-w-0 flex-1 flex-col justify-between gap-2 overflow-hidden rounded-xl bg-gradient-to-br px-4 py-3 text-white shadow-sm')
    <div class="flex flex-col gap-3 {{ $span }}">
        <x-dashboard.flip class="flex-1">
        <article class="{{ $wide }} from-[#d99a0b] to-[#a8740a]" title="Average order value: gross sales ÷ confirmed orders">
            {!! $bubble !!}
            <p class="{{ $label }}">AOV</p>
            <div class="relative flex items-end justify-between gap-3">
                <p class="text-2xl font-bold whitespace-nowrap tabular-nums">{{ $team['orders'] ? '₱'.number_format($team['gross'] / $team['orders']) : '—' }}</p>
                <p class="text-right text-xs text-white/90 tabular-nums">{{ $peso((float) $team['gross']) }}<span class="block">÷ {{ number_format($team['orders']) }} orders</span></p>
            </div>
        </article>
            <x-slot:back>
                <x-dashboard.trend title="AOV per day" :format="$peso"
                    :series="[['label' => 'AOV', 'color' => '#a8740a', 'values' => $daily->map(fn ($day) => $day['orders'] ? $day['gross'] / $day['orders'] : null)->all()]]"
                    :legend="[['Overall', '#a8740a', $team['orders'] ? $peso($team['gross'] / $team['orders']) : '—', false]]" />
            </x-slot:back>
        </x-dashboard.flip>

        <x-dashboard.flip class="flex-1">
        <a href="{{ route('cancelled-orders.index', $query) }}" class="group {{ $wide }} from-[#e0564f] to-[#ad3530] transition hover:-translate-y-0.5 hover:shadow-lg"
           title="{{ $own ? 'Your' : 'The CRAs\'' }} Pancake orders (any tag or product) that were canceled or are waiting for goods ({{ $short($cancelled['amount']) }}); % of everything {{ $own ? 'you' : 'the CRD team' }} sold in these dates. Click to see them per day.">
            {!! $bubble !!}
            <div class="relative flex items-start justify-between gap-2">
                <p class="{{ $label }}">Cancelled / Waiting</p>
                <span class="text-xs font-semibold whitespace-nowrap text-white group-hover:underline">View &rarr;</span>
            </div>
            <div class="relative flex items-end justify-between gap-3">
                <div class="min-w-0">
                    <p class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold tabular-nums">{{ number_format($cancelled['total']) }}</span>
                        <span class="rounded-full bg-white/20 px-2 py-0.5 text-sm font-bold tabular-nums" title="{{ number_format($cancelled['total']) }} of {{ number_format($cancelled['orders']) }} orders sold in these dates">{{ $pct($cancelled['rate']) }}</span>
                    </p>
                    {{-- The amount of these orders: a small pill under the count --}}
                    <p class="mt-1 inline-block rounded-full bg-white/20 px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap tabular-nums" title="Amount of the cancelled and waiting orders">{{ $peso($cancelled['amount']) }}</p>
                </div>
                <div class="text-right text-xs text-white/90 tabular-nums">
                    <p class="whitespace-nowrap">Cancelled {{ number_format($cancelled['cancelled']) }}</p>
                    <p class="whitespace-nowrap">Waiting {{ number_format($cancelled['waiting']) }}</p>
                </div>
            </div>
        </a>
            <x-slot:back>
                <x-dashboard.trend title="Cancelled / waiting per day" type="bars" :format="$formatCount"
                    :series="[['label' => 'Cancelled', 'color' => '#e0564f', 'values' => $cancelledDays->map(fn ($day) => $day['cancelled'])->all()], ['label' => 'Waiting', 'color' => '#d99a0b', 'values' => $cancelledDays->map(fn ($day) => $day['waiting'])->all()]]"
                    :legend="[['Cancelled', '#e0564f', number_format($cancelled['cancelled']), false], ['Waiting', '#d99a0b', number_format($cancelled['waiting']), false]]" />
            </x-slot:back>
        </x-dashboard.flip>
    </div>

    @if ($customers)
        {{-- Customers delivered in these dates, counted as in the Customer Database, out of all the leads (every customer
         the Customer Database has for these dates), against the monthly goal (% of those leads, Settings → Sales Goals);
         opens the Customer Database filtered the same way --}}
        @php($customerRange = ['from' => $results['range']->from->toDateString(), 'to' => $results['range']->to->toDateString()])
        @php($leads = $customers['leads'])
        @php($segments = [])
        <x-dashboard.flip class="sm:col-span-6">
        <div class="grid gap-3 sm:grid-cols-2">
        @foreach ([
            'retained' => ['Retained', 'FSD Leads', 'from-[#4f46e5] to-[#3730a3]', 'retain', 'FSD leads a CRA got to buy for the first time (their first CRA-handled order ever), out of all the leads (every customer in the Customer Database for these dates). Click to see them.'],
            'repeat' => ['Repeat customers', 'CRD Leads', 'from-[#c026d3] to-[#86198f]', 'get to order again', 'CRD leads who bought again from a CRA (an earlier CRA-handled order too), out of all the leads (every customer in the Customer Database for these dates). Click to see them.'],
        ] as $segment => [$segmentLabel, $segmentNote, $segmentColors, $segmentVerb, $segmentTitle])
            @php($count = $customers[$segment])
            @php($goal = $customers['goals'][$segment])
            @php($goalLabel = rtrim(rtrim(number_format($goal, 1), '0'), '.').'%')
            @php($goalCount = \App\Support\SalesGoals::customersForGoal($leads, $goal))
            @php($toGoal = max(0, $goalCount - $count))
            @php($goalProgress = $goalCount ? min(1, $count / $goalCount) : 1)
            @php($segments[$segment] = ['label' => $segmentLabel, 'count' => $count, 'goal' => $goalLabel, 'goal_count' => $goalCount])
            <a href="{{ route('customers.index', [...$customerRange, 'segment' => $segment]) }}" class="group {{ $tile }} {{ $segmentColors }} gap-2 transition hover:-translate-y-0.5 hover:shadow-lg" title="{{ $segmentTitle }}">
                {!! $bubble !!}
                <div class="relative flex items-start justify-between gap-2">
                    <p class="{{ $label }}">{{ $segmentLabel }}</p>
                    @if ($leads)
                        <span @class(['shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold', 'text-teal-700' => $toGoal === 0, 'text-coral-700' => $toGoal > 0])>{{ $toGoal === 0 ? '✓ Goal hit' : '▼ Below goal' }}</span>
                    @endif
                </div>
                <p class="relative flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                    <span class="text-3xl font-bold whitespace-nowrap tabular-nums">{{ number_format($count) }}<span class="text-sm font-semibold text-white/75">/{{ number_format($leads) }}</span></span>
                    <span class="shrink-0 rounded-full bg-white/20 px-2 py-0.5 text-base font-bold tabular-nums" title="{{ number_format($count) }} of {{ number_format($leads) }} leads (FSD and CRD)">{{ $pct($leads ? $count / $leads : null) }}</span>
                </p>

                {{-- Progress toward the goal: full bar = goal reached --}}
                <div class="relative">
                    <div class="h-2 rounded-full bg-white/25" role="progressbar" aria-label="{{ $segmentLabel }} goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($goalProgress * 100) }}">
                        <span class="block h-full rounded-full bg-white" style="width: {{ $goalProgress * 100 }}%"></span>
                    </div>
                    <p class="mt-1.5 flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-[11px] text-white/90 tabular-nums">
                        <span>Goal <span class="font-bold text-white">{{ $goalLabel }}</span> · {{ number_format($goalCount) }} of {{ number_format($leads) }}</span>
                        @if (! $leads)
                            <span>No leads in these dates</span>
                        @elseif ($toGoal > 0)
                            <span class="rounded-full bg-white px-2 font-bold text-coral-700" title="Customers still to {{ $segmentVerb }} to reach {{ $goalLabel }} of {{ number_format($leads) }} leads">{{ number_format($toGoal) }} more to reach {{ $goalLabel }}</span>
                        @else
                            <span class="rounded-full bg-white px-2 font-bold text-teal-700">Goal reached</span>
                        @endif
                    </p>
                </div>

                <p class="relative flex items-center justify-between gap-2 text-xs">
                    <span class="text-white/90">{{ $segmentNote }}</span>
                    <span class="font-semibold text-white group-hover:underline">View customers &rarr;</span>
                </p>
            </a>
        @endforeach
        </div>
            <x-slot:back>
                {{-- Both against their goal on one scale (the same leads): bar = customers, tick = the goal --}}
                @php($scale = max(1, ...array_values(array_map(fn ($row) => max($row['count'], $row['goal_count']), $segments))))
                <div class="flex h-full flex-col gap-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                        <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">Retained & repeat vs goal</p>
                        <p class="flex items-center gap-1 text-[11px] text-ink"><span aria-hidden="true" class="inline-block h-2.5 w-0.5 bg-ink"></span> Goal · out of {{ number_format($leads) }} leads</p>
                    </div>
                    <div class="flex flex-1 flex-col justify-center gap-4">
                        @foreach ($segments as $segment => $row)
                            @php($color = $segment === 'retained' ? '#4f46e5' : '#d4458f')
                            <div>
                                <p class="mb-1 flex justify-between gap-2 text-xs tabular-nums">
                                    <span class="font-semibold text-ink">{{ $row['label'] }} <span class="font-normal text-muted">{{ number_format($row['count']) }} · {{ $pct($leads ? $row['count'] / $leads : null) }}</span></span>
                                    <span class="text-muted">Goal {{ $row['goal'] }} · {{ number_format($row['goal_count']) }}</span>
                                </p>
                                <div class="relative h-4 rounded bg-canvas" data-tip="{{ $row['label'] }}: {{ number_format($row['count']) }} of {{ number_format($leads) }} leads ({{ $pct($leads ? $row['count'] / $leads : null) }})&#10;Goal {{ $row['goal'] }}: {{ number_format($row['goal_count']) }}">
                                    <span class="absolute inset-y-0 left-0 rounded" style="width: {{ $row['count'] / $scale * 100 }}%; background: {{ $color }}"></span>
                                    <span aria-hidden="true" class="absolute -inset-y-1 w-0.5 rounded bg-ink" style="left: calc({{ $row['goal_count'] / $scale * 100 }}% - 1px)"></span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-slot:back>
        </x-dashboard.flip>
    @endif
</section>

{{-- Churn: its own light card so it isn't read as these dates' customers; any row opens the CRD / FSD / overall breakdown --}}
@php($deadlines = $results['range']->label())
@php($monthLabel = fn (string $month) => \Carbon\CarbonImmutable::parse($month.'-01')->format($results['range']->from->isSameYear($month.'-01') ? 'M' : 'M Y'))
@php($churnTeams = ['all' => 'Overall', 'crd' => 'CRD', 'fsd' => 'FSD'])
<section aria-labelledby="churn-title" class="flex flex-col gap-2 rounded-xl bg-[#fdeeee] p-3 text-[#8f3236] ring-1 ring-[#f6d3d4]">
    <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
        <h3 id="churn-title" class="text-sm font-bold">Customer churn</h3>
        <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-coral-700">Deadline {{ $deadlines }}</span>
    </div>

    <ul class="flex flex-1 flex-col gap-1.5 sm:flex-row xl:flex-col">
        @foreach ($churnTeams as $team => $teamLabel)
            @php($teamChurn = $churn[$team])
            <li class="flex-1">
                <button type="button" onclick="document.getElementById('churn-dialog').showModal()" aria-haspopup="dialog"
                        class="flex h-full w-full items-center justify-between gap-2 rounded-lg bg-white/70 px-3 py-2 text-left transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-coral"
                        title="{{ $team === 'all' ? 'CRD and FSD' : $teamLabel }}-delivered customers from earlier months whose {{ $churn['grace_days'] }} days to reorder ended {{ $deadlines }}. Click for the breakdown.">
                    <span>
                        <span class="block text-[11px] font-semibold tracking-wide uppercase">{{ $teamLabel }}</span>
                        <span class="block text-[11px] text-[#8f3236]/80 tabular-nums">{{ number_format($teamChurn['lost']) }} lost of {{ number_format($teamChurn['customers']) }}</span>
                    </span>
                    <span class="text-lg font-bold tabular-nums">{{ $pct($teamChurn['rate'], 2) }}</span>
                </button>
            </li>
        @endforeach
    </ul>
    <button type="button" onclick="document.getElementById('churn-dialog').showModal()" aria-haspopup="dialog" class="self-start text-xs font-semibold hover:underline">View breakdown &rarr;</button>

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
