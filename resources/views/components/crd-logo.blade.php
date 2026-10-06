@props(['variant' => 'color'])

@php($id = 'crd-'.\Illuminate\Support\Str::random(6))

{{-- CRD mark: a rounded tile with rising performance bars and the CRD wordmark. --}}
<svg {{ $attributes->merge(['viewBox' => '0 0 120 120', 'role' => 'img', 'aria-label' => 'CRD logo']) }} xmlns="http://www.w3.org/2000/svg">
    <defs>
        <linearGradient id="{{ $id }}-bg" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#A05AFF" />
            <stop offset="1" stop-color="#4BCBEB" />
        </linearGradient>
    </defs>

    @if ($variant === 'light')
        <rect x="4" y="4" width="112" height="112" rx="30" fill="#ffffff" fill-opacity=".16" stroke="#ffffff" stroke-opacity=".55" stroke-width="2" />
    @else
        <rect x="4" y="4" width="112" height="112" rx="30" fill="url(#{{ $id }}-bg)" />
    @endif

    {{-- Rising bars --}}
    <rect x="26" y="62" width="14" height="22" rx="5" fill="#FE9496" />
    <rect x="46" y="48" width="14" height="36" rx="5" fill="#1BCFB4" />
    <rect x="66" y="34" width="14" height="50" rx="5" fill="#ffffff" />
    <circle cx="90" cy="28" r="7" fill="#ffffff" fill-opacity=".9" />

    <text x="60" y="104" text-anchor="middle" font-family="Instrument Sans, ui-sans-serif, system-ui, sans-serif"
          font-size="17" font-weight="700" letter-spacing="4" fill="#ffffff">CRD</text>
</svg>
