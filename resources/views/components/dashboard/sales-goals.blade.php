@props(['goals'])

@php
    $peso = fn (float $value) => '₱'.number_format($value);
    // Short amounts for tight rows: ₱77k, ₱1.2M.
    $short = fn (float $value) => '₱'.($value >= 1e6 ? rtrim(rtrim(number_format($value / 1e6, 2), '0'), '.').'M' : ($value >= 1e3 ? rtrim(rtrim(number_format($value / 1e3, 1), '0'), '.').'k' : number_format($value)));
    $pct = fn (?float $value) => $value === null ? '—' : number_format($value * 100, 1).'%';
    $month = $goals['month'];
    $ahead = $month['progress'] !== null && $month['progress'] >= $month['pace'];
@endphp

{{--
    Sales goals on the dashboard (goals from Settings → Sales Goals; sales = Conversion Breakdown gross).
    Bars use brand purple; the pace tick is ink. Goal hit / ahead / behind use teal and coral with a text label.
--}}
<section {{ $attributes->merge(['class' => 'flex min-w-0 flex-col rounded-2xl border border-line bg-white/60 p-4']) }} aria-labelledby="goals-title">
    <header class="mb-2 flex h-8 flex-wrap items-center justify-between gap-3">
        <h2 id="goals-title" class="text-base font-semibold">Sales Goals</h2>
        <div class="flex items-center gap-3 text-xs">
            @can('sales_goals.manage')
                <a href="{{ route('settings.sales-goals.index') }}" class="font-semibold text-muted hover:text-brand-600">Set goals</a>
            @endcan
        </div>
    </header>

    <div class="grid flex-1 gap-4 md:grid-cols-8">
        {{-- CRD monthly goal --}}
        <article class="relative flex flex-col justify-between gap-3 overflow-hidden rounded-xl bg-gradient-to-br from-brand-600 to-brand-700 p-4 text-white shadow-sm md:col-span-3">
            <span aria-hidden="true" class="absolute -top-8 -right-8 size-28 rounded-full bg-white/15"></span>
            <div class="relative">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-xs font-medium text-white/90">CRD monthly goal · {{ $month['label'] }}</p>
                    @if ($month['progress'] !== null)
                        <span @class([
                            'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                            'bg-white text-teal-700' => $month['progress'] >= 1 || $ahead,
                            'bg-white text-coral-700' => $month['progress'] < 1 && ! $ahead,
                        ])>{{ $month['progress'] >= 1 ? '✓ Goal hit' : ($ahead ? '▲ Ahead' : '▼ Behind') }}</span>
                    @endif
                </div>
                <p class="mt-1 text-3xl font-bold tabular-nums">{{ $pct($month['progress']) }}</p>
                <p class="text-xs text-white/90"><span class="font-semibold text-white tabular-nums">{{ $peso($month['sales']) }}</span> of {{ $peso($month['goal']) }}</p>
            </div>

            <div class="relative">
                <div class="relative h-2.5 rounded-full bg-white/25" role="progressbar" aria-label="CRD monthly goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round(min(1, $month['progress'] ?? 0) * 100) }}">
                    <span class="absolute inset-y-0 left-0 rounded-full bg-white" style="width: {{ min(100, ($month['progress'] ?? 0) * 100) }}%"></span>
                    {{-- Where sales should be by today if they were spread evenly over the month --}}
                    <span class="absolute -inset-y-1 w-0.5 rounded bg-[#ffe5a0]" style="left: {{ min(100, $month['pace'] * 100) }}%" title="Pace for day {{ $month['day'] }}: {{ $pct($month['pace']) }}"></span>
                </div>
                <p class="mt-1.5 flex flex-wrap justify-between gap-x-2 text-[11px] text-white/90">
                    <span>Day {{ $month['day'] }}/{{ $month['days'] }} · pace <span class="font-semibold text-[#ffe5a0]">{{ $pct($month['pace']) }}</span></span>
                    <span>{{ $month['remaining'] > 0 ? $short($month['remaining']).' to go' : 'Goal reached' }}</span>
                </p>
            </div>
        </article>

        {{-- Goal per CRA for the picked day, week so far or month so far --}}
        <article class="rounded-xl bg-white p-4 shadow-sm md:col-span-5">
            <div class="mb-2.5 flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-2 text-xs font-medium text-muted">
                    <span class="grid size-6 place-items-center rounded-md bg-gradient-to-br from-[#0e8f7c] to-[#0b7d6c] text-white" aria-hidden="true">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.5"/></svg>
                    </span>
                    Goal per CRA · {{ $goals['range']['label'] }}
                    @if ($leadsFrom = \App\Support\WorkingDate::leadDaysLabel($goals['range']['from'], $goals['today']))
                        <span class="rounded-full bg-[#fff6d6] px-2 py-0.5 text-[10px] font-semibold text-[#473821]" title="Pancake sales on these days, made while working these lead days.">leads from {{ $leadsFrom }}</span>
                    @endif
                </p>
                <p class="text-[11px] text-muted">{{ $short($goals['general_daily']) }} per CRA per day{{ $goals['range']['days'] > 1 ? ' × '.$goals['range']['days'].' days' : '' }}</p>
            </div>
            <ul class="space-y-2">
                @forelse ($goals['cras'] as $row)
                    @php($hit = $row['progress'] !== null && $row['progress'] >= 1)
                    <li class="grid grid-cols-[minmax(0,6rem)_1fr_auto] items-center gap-2.5 text-sm" title="{{ $row['cra']->displayName() }}: {{ $peso($row['sales']) }} of {{ $peso($row['goal']) }}">
                        <span class="truncate font-medium">{{ $row['cra']->displayName() }}</span>
                        <span class="relative h-2 rounded-full bg-canvas" role="progressbar" aria-label="{{ $row['cra']->displayName() }} daily goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round(min(1, $row['progress'] ?? 0) * 100) }}">
                            <span @class(['absolute inset-y-0 left-0 rounded-full', 'bg-teal-700' => $hit, 'bg-brand-600' => ! $hit]) style="width: {{ min(100, ($row['progress'] ?? 0) * 100) }}%"></span>
                        </span>
                        <span class="flex items-baseline gap-2 tabular-nums">
                            <span @class(['w-12 text-right font-semibold', 'text-teal-700' => $hit])>{{ $pct($row['progress']) }}</span>
                            <span class="hidden w-24 text-right text-[11px] text-muted sm:inline">{{ $short($row['sales']) }} / {{ $short($row['goal']) }}@if ($row['own_goal'])<span title="This CRA has their own daily goal">*</span>@endif</span>
                        </span>
                    </li>
                @empty
                    <li class="text-sm text-muted">No active CRAs yet.</li>
                @endforelse
            </ul>
            @if ($goals['cras']->contains('own_goal', true))
                <p class="mt-2 text-[11px] text-muted">* Own daily goal set in Settings → Sales Goals.</p>
            @endif
        </article>
    </div>
</section>
