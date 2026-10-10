@props(['label' => 'What do these mean?'])

{{-- An "i" button that opens a short explanation (click to open, works on touch). --}}
<details {{ $attributes->merge(['class' => 'relative']) }}>
    <summary title="{{ $label }}" aria-label="{{ $label }}"
             class="grid size-7 cursor-pointer list-none place-items-center rounded-full border border-current/20 bg-white text-xs font-bold text-brand-600 italic shadow-sm hover:bg-brand-50 [&::-webkit-details-marker]:hidden">
        i
    </summary>
    <div class="absolute right-0 z-30 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-xl border border-line bg-white p-4 text-left text-xs leading-relaxed font-normal text-ink normal-case shadow-lg">
        {{ $slot }}
    </div>
</details>
