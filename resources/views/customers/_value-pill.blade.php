{{-- Same pills as Customer Database → High AOV CVR & VIP; a VIP shows only VIP --}}
@if ($flags['vip'] ?? false)
    <span class="inline-flex items-center gap-1 rounded-full bg-[#1a1814] px-2 py-0.5 text-[0.6875rem] font-bold tracking-wide whitespace-nowrap text-[#f3dca3]"
          title="VIP in {{ implode(', ', $flags['vip_products']) }}"><svg aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" class="size-3 text-[#c9a24a]"><path d="M3 18h18l-1.5-10-5 4L12 5l-2.5 7-5-4z"/></svg>VIP</span>
@elseif ($flags['high_aov'] ?? false)
    <span class="rounded-full bg-brand-100 px-2 py-0.5 text-[0.6875rem] font-bold tracking-wide whitespace-nowrap text-brand-700">HIGH AOV</span>
@endif
