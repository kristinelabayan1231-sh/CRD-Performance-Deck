@props(['periods'])

@php($pct = fn (?float $value) => $value === null ? '—' : number_format($value * 100, 2).'%')

{{--
    Conversion Breakdown on the dashboard: Total conv % per CRA = (Orders BC + Orders SC) ÷ (Engagements + Leads).
    Bars are brand purple, scaled to the period's highest rate; the ink tick is the team rate.
--}}
<section {{ $attributes->merge(['class' => 'flex min-w-0 flex-col']) }} aria-labelledby="conv-dash-title" data-tabs="dashboard.conversion">
    <header class="mb-2 flex h-8 flex-wrap items-center justify-between gap-2">
        <h2 id="conv-dash-title" class="text-base font-semibold">Conversion</h2>
        <div class="flex items-center gap-2">
            <div class="flex rounded-lg bg-white p-0.5 text-xs font-semibold shadow-sm" role="tablist" aria-label="Period">
                @foreach ($periods as $key => $p)
                    <button type="button" role="tab" data-tab="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                            class="rounded-md px-2.5 py-1 text-muted transition aria-selected:bg-brand-600 aria-selected:text-white">{{ $p['name'] }}</button>
                @endforeach
            </div>
            <a href="{{ route('conversion.index') }}" class="text-xs font-semibold text-brand-600 hover:underline">Open &rarr;</a>
        </div>
    </header>

    @foreach ($periods as $key => $p)
        @php($top = max(0.01, $p['rows']->max(fn ($row) => $row['totals']['total_rate'] ?? 0) ?? 0, $p['team']['total_rate'] ?? 0))
        <article role="tabpanel" data-tab-panel="{{ $key }}" @unless ($loop->first) hidden @endunless class="flex-1 rounded-xl bg-white p-4 shadow-sm">
            <div class="mb-2.5 flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-2 text-xs font-medium text-muted">
                    <span class="grid size-6 place-items-center rounded-md bg-gradient-to-br from-[#1f8fb8] to-[#1a7fa6] text-white" aria-hidden="true">
                        <svg class="size-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M3 4h18l-7 8.5V19l-4 2v-8.5L3 4Z"/></svg>
                    </span>
                    Total conv % per CRA · {{ $p['label'] }}
                    @if ($p['leads_from'])
                        <span class="rounded-full bg-[#fff6d6] px-2 py-0.5 text-[10px] font-semibold text-[#473821]" title="Results are by the real date; the leads worked are from the paired lead days.">leads from {{ $p['leads_from'] }}</span>
                    @endif
                </p>
                <p class="text-[11px] text-muted" title="{{ number_format($p['team']['orders']) }} orders of {{ number_format($p['team']['reach']) }} engagements + leads">
                    Team <span class="font-semibold text-ink tabular-nums">{{ $pct($p['team']['total_rate']) }}</span>
                </p>
            </div>
            <ul class="space-y-2">
                @forelse ($p['rows'] as $row)
                    @php($t = $row['totals'])
                    <li class="grid grid-cols-[minmax(0,6rem)_1fr_auto] items-center gap-2.5 text-sm" title="{{ $row['cra']->displayName() }}: {{ number_format($t['orders']) }} orders ÷ {{ number_format($t['reach']) }} engagements + leads">
                        <span class="truncate font-medium" title="{{ $row['cra']->displayName() }}">{{ $row['cra']->displayName() }}</span>
                        <span class="relative h-2 rounded-full bg-canvas">
                            <span class="absolute inset-y-0 left-0 rounded-full bg-brand-600" style="width: {{ min(100, ($t['total_rate'] ?? 0) / $top * 100) }}%"></span>
                            @if ($p['team']['total_rate'] > 0 && $p['rows']->count() > 1)
                                <span class="absolute -inset-y-1 w-0.5 rounded bg-ink" style="left: {{ min(100, $p['team']['total_rate'] / $top * 100) }}%" aria-hidden="true"></span>
                            @endif
                        </span>
                        <span class="w-14 text-right font-semibold tabular-nums">{{ $pct($t['total_rate']) }}</span>
                    </li>
                @empty
                    <li class="text-sm text-muted">No active CRAs yet.</li>
                @endforelse
            </ul>
            @if ($p['rows']->count() > 1)
                <p class="mt-2 flex items-center gap-1.5 text-[11px] text-muted"><span class="inline-block h-3 w-0.5 rounded bg-ink"></span> Team rate · (BC + SC orders) ÷ (engagements + leads)</p>
            @endif
        </article>
    @endforeach
</section>
