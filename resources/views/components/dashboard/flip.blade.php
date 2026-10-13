{{-- A Results tile that turns over to its graph (the header's graph button flips them all; resources/js/results-flip.js) --}}
<div {{ $attributes->merge(['class' => 'grid perspective-[1200px]']) }}>
    <div data-results-flip class="grid transition-transform duration-500 transform-3d motion-reduce:transition-none data-flipped:rotate-y-180">
        <div data-flip-face="front" class="col-start-1 row-start-1 grid backface-hidden">{{ $slot }}</div>
        <div data-flip-face="back" inert class="col-start-1 row-start-1 min-w-0 rotate-y-180 rounded-xl bg-white p-3 shadow-sm ring-1 ring-line backface-hidden">{{ $back }}</div>
    </div>
</div>
