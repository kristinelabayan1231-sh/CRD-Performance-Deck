@props(['title', 'subtitle' => null, 'total', 'newLabel', 'newValue', 'oldLabel', 'oldValue'])

@php
    $newPct = $total > 0 ? round(($newValue / $total) * 180, 1) : 0;
@endphp

<div class="rounded-xl bg-white p-5 shadow-sm">
    <p class="text-sm font-semibold text-slate-900">{{ $title }}</p>
    @if ($subtitle)
        <p class="text-xs text-slate-400">{{ $subtitle }}</p>
    @endif

    <div class="mt-4 flex flex-wrap items-center gap-6">
        <div class="relative h-28 w-56 shrink-0 overflow-hidden">
            <div class="absolute left-0 top-0 h-56 w-56 rounded-full"
                style="background: conic-gradient(from 270deg, #0d9488 0deg {{ $newPct }}deg, #cbd5e1 {{ $newPct }}deg 180deg, transparent 180deg 360deg);">
            </div>
            <div class="absolute inset-x-0 bottom-0 flex flex-col items-center pb-1">
                <span class="text-[10px] font-medium uppercase tracking-wide text-slate-400">Total</span>
                <span class="text-2xl font-semibold text-slate-900">{{ number_format($total) }}</span>
            </div>
        </div>
        <div class="space-y-2 text-sm">
            <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-teal-600"></span>
                <span class="text-slate-500">{{ $newLabel }}</span>
                <span class="font-semibold text-slate-900">{{ number_format($newValue) }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-slate-300"></span>
                <span class="text-slate-500">{{ $oldLabel }}</span>
                <span class="font-semibold text-slate-900">{{ number_format($oldValue) }}</span>
            </div>
        </div>
    </div>
</div>