@props(['title', 'accent' => 'brand', 'icon' => null, 'titleId' => null, 'bodyClass' => 'p-4', 'tinted' => true])

@php
    // One quiet look for every accent (black + gold palette): a white card, a plain header over a hairline, a gold-soft
    // icon chip. The accent stays in the API so the pages that pass one keep working.
    $neutral = ['header' => 'from-white to-white border-line', 'chip' => 'from-brand-100 to-brand-100', 'body' => 'bg-white'];
    $accents = ['brand' => $neutral, 'teal' => $neutral, 'sky' => $neutral, 'coral' => $neutral, 'amber' => $neutral];
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
        'pie' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v9h9a9 9 0 1 1-9-9Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 3.5A9 9 0 0 1 20.5 9H15z"/>',
        'bulb' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3Z"/>',
        'tag' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12V4h8l10 10-8 8L3 12Z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
        'box' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 8 12 3 3 8v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8"/>',
    ];
@endphp

{{-- A page section: a header (icon, title, badges, actions) over the body, on a white card. --}}
<section {{ $attributes->merge(['class' => 'rounded-2xl border border-line bg-white shadow-sm']) }} @if ($titleId) aria-labelledby="{{ $titleId }}" @endif>
    {{-- Header above the body (z-20) so pop-overs opened from it, like the "i" info, aren't clipped --}}
    <header class="relative z-20 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 rounded-t-2xl border-b bg-gradient-to-r px-4 py-2.5 {{ $style['header'] }}">
        <div class="flex min-w-0 flex-wrap items-center gap-2.5">
            @if ($icon)
                <span aria-hidden="true" class="grid size-8 shrink-0 place-items-center rounded-full bg-gradient-to-br text-ink {{ $style['chip'] }}">
                    <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">{!! $icons[$icon] ?? '' !!}</svg>
                </span>
            @endif
            <h2 @if ($titleId) id="{{ $titleId }}" @endif class="text-[1.0625rem] font-bold text-ink">{{ $title }}</h2>
            {{ $badges ?? '' }}
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-3 text-xs">{{ $actions }}</div>
        @endisset
    </header>
    <div @class(['overflow-hidden rounded-b-2xl', $style['body'] => $tinted, $bodyClass])>{{ $slot }}</div>
</section>
