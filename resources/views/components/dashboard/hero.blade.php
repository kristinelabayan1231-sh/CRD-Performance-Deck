@props(['results', 'segmentation' => null, 'customers' => null])

@php
    $peso = fn (float $value) => '₱'.number_format($value);
    $short = fn (float $value) => '₱'.($value >= 1e6 ? rtrim(rtrim(number_format($value / 1e6, 2), '0'), '.').'M' : ($value >= 1e3 ? rtrim(rtrim(number_format($value / 1e3, 1), '0'), '.').'k' : number_format($value)));
    $pct = fn (?float $value, int $decimals = 1) => $value === null ? '—' : number_format($value * 100, $decimals).'%';
    $month = $results['month'];
    $ahead = $month['progress'] !== null && $month['pace'] !== null && $month['progress'] >= $month['pace'];

    // The goal's back: every CRA's sales added up day by day against an even pace to the goal (as on the Results tiles).
    $daily = collect($results['daily']);
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

    // The rings: four of these dates' rates at a glance, each out of 100%. Gold = the goal, then the tracker's figures.
    $catered = collect($segmentation['kpis'] ?? [])->firstWhere('label', 'Catered');
    $cateredRate = $catered && preg_match('/([\d.]+)%/', (string) $catered['value'], $match) ? (float) $match[1] / 100 : null;
    $repeatRate = $customers && ($customers['leads']['repeat'] ?? 0) ? $customers['repeat'] / $customers['leads']['repeat'] : null;
    $conversion = $results['conversion'];
    $segmentationRate = ($conversion['no_tracker'] ?? false) ? null : $conversion['sc']['rate'];
    $rings = array_values(array_filter([
        ['Monthly goal', $month['progress'], '#ffc21a'],
        ['Leads catered', $cateredRate, '#14b8a6'],
        ['Repeat customers', $repeatRate, '#f97316'],
        ['Segmentation CVR', $segmentationRate, '#8b5cf6'],
    ], fn (array $ring) => $ring[1] !== null));
@endphp

{{-- The dashboard's opening: the CRD goal large on the left (turns over to its graph with the Results tiles), the rings on
     the right. --}}
<section {{ $attributes->merge(['class' => 'grid items-center gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]']) }} aria-label="CRD goal">
    <x-dashboard.flip>
        <article class="flex flex-col gap-4 py-2">
            <div class="flex items-start justify-between gap-3">
                <p class="text-sm font-medium text-muted">{{ ($month['prorated'] ? 'CRD goal (Gross Sales)' : 'CRD monthly goal (Gross Sales)').' · '.$month['label'] }}</p>
                @if ($month['progress'] !== null)
                    <span @class([
                        'shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        'bg-teal/10 text-teal-700' => $month['progress'] >= 1 || $ahead,
                        'bg-coral/10 text-coral-700' => $month['progress'] < 1 && ! $ahead,
                    ])>{{ $month['progress'] >= 1 ? '✓ Goal hit' : ($month['pace'] === null ? '▼ Below goal' : ($ahead ? '▲ Ahead' : '▼ Behind')) }}</span>
                @endif
            </div>
            <div>
                <p class="font-display text-[clamp(3rem,6.5vw,5.75rem)] leading-[0.95] font-extrabold tracking-[-0.045em] tabular-nums" title="Gross sales so far">{{ $peso($month['sales']) }}</p>
                <p class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="text-lg font-semibold tabular-nums" title="Share of the goal">{{ $pct($month['progress']) }} of goal</span>
                    <span class="rounded-full bg-white px-2.5 py-0.5 font-medium text-muted tabular-nums ring-1 ring-line">Target <span class="font-bold text-ink">{{ $peso($month['goal']) }}</span></span>
                </p>
            </div>
            <div>
                <div class="relative h-2.5 rounded-full bg-line" role="progressbar" aria-label="CRD goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round(min(1, $month['progress'] ?? 0) * 100) }}">
                    <span class="absolute inset-y-0 left-0 rounded-full bg-brand-500" style="width: {{ min(100, ($month['progress'] ?? 0) * 100) }}%"></span>
                    @if ($month['pace'] !== null)
                        {{-- Where sales should be by now if they were spread evenly over the month --}}
                        <span class="absolute -inset-y-1.5 w-0.5 rounded bg-ink" style="left: {{ min(100, $month['pace'] * 100) }}%" title="Pace for day {{ $month['day'] }}: {{ $pct($month['pace']) }}"></span>
                    @endif
                </div>
                <p class="mt-2 flex flex-wrap justify-between gap-x-2 text-xs text-muted">
                    @if ($month['pace'] !== null)
                        <span>Day {{ $month['day'] }}/{{ $month['days'] }} · pace <span class="font-semibold text-ink">{{ $pct($month['pace']) }}</span></span>
                    @else
                        <span>Monthly goal prorated to {{ $results['range']->days() }} {{ Str::plural('day', $results['range']->days()) }}</span>
                    @endif
                    <span @class(['rounded-full px-2 font-bold', 'bg-coral/10 text-coral-700' => $month['remaining'] > 0, 'bg-brand-100 text-ink' => $month['remaining'] <= 0])>{{ $month['remaining'] > 0 ? $short($month['remaining']).' to go' : 'Goal reached' }}</span>
                </p>
            </div>
        </article>
        <x-slot:back>
            <x-dashboard.trend title="Gross sales vs goal" :series="[['label' => 'Gross sales', 'color' => '#c99400', 'values' => $cumulative]]" :format="$short"
                :target="['label' => 'Even pace to goal', 'values' => $pace]"
                :legend="[['Sales', '#c99400', $short($month['sales']), false], ['Goal', '#5f5d58', $short($month['goal']), true]]" />
        </x-slot:back>
    </x-dashboard.flip>

    @if ($rings)
        <div class="flex flex-wrap items-center justify-center gap-6">
            <svg viewBox="0 0 240 240" class="w-[min(15rem,60vw)]" role="img" aria-label="{{ collect($rings)->map(fn ($ring) => $ring[0].' '.$pct($ring[1]))->join(', ') }}">
                @foreach ($rings as $i => [$ringLabel, $ringValue, $ringColor])
                    @php($radius = 104 - $i * 25)
                    @php($circumference = 2 * M_PI * $radius)
                    <circle cx="120" cy="120" r="{{ $radius }}" fill="none" stroke="#ebebe8" stroke-width="17"/>
                    <circle cx="120" cy="120" r="{{ $radius }}" fill="none" stroke="{{ $ringColor }}" stroke-width="17" stroke-linecap="round"
                            stroke-dasharray="{{ round($circumference, 2) }}" stroke-dashoffset="{{ round($circumference * (1 - min(1, max(0, $ringValue))), 2) }}" transform="rotate(-90 120 120)">
                        <title>{{ $ringLabel }} {{ $pct($ringValue) }}</title>
                    </circle>
                @endforeach
            </svg>
            <ul class="grid gap-3">
                @foreach ($rings as [$ringLabel, $ringValue, $ringColor])
                    <li class="grid grid-cols-[0.75rem_auto] items-center gap-x-2.5">
                        <span class="size-3 rounded-full" style="background: {{ $ringColor }}" aria-hidden="true"></span>
                        <strong class="font-display text-xl leading-none tabular-nums">{{ $pct($ringValue) }}</strong>
                        <span class="col-start-2 text-sm text-muted">{{ $ringLabel }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
