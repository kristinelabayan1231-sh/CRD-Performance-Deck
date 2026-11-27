{{-- In Filters: the day picked, or the whole month's list. --}}
@php($picked = array_filter(['cra' => $cra->id, 'type' => $filters['type'], 'product' => $filters['product']]))
<nav aria-label="Leads shown" class="grid grid-cols-2 gap-2 text-sm">
    @foreach ([[$day->format('M j').' only', ['date' => $day->toDateString()], ! $wholeMonth && ! $range], ['Whole month', ['date' => $day->toDateString(), 'view' => 'month'], $wholeMonth]] as [$label, $query, $active])
        <a href="{{ route('exclusive-leads.index', [...$picked, ...$query]) }}" @if ($active) aria-current="page" @endif
           @class(['grid h-9 place-items-center rounded-lg border px-2 font-semibold', 'border-brand-500 bg-brand-50 text-brand-700' => $active, 'border-line hover:border-brand-400 hover:text-brand-600' => ! $active])>{{ $label }}</a>
    @endforeach
</nav>
