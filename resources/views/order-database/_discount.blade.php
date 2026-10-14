{{-- Pancake's discount on these orders (e.g. a GCash payment entered as a discount): the sales already count it, the pill keeps it visible. --}}
@if ($discount > 0)
    <span class="ml-2 inline-block rounded-full bg-[#ffe5a0] px-2 py-0.5 font-sans text-[11px] font-semibold whitespace-nowrap text-[#473821]">Discounted: {{ $peso($discount) }}</span>
@endif
