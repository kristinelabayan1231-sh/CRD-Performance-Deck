@props(['href', 'active' => false, 'icon' => null, 'sub' => false])

{{-- A sidebar link on the dark panel: an icon and its label, or (sub) a dot and the label inside a group. --}}
<a href="{{ $href }}" @if ($active) aria-current="page" @endif
   @class([
       'flex items-center gap-3 rounded-lg transition focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:outline-none',
       'min-h-10 px-3 font-medium' => ! $sub,
       'min-h-9 px-2.5 text-[13.5px] font-medium' => $sub,
       'bg-brand-600 font-semibold text-white' => $active,
       'text-[#d8d0ee] hover:bg-white/5 hover:text-white' => ! $active,
   ])>
    @if ($sub)
        <span @class(['size-1.5 shrink-0 rounded-full', 'bg-white' => $active, 'bg-[#6d628f]' => ! $active]) aria-hidden="true"></span>
    @elseif ($icon)
        <x-nav-icon :name="$icon" class="size-5 shrink-0" />
    @endif
    <span>{{ $slot }}</span>
</a>
