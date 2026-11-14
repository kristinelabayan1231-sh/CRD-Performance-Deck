{{-- A ACB cell (a CRA's or TOTAL's): — without any month counted, red below zero. --}}
<td @class([$class, 'border-l-2 border-l-brand-200 px-3 py-2 text-right whitespace-nowrap', 'text-coral-700' => $value !== null && $value < 0])>
    {!! $value === null ? '<span class="text-muted/60">—</span>' : e(number_format($value, 2)) !!}
</td>
