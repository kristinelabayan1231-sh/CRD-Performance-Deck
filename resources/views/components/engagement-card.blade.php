@props([
    'title',
    'subtitle' => null,
    'total',
    'newLabel',
    'newValue',
    'oldLabel',
    'oldValue',
    'changePercent' => null,
    'compareRangeLabel' => null,
    'messages' => null,
    'comments' => null,
])

@php
    $newPct = $total > 0 ? round(($newValue / $total) * 100, 2) : 0;
    $oldPct = $total > 0 ? round(100 - $newPct, 2) : 0;
    $arcDeg = $total > 0 ? round(($newValue / $total) * 180, 1) : 0;

    $icons = [
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'chat' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
    ];

    $badge = function (?float $pct) {
        if ($pct === null) {
            return null;
        }

        return [
            'positive' => $pct >= 0,
            'text' => ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%',
        ];
    };

    $totalBadge = $badge($changePercent);
@endphp

<div class="rounded-xl bg-white p-5 shadow-sm">
    <p class="text-sm font-semibold text-slate-900">{{ $title }}</p>
    @if ($subtitle)
        <p class="text-xs text-slate-400">{{ $subtitle }}</p>
    @endif

    <div class="mt-4 flex flex-wrap items-start gap-6">
        <div class="shrink-0">
            <div class="relative h-28 w-56 overflow-hidden">
                <div class="absolute left-0 top-0 h-56 w-56 rounded-full"
                    style="background: conic-gradient(from 270deg, #0d9488 0deg {{ $arcDeg }}deg, #e2e8f0 {{ $arcDeg }}deg 180deg, transparent 180deg 360deg);">
                </div>
                <div class="absolute inset-x-0 bottom-0 flex flex-col items-center pb-1">
                    <span class="text-[10px] font-medium uppercase tracking-wide text-slate-400">Total engagement</span>
                    <span class="text-2xl font-semibold text-slate-900">{{ number_format($total) }}</span>
                </div>
            </div>

            @if ($totalBadge)
                <div class="mt-1.5 flex justify-center">
                    <div class="group relative inline-flex">
                        <span class="inline-flex cursor-default items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $totalBadge['positive'] ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600' }}">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                @if ($totalBadge['positive'])
                                    <polyline points="18 15 12 9 6 15"/>
                                @else
                                    <polyline points="6 9 12 15 18 9"/>
                                @endif
                            </svg>
                            {{ $totalBadge['text'] }}
                        </span>

                        @if ($compareRangeLabel)
                            <div class="pointer-events-none absolute left-1/2 top-full z-30 mt-2 w-max -translate-x-1/2 rounded-md bg-slate-900 px-3 py-2 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100">
                                Compared to the day {{ $compareRangeLabel }}
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <div class="mt-1.5 flex items-center justify-between text-xs font-medium text-slate-500">
                <span>{{ number_format($newPct, 2) }}%</span>
                <span>{{ number_format($oldPct, 2) }}%</span>
            </div>
        </div>

        <div class="space-y-2 text-sm">
            <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-sm bg-teal-600"></span>
                <span class="text-slate-500">{{ $newLabel }}</span>
                <span class="font-semibold text-slate-900">{{ number_format($newValue) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-sm bg-slate-300"></span>
                <span class="text-slate-500">{{ $oldLabel }}</span>
                <span class="font-semibold text-slate-900">{{ number_format($oldValue) }}</span>
            </div>
        </div>
    </div>

    @if ($messages || $comments)
        <div class="mt-5 grid grid-cols-1 gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2">
            @foreach ([$messages, $comments] as $stat)
                @continue(! $stat)
                @php
                    $statBadge = $badge($stat['change'] ?? null);
                @endphp
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $stat['iconBg'] ?? 'bg-teal-50 text-teal-600' }}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            {!! $icons[$stat['icon']] ?? '' !!}
                        </svg>
                    </span>
                    <div>
                        <p class="text-xs text-slate-400">{{ $stat['label'] }}</p>
                        <div class="flex items-center gap-2">
                            <span class="text-lg font-semibold text-slate-900">{{ number_format($stat['value']) }}</span>
                            @if ($statBadge)
                                <span class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-semibold {{ $statBadge['positive'] ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600' }}">
                                    <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                        @if ($statBadge['positive'])
                                            <polyline points="18 15 12 9 6 15"/>
                                        @else
                                            <polyline points="6 9 12 15 18 9"/>
                                        @endif
                                    </svg>
                                    {{ $statBadge['text'] }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
