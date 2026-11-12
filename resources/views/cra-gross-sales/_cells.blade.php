{{-- One month (or the year) for a CRA or TOTAL: its Gross and Net columns, — in both without figures. $first: the
     Gross column's left border, which separates the months. --}}
<td class="{{ $class }} {{ $first }} px-3 py-2 text-right whitespace-nowrap">
    {!! $cell === null ? '<span class="text-muted/60">—</span>' : e(number_format($cell['gross'], 2)) !!}
</td>
<td @class([$class, 'px-3 py-2 text-right font-semibold whitespace-nowrap', 'text-coral-700' => $cell !== null && $cell['net'] < 0])>
    {!! $cell === null ? '<span class="text-muted/60">—</span>' : e(number_format($cell['net'], 2)) !!}
</td>
