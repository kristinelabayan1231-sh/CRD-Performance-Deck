@props(['periods', 'fetchedAt', 'active' => 'today'])

@php
    $pct = fn (?float $value) => $value === null ? '—' : number_format($value * 100, 2).'%';
    // One period, picked by the dashboard's shared Today / Week / Month switch.
    $default = $active;
    $periods = $periods ? [$active => $periods[$active]] : $periods;
@endphp

{{-- Live from Logistics: the logistics website's Retention Summary, from the Shecom retention report (by delivered date). --}}
<section {{ $attributes->merge(['class' => 'rounded-2xl border border-line bg-white/60 p-4']) }} aria-labelledby="logistics-title">
    <header class="mb-2 flex h-8 flex-wrap items-center justify-between gap-3">
        <h2 id="logistics-title" class="flex items-center gap-2 text-base font-semibold">
            Live from Logistics
            @if ($periods)
                <span class="rounded-full bg-canvas px-2.5 py-0.5 text-xs font-semibold text-muted">{{ $periods[$active]['label'] }}</span>
            @endif
            <span class="text-xs font-normal text-ink/80">
                @if ($fetchedAt)
                    · Updated {{ $fetchedAt->timezone(config('segmentation.timezone'))->format('M j, g:i A') }}
                @endif
            </span>
        </h2>
    </header>

    @if (! $periods)
        <p class="rounded-xl bg-white p-4 text-sm text-muted shadow-sm">Logistics numbers aren't available yet. They load with the next lead sync; refresh in a few minutes.</p>
    @else
        @foreach ($periods as $key => $p)
            <div role="tabpanel" data-tab-panel="{{ $key }}" @unless ($key === $default) hidden @endunless class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @php($icons = [
                    'truck' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 7h11v9H3zM14 10h4l3 3v3h-7M7.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm10 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>',
                    'repeat' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 2l3 3-3 3M4 11V9a4 4 0 0 1 4-4h12M7 22l-3-3 3-3M20 13v2a4 4 0 0 1-4 4H4"/>',
                    'percent' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 5 5 19M7 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/>',
                ])
                @foreach ([
                    ['truck', 'FB delivered', number_format($p['fb_delivered']), 'Facebook Sales (FSD)', 'Facebook Sales (FSD) orders delivered in this period.', 'bg-[#e3f3f9]', 'text-[#0c5a7d]'],
                    ['repeat', 'Retained by CRD', number_format($p['fb_retained']), 'Later resold by CRD', 'FB-delivered customers who later bought again through CRD.', 'bg-[#e3f3f9]', 'text-[#0c5a7d]'],
                    ['percent', 'Retention rate', $pct($p['retention_rate']), 'Retained ÷ FB delivered', 'Retained by CRD ÷ Total FB delivered.', 'bg-gradient-to-br from-[#1f8fb8] to-[#1a7fa6] text-white', null],
                    ['truck', 'CRD delivered', number_format($p['crd_delivered']), 'CRD orders', 'CRD orders delivered in this period.', 'bg-[#ddf3ee]', 'text-[#0b6b5d]'],
                    ['repeat', 'Actual Order', number_format($p['crd_again']), null, 'CRD-delivered customers who placed another CRD order after delivery.', 'bg-[#ddf3ee]', 'text-[#0b6b5d]'],
                    ['percent', 'Repeat rate', $pct($p['repeat_rate']), 'Actual ÷ CRD delivered', 'Actual Order ÷ Total CRD delivered.', 'bg-gradient-to-br from-[#0e8f7c] to-[#0b7d6c] text-white', null],
                ] as [$icon, $label, $value, $note, $help, $surface, $valueColor])
                    <div class="relative min-w-0 overflow-hidden rounded-xl p-3 shadow-sm {{ $surface }}" title="{{ $help }} ({{ $p['label'] }}, by delivered date)">
                        @if (! $valueColor)
                            <span aria-hidden="true" class="absolute -top-6 -right-6 size-16 rounded-full bg-white/15"></span>
                        @endif
                        <p @class(['relative flex items-center gap-1.5 text-[11px] font-semibold tracking-wide uppercase', $valueColor => $valueColor, 'text-white' => ! $valueColor])>
                            <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">{!! $icons[$icon] !!}</svg>
                            <span class="truncate">{{ $label }}</span>
                        </p>
                        <p class="relative text-2xl font-bold tabular-nums {{ $valueColor }}">{{ $value }}</p>
                        @if ($note)
                            <p @class(['relative truncate text-[11px]', 'text-ink/80' => $valueColor, 'text-white' => ! $valueColor])>{{ $note }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif
</section>
