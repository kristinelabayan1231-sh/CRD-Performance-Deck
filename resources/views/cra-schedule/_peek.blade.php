@php
    use App\Models\DutySchedule;

    $peek = fn (string $view, \Carbon\CarbonImmutable $date) => route('cra-schedule.peek', ['view' => $view, 'date' => $date->toDateString()]);
    $title = $view === 'week'
        ? ($from->isSameMonth($to) ? $from->format('M j').' – '.$to->format('j, Y') : $from->format('M j').' – '.$to->format('M j, Y'))
        : $from->format('F Y');
    $showsToday = $today->betweenIncluded($from, $to);
@endphp

{{-- The floating schedule's body (schedule-peek.js loads it into the panel): every CRA's week or month. --}}
<div class="flex min-h-0 flex-1 flex-col" data-peek-body>
    <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2.5">
        <div class="flex rounded-lg bg-canvas p-0.5 text-xs font-semibold" role="group" aria-label="View">
            @foreach (['week' => 'Week', 'month' => 'Month'] as $value => $label)
                <button type="button" data-peek-url="{{ $peek($value, $view === $value ? $from : ($showsToday ? $today : $from)) }}" data-peek-view="{{ $value }}"
                        aria-pressed="{{ $view === $value ? 'true' : 'false' }}"
                        @class(['h-9.5 rounded-md px-4', 'bg-white text-ink shadow-sm' => $view === $value, 'text-muted hover:text-ink' => $view !== $value])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex items-center gap-2">
            <button type="button" data-peek-url="{{ $peek($view, $previous) }}" aria-label="Previous {{ $view }}" class="grid size-11 place-items-center rounded-lg border border-line hover:border-brand-400">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
            </button>
            <span class="min-w-32 text-center text-sm font-semibold" data-peek-title>{{ $title }}</span>
            <button type="button" data-peek-url="{{ $peek($view, $next) }}" aria-label="Next {{ $view }}" class="grid size-11 place-items-center rounded-lg border border-line hover:border-brand-400">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>
        @unless ($showsToday)
            <button type="button" data-peek-url="{{ $peek($view, $today) }}" class="h-11 rounded-lg px-2.5 text-xs font-semibold text-brand-600 hover:underline">Today</button>
        @endunless
    </div>

    <div class="min-h-0 flex-1 overflow-auto">
        @if ($rows->isEmpty())
            <p class="p-6 text-center text-sm text-muted">No active CRAs.</p>
        @elseif ($view === 'week')
            <table class="w-full min-w-[40rem] border-separate border-spacing-0 text-left text-xs">
                <thead class="sticky top-0 z-10 bg-white">
                    <tr>
                        <th class="sticky left-0 z-10 w-32 border-b border-line bg-white px-3 py-2 font-semibold text-muted">CRA</th>
                        @foreach ($days as $day)
                            <th @class(['border-b border-line px-1.5 py-2 text-center font-semibold', 'bg-brand-50 text-brand-700' => $day->isSameDay($today), 'text-muted' => ! $day->isSameDay($today)])>
                                <span class="block text-[0.625rem] tracking-wide uppercase">{{ $day->format('D') }}</span>
                                <span class="text-sm">{{ $day->day }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <th scope="row" class="sticky left-0 z-[1] border-b border-line bg-white px-3 py-2 font-medium">
                                <span class="flex items-center gap-2"><x-avatar :user="$row['cra']" class="size-6" /><span class="truncate">{{ $row['cra']->displayName() }}</span></span>
                            </th>
                            @foreach ($days as $day)
                                @php($cell = $row['days'][$day->toDateString()])
                                <td @class(['border-b border-line px-1 py-1.5 text-center', 'bg-brand-50/60' => $day->isSameDay($today)])>
                                    @if ($cell['login'])
                                        <span class="inline-flex items-center gap-1 font-semibold text-teal-700" title="Logged in {{ $cell['login'] }}"><span aria-hidden="true" class="size-1.5 rounded-full bg-teal"></span>{{ $cell['login'] }}</span>
                                    @elseif ($cell['type'])
                                        <span title="{{ DutySchedule::TYPES[$cell['type']] }}{{ $cell['planned'] ? '' : ' (didn\'t log in)' }}"
                                              @class(['inline-block max-w-full truncate rounded px-1.5 py-0.5 text-[0.6875rem] font-semibold', DutySchedule::LOOKS[$cell['type']]['tag'], 'border border-dashed border-coral-700/70' => ! $cell['planned']])>{{ DutySchedule::LOOKS[$cell['type']]['short'] }}</span>
                                    @elseif ($day->greaterThan($today))
                                        <span class="text-muted">Working</span>
                                    @elseif ($day->isSameDay($today))
                                        <span class="text-muted">Not in yet</span>
                                    @else
                                        <span class="text-muted" aria-label="No record">–</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="text-[0.6875rem] text-muted">
                        <th class="sticky left-0 bg-white px-3 py-2 text-left font-semibold">Per day</th>
                        @foreach ($days as $day)
                            @php($totals = $dayTotals[$day->toDateString()])
                            <td class="px-1 py-2 text-center leading-tight">
                                @if ($totals['duty'])<span class="block text-teal-700">{{ $totals['duty'] }} on duty</span>@endif
                                @if ($totals['off'])<span class="block">{{ $totals['off'] }} off</span>@endif
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        @else
            <table class="w-full border-separate border-spacing-0 text-xs">
                <thead class="sticky top-0 z-10 bg-white">
                    <tr>
                        <th class="sticky left-0 z-10 min-w-24 border-b border-line bg-white px-3 py-2 text-left font-semibold text-muted">CRA</th>
                        @foreach ($days as $day)
                            <th @class(['w-6 min-w-6 border-b border-line py-1.5 text-center font-semibold', 'bg-brand-50 text-brand-700' => $day->isSameDay($today), 'text-muted' => ! $day->isSameDay($today), 'bg-canvas/60' => $day->isWeekend() && ! $day->isSameDay($today)])>
                                <span class="block text-[0.5625rem] uppercase">{{ substr($day->format('D'), 0, 1) }}</span>{{ $day->day }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <th scope="row" class="sticky left-0 z-[1] max-w-32 truncate border-b border-line bg-white px-3 py-1.5 text-left font-medium whitespace-nowrap">{{ $row['cra']->displayName() }}</th>
                            @foreach ($days as $day)
                                @php($cell = $row['days'][$day->toDateString()])
                                <td @class(['border-b border-line p-0.5 text-center', 'bg-brand-50/60' => $day->isSameDay($today)])>
                                    @if ($cell['login'])
                                        <span class="mx-auto block size-2 rounded-full bg-teal" title="{{ $row['cra']->displayName() }}, {{ $day->format('M j') }}: logged in {{ $cell['login'] }}"></span>
                                    @elseif ($cell['type'])
                                        <span title="{{ $row['cra']->displayName() }}, {{ $day->format('M j') }}: {{ DutySchedule::TYPES[$cell['type']] }}{{ $cell['planned'] ? '' : ' (didn\'t log in)' }}"
                                              @class(['mx-auto grid size-5 place-items-center rounded text-[0.625rem] font-bold', DutySchedule::LOOKS[$cell['type']]['tag'], 'border border-dashed border-coral-700/70' => ! $cell['planned']])>{{ DutySchedule::LOOKS[$cell['type']]['short'][0] }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-line px-4 py-2 text-[0.6875rem] text-muted">
        <span class="flex items-center gap-1"><span aria-hidden="true" class="size-2 rounded-full bg-teal"></span>Logged in</span>
        @foreach (DutySchedule::TYPES as $value => $label)
            <span class="flex items-center gap-1"><span aria-hidden="true" @class(['grid size-3.5 place-items-center rounded text-[0.5625rem] font-bold', DutySchedule::LOOKS[$value]['tag']])>{{ $view === 'month' ? DutySchedule::LOOKS[$value]['short'][0] : '' }}</span>{{ DutySchedule::LOOKS[$value]['short'] }}</span>
        @endforeach
        <span class="flex items-center gap-1"><span aria-hidden="true" class="size-3.5 rounded border border-dashed border-coral-700/70"></span>Didn't log in</span>
        <a href="{{ route('cra-schedule.index', ['month' => $from->format('Y-m')]) }}" class="ml-auto font-semibold text-brand-600 hover:underline">Open CRA Schedule &rarr;</a>
    </div>
</div>
