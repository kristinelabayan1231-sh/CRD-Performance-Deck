@props(['rest' => 0, 'leave' => 0, 'counts' => true])

{{-- A CRA's days off in a period (DutyGoals): "Rest Day" and/or "Absent / Leave", with "· N days" when $counts. --}}
@foreach ([[$rest, 'Rest Day', 'bg-sky/15 text-sky-800'], [$leave, 'Absent / Leave', 'bg-coral/15 text-coral-700']] as [$days, $label, $colors])
    @if ($days > 0)
        <span {{ $attributes->class(['inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap', $colors]) }}>{{ $label }}{{ $counts ? ' · '.$days.' '.Str::plural('day', $days) : '' }}</span>
    @endif
@endforeach
