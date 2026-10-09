@props(['results', 'churn', 'query' => []])

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
    confirmed orders, conversion rate, AOV and churn. A CRA's orders, conversion and AOV are their own.
--}}
<section {{ $attributes->merge(['class' => 'grid gap-3 sm:grid-cols-2 lg:grid-cols-6']) }} aria-label="Results">
    {{-- CRD monthly goal --}}
    <article class="relative flex flex-col justify-between gap-3 overflow-hidden rounded-xl bg-gradient-to-br from-brand-600 to-brand-700 p-4 text-white shadow-sm sm:col-span-2">
        <span aria-hidden="true" class="absolute -top-8 -right-8 size-28 rounded-full bg-white/15"></span>
        <div class="relative">
            <div class="flex items-start justify-between gap-2">
                <p class="text-xs font-medium text-white/90">
                    {{ $month['prorated'] ? 'CRD goal · '.$month['label'] : 'CRD monthly goal · '.$month['label'] }}
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
            <p class="text-xs text-white/90"><span class="font-semibold text-white tabular-nums">{{ $peso($month['sales']) }}</span> gross of {{ $peso($month['goal']) }}</p>
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
                <span>{{ $month['remaining'] > 0 ? $short($month['remaining']).' to go' : 'Goal reached' }}</span>
            </p>
        </div>
    </article>

    {{-- Confirmed orders: opens the list --}}
    <a href="{{ route('conversion.orders', $query) }}" class="group relative flex min-w-0 flex-col justify-between gap-1 rounded-xl bg-white p-4 shadow-sm ring-2 ring-transparent transition hover:ring-brand-200"
       title="{{ $own ? 'Your' : 'The CRAs\'' }} orders tagged CRD - BROADCAST or CRD - SEGMENTATION (not canceled). Click to see them.">
        <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">{{ $own ? 'Your confirmed orders' : 'Total confirmed orders' }}</p>
        <p class="text-3xl font-bold tabular-nums">{{ number_format($team['orders']) }}</p>
        <p class="text-xs text-muted">BC {{ number_format($team['bc_orders']) }} · SC {{ number_format($team['sc_orders']) }}</p>
        <p class="text-xs font-semibold text-brand-600 group-hover:underline">View orders &rarr;</p>
    </a>

    {{-- Conversion rate --}}
    <article class="flex min-w-0 flex-col justify-between gap-1 rounded-xl bg-white p-4 shadow-sm" title="(BC + SC orders) ÷ (engagements + leads)">
        <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">{{ $own ? 'Your conversion rate' : 'Conversion rate' }}</p>
        <p class="text-3xl font-bold tabular-nums">{{ $pct($team['total_rate'], 2) }}</p>
        <p class="text-xs text-muted tabular-nums">{{ number_format($team['orders']) }} of {{ number_format($team['reach']) }} engagements + leads</p>
    </article>

    {{-- AOV --}}
    <article class="flex min-w-0 flex-col justify-between gap-1 rounded-xl bg-white p-4 shadow-sm" title="Average order value: gross sales ÷ confirmed orders">
        <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">AOV</p>
        <p class="text-3xl font-bold tabular-nums">{{ $team['orders'] ? '₱'.number_format($team['gross'] / $team['orders']) : '—' }}</p>
        <p class="text-xs text-muted tabular-nums">{{ $peso((float) $team['gross']) }} ÷ {{ number_format($team['orders']) }} orders</p>
    </article>

    {{-- Churn rate --}}
    <article class="flex min-w-0 flex-col justify-between gap-1 rounded-xl bg-white p-4 shadow-sm"
             title="Customers lost ÷ CRD customers whose product ran out in this period × 100. Lost = no order since that delivery. Lower is better.">
        <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">Churn rate</p>
        <p @class(['text-3xl font-bold tabular-nums', 'text-coral-700' => ($churn['rate'] ?? 0) > 0])>{{ $pct($churn['rate'], 2) }}</p>
        <p class="text-xs text-muted tabular-nums">{{ number_format($churn['lost']) }} lost of {{ number_format($churn['customers']) }} who ran out</p>
    </article>
</section>
