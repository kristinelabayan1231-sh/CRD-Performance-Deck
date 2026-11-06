{{-- A goal ring: $value of $target, with the percent in the middle. $stroke is the arc's colour class. --}}
@php($percent = $target > 0 ? min(100, round($value / $target * 100)) : 0)
<div class="flex flex-col items-center gap-1 text-center">
    <div class="relative size-24" role="img" aria-label="{{ $label }}: {{ number_format($value) }} of {{ number_format($target) }}, {{ $percent }} percent">
        <svg viewBox="0 0 36 36" class="size-full -rotate-90" aria-hidden="true">
            <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="4" class="stroke-line"/>
            @if ($percent > 0)
                <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="4" stroke-linecap="round" pathLength="100" stroke-dasharray="{{ $percent }} 100" class="{{ $stroke }}"/>
            @endif
        </svg>
        <span class="absolute inset-0 grid place-items-center text-lg font-bold tabular-nums">{{ $percent }}%</span>
    </div>
    <p class="text-sm font-semibold">{{ $label }}</p>
    <p class="text-xs text-muted tabular-nums"><strong class="text-ink">{{ number_format($value) }}</strong> of {{ number_format($target) }}</p>
    @isset($sub)<p class="text-[0.6875rem] text-muted">{{ $sub }}</p>@endisset
</div>
