@props(['variant' => 'color'])

{{-- CRD mark: a rounded black tile with rising gold performance bars and the CRD wordmark. "rail" sits on the black
     sidebar (a lighter tile, quiet dot and wordmark); "light" is the outline on a dark or coloured background. --}}
<svg {{ $attributes->merge(['viewBox' => '0 0 120 120', 'role' => 'img', 'aria-label' => 'CRD logo']) }} xmlns="http://www.w3.org/2000/svg">
    @if ($variant === 'light')
        <rect x="4" y="4" width="112" height="112" rx="30" fill="#ffffff" fill-opacity=".16" stroke="#ffffff" stroke-opacity=".55" stroke-width="2" />
    @else
        <rect x="4" y="4" width="112" height="112" rx="30" fill="{{ $variant === 'rail' ? '#1f1f1f' : '#111111' }}" />
    @endif

    {{-- Rising bars --}}
    <rect x="26" y="62" width="14" height="22" rx="5" fill="#8a6100" />
    <rect x="46" y="48" width="14" height="36" rx="5" fill="#ffc21a" />
    <rect x="66" y="34" width="14" height="50" rx="5" fill="#ffe08a" />
    <circle cx="90" cy="28" r="7" fill="{{ $variant === 'rail' ? '#4a4a4a' : '#ffffff' }}" />

    <text x="60" y="104" text-anchor="middle" font-family="Hanken Grotesk, Segoe UI, ui-sans-serif, system-ui, sans-serif"
          font-size="17" font-weight="700" letter-spacing="4" fill="{{ $variant === 'rail' ? '#5a5a5a' : '#ffffff' }}">CRD</text>
</svg>
