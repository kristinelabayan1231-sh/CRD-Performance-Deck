@props(['results', 'customers' => null, 'query' => []])

@php
    $peso = fn (float $value) => '₱'.number_format($value);
    // Short amounts for tight spots: ₱77k, ₱1.2M.
    $short = fn (float $value) => '₱'.($value >= 1e6 ? rtrim(rtrim(number_format($value / 1e6, 2), '0'), '.').'M' : ($value >= 1e3 ? rtrim(rtrim(number_format($value / 1e3, 1), '0'), '.').'k' : number_format($value)));
    $pct = fn (?float $value, int $decimals = 1) => $value === null ? '—' : number_format($value * 100, $decimals).'%';
    $team = $results['team'];
    $own = ! auth()->user()->can('conversion.view_all');
    $cancelled = $results['cancelled'];

    // The graphs on the tiles' backs, one value per day of these dates.
    $daily = collect($results['daily']);
    $formatCount = fn (float $value) => number_format($value);
    $graphShort = fn (float $value) => $short($value);
    $graphPct = fn (float $value) => $pct($value, $value < 0.1 ? 1 : 0);
    $cancelledDays = $daily->keys()->mapWithKeys(fn ($day) => [$day => $cancelled['days'][$day] ?? ['cancelled' => 0, 'waiting' => 0]]);
@endphp

{{--
    Results for the dashboard's month (to date) or range: confirmed orders, conversion rate, AOV beside Cancelled /
    Waiting, and (with Customer Database access) Retained and Repeat Customers. A CRA's orders, conversion and AOV are their own. Churn is its own strip further down the
    dashboard (x-dashboard.churn).
--}}
{{-- Six columns: orders, conversion and AOV + Cancelled share the first row, Retained and Repeat the second. The CRD goal
     is the dashboard's hero above (x-dashboard.hero). --}}
<section {{ $attributes->class(['grid content-start gap-3', 'sm:grid-cols-6' => $customers, 'sm:grid-cols-3' => ! $customers]) }} aria-label="Results">
    {{-- Confirmed orders (opens the list), conversion rate, AOV: one colour each so they read apart --}}
    @php($tile = 'relative flex min-w-0 flex-col justify-between gap-1 overflow-hidden rounded-2xl border border-line bg-white p-4 text-ink')
    @php($bubble = '')
    @php($label = 'relative text-[0.6875rem] font-semibold tracking-wide text-muted uppercase')
    @php($note = 'relative text-xs text-muted tabular-nums')
    @php($span = $customers ? 'sm:col-span-2' : '')
    <x-dashboard.flip :class="$span">
    <a href="{{ route('conversion.orders', $query) }}" class="group {{ $tile }} transition hover:-translate-y-0.5 hover:border-brand-500"
       title="{{ $own ? 'Your' : 'The CRAs\'' }} orders tagged CRD - BROADCAST or CRD - SEGMENTATION (not canceled). Click to see them.">
        {!! $bubble !!}
        <p class="{{ $label }}">{{ $own ? 'Your confirmed orders' : 'Total confirmed orders' }}</p>
        <p class="relative font-display text-3xl font-bold tabular-nums">{{ number_format($team['orders']) }}</p>
        <p class="{{ $note }}">Broadcast {{ number_format($team['bc_orders']) }} · Segmentation {{ number_format($team['sc_orders']) }}@if ($team['untagged_orders']) · Not tagged {{ number_format($team['untagged_orders']) }}@endif</p>
        <p class="relative text-xs font-semibold text-ink group-hover:underline">View orders &rarr;</p>
    </a>
        <x-slot:back>
            <x-dashboard.trend title="Confirmed orders per day" type="bars" :format="$formatCount"
                :series="[['label' => 'Broadcast', 'color' => '#14b8a6', 'values' => $daily->map->bc_orders->all()], ['label' => 'Segmentation', 'color' => '#8b3ff0', 'values' => $daily->map->sc_orders->all()], ...($team['untagged_orders'] ? [['label' => 'Not tagged', 'color' => '#6b7280', 'values' => $daily->map->untagged_orders->all()]] : [])]"
                :legend="[['Broadcast', '#14b8a6', number_format($team['bc_orders']), false], ['Segmentation', '#8b3ff0', number_format($team['sc_orders']), false], ...($team['untagged_orders'] ? [['Not tagged', '#6b7280', number_format($team['untagged_orders']), false]] : [])]" />
        </x-slot:back>
    </x-dashboard.flip>

    {{-- Conversion rate, all counting customers (SegmentationStats::conversion()): Broadcast + Segmentation on top (every
         buyer once ÷ engagements + leads), then Segmentation CVR (the Segmentation Tracker's Converted: Retained + Repeat
         ÷ tracker leads) and Broadcast CVR (Broadcast buyers ÷ engagements) as bars; the back has all three per day. --}}
    @php($conv = $results['conversion'])
    @php($noTracker = $conv['no_tracker'] ?? false)
    @php($cvrRows = [
        $noTracker
            ? ['Segmentation CVR', $conv['sc'], 'No tracker data', 'The Segmentation Tracker has no purchases for these dates']
            : ['Segmentation CVR', $conv['sc'], number_format($conv['sc']['customers']).' of '.number_format($conv['sc']['base']).' leads', 'Same as the Segmentation Tracker\'s Converted: '.number_format($conv['sc']['customers']).' customers of '.number_format($conv['sc']['base']).' leads'],
        ['Broadcast CVR', $conv['bc'], number_format($conv['bc']['customers']).' of '.number_format($conv['bc']['base']).' engagements', number_format($conv['bc']['customers']).' customers of '.number_format($conv['bc']['base']).' engagements'],
    ])
    <x-dashboard.flip :class="$span">
    <article class="{{ $tile }}">
        {!! $bubble !!}
        <div class="relative grid gap-0.5" title="{{ $noTracker ? 'The Segmentation Tracker has no purchases for these dates' : 'Broadcast + Segmentation: '.number_format($conv['all']['customers']).' customers of '.number_format($conv['all']['base']).' engagements + leads' }}">
            <p class="{{ $label }}">{{ $own ? 'Your conversion rate' : 'Conversion rate' }}</p>
            <p class="font-display text-3xl font-bold tabular-nums">{{ $pct($conv['all']['rate'], 2) }}</p>
            <p class="{{ $note }}">Broadcast + Segmentation · {{ $noTracker ? 'No tracker data' : number_format($conv['all']['customers']).' of '.number_format($conv['all']['base']) }}</p>
        </div>
        <div class="relative mt-1 grid gap-2 border-t border-line pt-2">
            @foreach ($cvrRows as [$rowLabel, $row, $rowNote, $rowTitle])
                <div class="grid grid-cols-[1fr_auto] items-baseline gap-x-2 gap-y-0.5 tabular-nums" title="{{ $rowTitle }}">
                    <span class="text-xs font-bold whitespace-nowrap">{{ $rowLabel }}</span>
                    <span class="text-sm font-bold">{{ $pct($row['rate'], 2) }}</span>
                    <span class="col-span-2 h-1 overflow-hidden rounded-full bg-line" aria-hidden="true">
                        <span class="block h-full min-w-1 rounded-full bg-brand-500" style="width: {{ min(100, ($row['rate'] ?? 0) * 100) }}%"></span>
                    </span>
                    <span class="col-span-2 text-[0.6875rem] text-muted">{{ $rowNote }}</span>
                </div>
            @endforeach
        </div>
    </article>
        <x-slot:back>
            <x-dashboard.trend title="Conversion rate per day" :format="$graphPct"
                :series="[
                    ['label' => 'Broadcast + Segmentation', 'color' => '#4f5563', 'values' => $conv['all']['daily']],
                    ['label' => 'Segmentation CVR', 'color' => '#8b3ff0', 'values' => $conv['sc']['daily']],
                    ['label' => 'Broadcast CVR', 'color' => '#14b8a6', 'values' => $conv['bc']['daily']],
                ]"
                :legend="[
                    ['Broadcast + Segmentation', '#4f5563', $pct($conv['all']['rate'], 2), false],
                    ['Segmentation CVR', '#8b3ff0', $pct($conv['sc']['rate'], 2), false],
                    ['Broadcast CVR', '#14b8a6', $pct($conv['bc']['rate'], 2), false],
                ]" />
        </x-slot:back>
    </x-dashboard.flip>

    {{-- AOV and Cancelled / Waiting share one slot, stacked as two short, wide tiles (label at the top left as on the
         other tiles, then the figure left and details right);
         Retained and Repeat take the rest of the row for their goals --}}
    @php($wide = 'relative flex min-w-0 flex-1 flex-col justify-between gap-2 overflow-hidden rounded-2xl border border-line bg-white px-4 py-3 text-ink')
    <div class="flex flex-col gap-3 {{ $span }}">
        <x-dashboard.flip class="flex-1">
        <article class="{{ $wide }}" title="Average order value: gross sales ÷ confirmed orders">
            {!! $bubble !!}
            <p class="{{ $label }}">AOV</p>
            <div class="relative flex items-end justify-between gap-3">
                <p class="font-display text-2xl font-bold whitespace-nowrap tabular-nums">{{ $team['orders'] ? '₱'.number_format($team['gross'] / $team['orders']) : '—' }}</p>
                <p class="text-right text-xs text-muted tabular-nums">{{ $peso((float) $team['gross']) }}<span class="block">÷ {{ number_format($team['orders']) }} orders</span></p>
            </div>
        </article>
            <x-slot:back>
                <x-dashboard.trend title="AOV per day" :format="$peso"
                    :series="[['label' => 'AOV', 'color' => '#f97316', 'values' => $daily->map(fn ($day) => $day['orders'] ? $day['gross'] / $day['orders'] : null)->all()]]"
                    :legend="[['Overall', '#f97316', $team['orders'] ? $peso($team['gross'] / $team['orders']) : '—', false]]" />
            </x-slot:back>
        </x-dashboard.flip>

        <x-dashboard.flip class="flex-1">
        <a href="{{ route('cancelled-orders.index', $query) }}" class="group {{ $wide }} transition hover:-translate-y-0.5 hover:border-brand-500"
           title="{{ $own ? 'Your' : 'The CRAs\'' }} Pancake orders (any tag or product) that were canceled or are waiting for goods ({{ $short($cancelled['amount']) }}); % of everything {{ $own ? 'you' : 'the CRD team' }} sold in these dates. Click to see them per day.">
            {!! $bubble !!}
            <div class="relative flex items-start justify-between gap-2">
                <p class="{{ $label }}">Cancelled / Waiting</p>
                <span class="text-xs font-semibold whitespace-nowrap text-ink group-hover:underline">View &rarr;</span>
            </div>
            <div class="relative flex items-end justify-between gap-3">
                <div class="min-w-0">
                    <p class="flex items-baseline gap-2">
                        <span class="font-display text-2xl font-bold tabular-nums">{{ number_format($cancelled['total']) }}</span>
                        <span class="rounded-full bg-canvas px-2 py-0.5 text-sm font-bold tabular-nums" title="{{ number_format($cancelled['total']) }} of {{ number_format($cancelled['orders']) }} orders in these dates (confirmed + canceled, as Total confirmed orders counts them)">{{ $pct($cancelled['rate']) }}</span>
                    </p>
                    {{-- The amount of these orders: a small pill under the count --}}
                    <p class="mt-1 inline-block rounded-full bg-canvas px-2 py-0.5 text-[0.6875rem] font-semibold whitespace-nowrap tabular-nums" title="Amount of the cancelled and waiting orders">{{ $peso($cancelled['amount']) }}</p>
                </div>
                <div class="text-right text-xs text-muted tabular-nums">
                    <p class="whitespace-nowrap">Cancelled {{ number_format($cancelled['cancelled']) }}</p>
                    <p class="whitespace-nowrap">Waiting {{ number_format($cancelled['waiting']) }}</p>
                </div>
            </div>
        </a>
            <x-slot:back>
                <x-dashboard.trend title="Cancelled / waiting per day" type="bars" :format="$formatCount"
                    :series="[['label' => 'Cancelled', 'color' => '#e0564f', 'values' => $cancelledDays->map(fn ($day) => $day['cancelled'])->all()], ['label' => 'Waiting', 'color' => '#ffc21a', 'values' => $cancelledDays->map(fn ($day) => $day['waiting'])->all()]]"
                    :legend="[['Cancelled', '#e0564f', number_format($cancelled['cancelled']), false], ['Waiting', '#ffc21a', number_format($cancelled['waiting']), false]]" />
            </x-slot:back>
        </x-dashboard.flip>
    </div>

    @if ($customers)
        {{-- Segmentation Tracker customers with Repeat Purchase = Yes bought in these dates (FSD Leads = Retained, CRD Leads =
         Repeat), each out of its own leads in the tracker (FSD or CRD), against the monthly goal (% of those leads, Settings → Sales Goals);
         opens the list of those customers for the same dates --}}
        @php($customerRange = ['from' => $results['range']->from->toDateString(), 'to' => $results['range']->to->toDateString()])
        @php($segments = [])
        {{-- Months before the tracker recorded purchases: customers from Shecom's deliveries, with no list of tracker customers to open --}}
        @php($fromDeliveries = ($customers['source'] ?? null) === \App\Services\DeliveredRetention::SOURCE)
        @php($baseNoun = $fromDeliveries ? 'customers delivered' : 'leads')
        @php($tileTag = $fromDeliveries ? 'article' : 'a')
        <x-dashboard.flip class="sm:col-span-6">
        <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($fromDeliveries ? [
            'retained' => ['Retained', 'FSD customers delivered', '', 'retain', 'Customers FSD delivered to in these dates (Shecom, one per number) who ordered from a CRA afterwards.'],
            'repeat' => ['Repeat customers', 'CRD customers delivered', '', 'get to order again', 'Customers CRD delivered to in these dates (Shecom, one per number) who ordered from a CRA afterwards.'],
        ] : [
            'retained' => ['Retained', 'FSD Leads', '', 'retain', 'FSD Leads in the Segmentation Tracker with Repeat Purchase = Yes bought in these dates, out of the tracker\'s FSD Leads. Click to see them.'],
            'repeat' => ['Repeat customers', 'CRD Leads', '', 'get to order again', 'CRD Leads in the Segmentation Tracker with Repeat Purchase = Yes bought in these dates, out of the tracker\'s CRD Leads. Click to see them.'],
        ] as $segment => [$segmentLabel, $segmentNote, $segmentColors, $segmentVerb, $segmentTitle])
            @php($count = $customers[$segment])
            @php($leads = $customers['leads'][$segment])
            @php($goal = $customers['goals'][$segment])
            @php($goalLabel = rtrim(rtrim(number_format($goal, 1), '0'), '.').'%')
            @php($goalCount = \App\Support\SalesGoals::customersForGoal($leads, $goal))
            @php($toGoal = max(0, $goalCount - $count))
            @php($goalProgress = $goalCount ? min(1, $count / $goalCount) : 1)
            @php($segments[$segment] = ['label' => $segmentLabel, 'note' => $segmentNote, 'count' => $count, 'leads' => $leads, 'goal' => $goalLabel, 'goal_count' => $goalCount])
            <{{ $tileTag }} @unless ($fromDeliveries) href="{{ route('segmentation.repeat-purchases', [...$customerRange, 'segment' => $segment]) }}" @endunless class="group {{ $tile }} {{ $segmentColors }} gap-2 {{ $fromDeliveries ? '' : 'transition hover:-translate-y-0.5 hover:border-brand-500' }}" title="{{ $segmentTitle }}">
                {!! $bubble !!}
                <div class="relative flex items-start justify-between gap-2">
                    <p class="{{ $label }}">{{ $segmentLabel }}</p>
                    @if ($leads)
                        <span @class(['shrink-0 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold', 'bg-teal/10 text-teal-700' => $toGoal === 0, 'bg-coral/10 text-coral-700' => $toGoal > 0])>{{ $toGoal === 0 ? '✓ Goal hit' : '▼ Below goal' }}</span>
                    @endif
                </div>
                <p class="relative flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                    <span class="font-display text-3xl font-bold whitespace-nowrap tabular-nums">{{ number_format($count) }}<span class="text-sm font-semibold text-muted">/{{ number_format($leads) }}</span></span>
                    <span class="shrink-0 rounded-full bg-canvas px-2 py-0.5 text-base font-bold tabular-nums" title="{{ number_format($count) }} of {{ number_format($leads) }} {{ $fromDeliveries ? '' : 'tracker' }} {{ $segmentNote }}">{{ $pct($leads ? $count / $leads : null) }}</span>
                </p>

                {{-- Progress toward the goal: full bar = goal reached --}}
                <div class="relative">
                    <div class="h-2 rounded-full bg-line" role="progressbar" aria-label="{{ $segmentLabel }} goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($goalProgress * 100) }}">
                        <span class="block h-full rounded-full bg-brand-500" style="width: {{ $goalProgress * 100 }}%"></span>
                    </div>
                    <p class="mt-1.5 flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-[0.6875rem] text-muted tabular-nums">
                        <span>Goal <span class="font-bold text-ink">{{ $goalLabel }}</span> · {{ number_format($goalCount) }} of {{ number_format($leads) }}</span>
                        @if (! $leads)
                            <span>No {{ $baseNoun }} in these dates</span>
                        @elseif ($toGoal > 0)
                            <span class="rounded-full bg-coral/10 px-2 font-bold text-coral-700" title="Customers still to {{ $segmentVerb }} to reach {{ $goalLabel }} of {{ number_format($leads) }} {{ $baseNoun }}">{{ number_format($toGoal) }} more to reach {{ $goalLabel }}</span>
                        @else
                            <span class="rounded-full bg-teal/10 px-2 font-bold text-teal-700">Goal reached</span>
                        @endif
                    </p>
                </div>

                <p class="relative flex items-center justify-between gap-2 text-xs">
                    <span class="text-muted">{{ $segmentNote }}</span>
                    @unless ($fromDeliveries)
                        <span class="font-semibold text-ink group-hover:underline">View customers &rarr;</span>
                    @endunless
                </p>
            </{{ $tileTag }}>
        @endforeach
        </div>
            <x-slot:back>
                {{-- Both against their goal on one scale (the same leads): bar = customers, tick = the goal --}}
                @php($scale = max(1, ...array_values(array_map(fn ($row) => max($row['count'], $row['goal_count']), $segments))))
                <div class="flex h-full flex-col gap-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">Retained & repeat vs goal</p>
                        <p class="flex items-center gap-1 text-[0.6875rem] text-ink"><span aria-hidden="true" class="inline-block h-2.5 w-0.5 bg-ink"></span> Goal · each out of its own {{ $baseNoun }}</p>
                    </div>
                    <div class="flex flex-1 flex-col justify-center gap-4">
                        @foreach ($segments as $segment => $row)
                            @php($color = $segment === 'retained' ? '#14b8a6' : '#f97316')
                            <div>
                                <p class="mb-1 flex justify-between gap-2 text-xs tabular-nums">
                                    <span class="font-semibold text-ink">{{ $row['label'] }} <span class="font-normal text-muted">{{ number_format($row['count']) }}/{{ number_format($row['leads']) }} · {{ $pct($row['leads'] ? $row['count'] / $row['leads'] : null) }}</span></span>
                                    <span class="text-muted">Goal {{ $row['goal'] }} · {{ number_format($row['goal_count']) }}</span>
                                </p>
                                <div class="relative h-4 rounded bg-canvas" data-tip="{{ $row['label'] }}: {{ number_format($row['count']) }} of {{ number_format($row['leads']) }} {{ $row['note'] }} ({{ $pct($row['leads'] ? $row['count'] / $row['leads'] : null) }})&#10;Goal {{ $row['goal'] }}: {{ number_format($row['goal_count']) }}">
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
