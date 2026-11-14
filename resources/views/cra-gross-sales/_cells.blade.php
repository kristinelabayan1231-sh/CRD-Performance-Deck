{{-- One month (or the year) for a CRA or TOTAL: its Gross and Net columns, — in both without figures. $first: the
     Gross column's left border, which separates the months. $top: the month's top seller (blue); $goal: Net Income under
     it shows red. $input (edit mode, a CRA's month): boxes for its Gross and Net instead. --}}
@if ($input ?? null)
    @foreach (['gross' => 'Gross', 'net' => 'Net'] as $field => $label)
        <td @class([$class, $first => $field === 'gross', 'px-1.5 py-1'])>
            <input type="text" inputmode="decimal" data-cra-gross-sales-input data-cra="{{ $input['cra'] }}" data-month="{{ $input['month']->format('Y-m') }}" data-field="{{ $field }}"
                   value="{{ $input['typed'][$field] === null ? '' : number_format($input['typed'][$field], 2) }}" placeholder="—"
                   aria-label="{{ $label }} for {{ $input['name'] }}, {{ $input['month']->format('F Y') }}"
                   class="h-7 w-28 rounded-md border border-line bg-white px-1.5 text-right text-[0.8125rem] font-semibold tabular-nums placeholder:font-normal placeholder:text-muted/60 focus:border-brand-400 focus:ring-2 focus:ring-brand-200 focus:outline-none">
        </td>
    @endforeach
@else
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
@endif
