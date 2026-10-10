@props(['title', 'accent' => 'brand', 'icon' => null, 'titleId' => null, 'bodyClass' => 'p-4', 'tinted' => true])

@php
    // Header band, icon chip and tinted body per accent; the tint lets white tiles inside stand out.
    $accents = [
        'brand' => ['header' => 'from-brand-100 to-brand-50 border-brand-200', 'chip' => 'from-brand-500 to-brand-700', 'body' => 'bg-brand-50/50'],
        'teal' => ['header' => 'from-[#d3f3ec] to-[#ecfaf6] border-[#b9e8de]', 'chip' => 'from-[#0e8f7c] to-[#0b6b5d]', 'body' => 'bg-[#f2fbf8]'],
        'sky' => ['header' => 'from-[#d4eef8] to-[#ebf7fc] border-[#bfe3f1]', 'chip' => 'from-[#1f8fb8] to-[#156c8c]', 'body' => 'bg-[#f2f9fc]'],
        'coral' => ['header' => 'from-[#fde0e0] to-[#fff0f0] border-[#f8c9ca]', 'chip' => 'from-[#e05a5f] to-[#c4484c]', 'body' => 'bg-[#fff7f7]'],
        'amber' => ['header' => 'from-[#fdecc0] to-[#fff7e0] border-[#f3dc9a]', 'chip' => 'from-[#d99a0b] to-[#a8740a]', 'body' => 'bg-[#fffaf0]'],
    ];
    $style = $accents[$accent] ?? $accents['brand'];
    $icons = [
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1" fill="currentColor"/>',
        'truck' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 7h11v9H3zM14 10h4l3 3v3h-7M7.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm10 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm13 9v-1a4 4 0 0 0-3-3.9M16 4.1a3 3 0 0 1 0 5.8"/>',
        'chart' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="m8 12 3 3 5-6"/>',
        'table' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M3 15h18M9 10v10"/>',
        'funnel' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18l-7 8v6l-4 2v-8z"/>',
        'database' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"/>',
    ];
@endphp

{{-- A page section: coloured header band (icon, title, badges, actions) over a tinted body. --}}
<section {{ $attributes->merge(['class' => 'rounded-2xl border border-line bg-white shadow-sm']) }} @if ($titleId) aria-labelledby="{{ $titleId }}" @endif>
    {{-- Header above the body (z-20) so pop-overs opened from it, like the "i" info, aren't clipped --}}
    <header class="relative z-20 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 rounded-t-2xl border-b bg-gradient-to-r px-4 py-2.5 {{ $style['header'] }}">
        <div class="flex min-w-0 flex-wrap items-center gap-2.5">
            @if ($icon)
                <span aria-hidden="true" class="grid size-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br text-white shadow-sm {{ $style['chip'] }}">
                    <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">{!! $icons[$icon] ?? '' !!}</svg>
                </span>
            @endif
            <h2 @if ($titleId) id="{{ $titleId }}" @endif class="text-base font-bold text-ink">{{ $title }}</h2>
            {{ $badges ?? '' }}
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-3 text-xs">{{ $actions }}</div>
        @endisset
    </header>
    <div @class(['overflow-hidden rounded-b-2xl', $style['body'] => $tinted, $bodyClass])>{{ $slot }}</div>
</section>
