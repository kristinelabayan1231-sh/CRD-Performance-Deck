{{-- One month (or the year) for a CRA or TOTAL: its Gross and Net columns, — in both without figures. $first: the
     Gross column's left border, which separates the months. $top: the month's top seller (blue); $goal: Net Income under
     it shows red. --}}
@php
    $isTop = ($top ?? false) && $cell !== null;
    $belowGoal = ($goal ?? null) !== null && $cell !== null && $cell['net'] < $goal;
@endphp
<td @class([$class, $first, 'px-3 py-2 text-right whitespace-nowrap', 'font-bold text-sky-700' => $isTop])>
    {!! $cell === null ? '<span class="text-muted/60">—</span>' : e(number_format($cell['gross'], 2)) !!}
</td>
<td @class([
    $class, 'px-3 py-2 text-right font-semibold whitespace-nowrap',
    'font-bold text-sky-700' => $isTop && ! $belowGoal,
    'text-coral-700' => $belowGoal || ($cell !== null && $cell['net'] < 0),
])>
    {!! $cell === null ? '<span class="text-muted/60">—</span>' : e(number_format($cell['net'], 2)) !!}
</td>
