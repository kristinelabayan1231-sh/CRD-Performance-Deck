@php
    use App\Models\DutySchedule;

    $offColors = [DutySchedule::REST => 'bg-sky/20 text-sky-800', DutySchedule::LEAVE => 'bg-coral/20 text-coral-700'];
    $names = $cras->keyBy('id');
    $dayKey = $day->toDateString();
@endphp

<x-layouts.app title="CRA Schedule">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">CRA Schedule <span class="text-sm font-normal text-muted">· days off set in My schedule, missed log-ins and who logged in on duty</span></h1>
            <nav class="flex items-center gap-2" aria-label="Month">
                <a href="{{ route('cra-schedule.index', ['month' => $month->subMonth()->format('Y-m')]) }}" aria-label="Previous month" class="grid size-9 place-items-center rounded-lg border border-line bg-white hover:border-brand-400">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                </a>
                <span class="min-w-32 text-center font-semibold">{{ $month->format('F Y') }}</span>
                <a href="{{ route('cra-schedule.index', ['month' => $month->addMonth()->format('Y-m')]) }}" aria-label="Next month" class="grid size-9 place-items-center rounded-lg border border-line bg-white hover:border-brand-400">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"/></svg>
                </a>
                @unless ($today->isSameMonth($month))
                    <a href="{{ route('cra-schedule.index') }}" class="rounded-lg px-3 py-2 text-sm font-semibold text-brand-600 hover:underline">This month</a>
                @endunless
            </nav>
        </header>

        <div class="flex flex-wrap items-start gap-4">
            {{-- The month: each day's CRAs off (dashed = missed log-in, not planned) and how many logged in --}}
            <section aria-label="{{ $month->format('F Y') }}" class="min-w-0 flex-[999_1_640px] rounded-xl bg-white p-4 shadow-sm">
                <div class="grid grid-cols-7 gap-1.5">
                    @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)
                        <span class="pb-1 text-center text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">{{ $weekday }}</span>
                    @endforeach
                    @for ($i = 0; $i < $month->dayOfWeek; $i++)
                        <span aria-hidden="true"></span>
                    @endfor
                    @for ($date = $month; $date->isSameMonth($month); $date = $date->addDay())
                        @php
                            $key = $date->toDateString();
                            $dayOff = $off[$key] ?? [];
                            $loggedIn = ($logins[$key] ?? collect())->count();
                        @endphp
                        <a href="{{ route('cra-schedule.index', ['month' => $month->format('Y-m'), 'day' => $key]) }}" @if ($key === $dayKey) aria-current="date" @endif
                           @class([
                               'flex min-h-24 flex-col gap-1 rounded-lg border p-1.5 text-left transition hover:border-brand-400',
                               'border-brand-500 ring-2 ring-brand-200' => $key === $dayKey,
                               'border-line' => $key !== $dayKey,
                               'bg-canvas/40' => $date->greaterThan($today),
                           ])>
                            <span class="flex items-center justify-between text-xs font-semibold">
                                <span @class(['grid size-5 place-items-center rounded-full', 'bg-brand-600 text-white' => $date->isSameDay($today)])>{{ $date->day }}</span>
                                @if ($loggedIn)
                                    <span class="flex items-center gap-1 text-[0.625rem] text-teal-700"><span aria-hidden="true" class="size-1.5 rounded-full bg-teal"></span>{{ $loggedIn }} on duty</span>
                                @endif
                            </span>
                            @foreach ($dayOff as $craId => $type)
                                @php($isPlanned = isset($planned[$key][$craId]))
                                <span title="{{ $names[$craId]->displayName() }}: {{ DutySchedule::TYPES[$type] }}{{ $isPlanned ? '' : ' (no log-in)' }}"
                                      @class(['truncate rounded px-1.5 py-0.5 text-[0.625rem] leading-tight font-semibold', $offColors[$type], 'border border-dashed border-coral' => ! $isPlanned])>{{ $names[$craId]->displayName() }}</span>
                            @endforeach
                        </a>
                    @endfor
                </div>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                    <span class="flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded bg-sky/20"></span>Rest Day</span>
                    <span class="flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded bg-coral/20"></span>Absent / Leave</span>
                    <span class="flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded border border-dashed border-coral bg-coral/20"></span>Absent, not planned (didn't log in)</span>
                    <span class="flex items-center gap-1.5"><span aria-hidden="true" class="size-1.5 rounded-full bg-teal"></span>CRAs logged in on duty</span>
                </div>
            </section>

            {{-- The picked day: each CRA's day, changeable with on_duty.manage --}}
            <section aria-labelledby="day-title" class="w-full overflow-hidden rounded-xl bg-white shadow-sm lg:w-80">
                <div class="border-b border-line px-4 py-3">
                    <h2 id="day-title" class="font-semibold">{{ $day->format('l, M j') }}</h2>
                    <p class="text-xs text-muted">{{ $canManage ? 'Set a CRA\'s day; logging in still counts as on duty.' : 'Each CRA\'s day.' }}</p>
                </div>
                <form method="POST" action="{{ route('cra-schedule.update') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="date" value="{{ $dayKey }}">
                    <ul class="divide-y divide-line">
                        @foreach ($dayRows as $row)
                            <li class="flex items-center gap-3 px-4 py-2.5">
                                <x-avatar :user="$row['cra']" class="size-7" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">{{ $row['cra']->displayName() }}</p>
                                    <p class="text-xs text-muted">
                                        @if ($row['logged_in_at'])
                                            <span class="text-teal-700">Logged in {{ $row['logged_in_at'] }}</span>
                                        @elseif ($row['off'])
                                            {{ DutySchedule::TYPES[$row['off']] }}{{ $row['planned'] ? '' : ' · didn\'t log in' }}
                                        @else
                                            {{ $day->greaterThan($today) ? 'On duty' : 'Not logged in' }}
                                        @endif
                                    </p>
                                </div>
                                @if ($canManage)
                                    <select name="types[{{ $row['cra']->id }}]" aria-label="{{ $row['cra']->displayName() }}'s day"
                                            class="h-9 rounded-lg border border-line bg-white px-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                        <option value="">On duty</option>
                                        @foreach (DutySchedule::TYPES as $value => $label)
                                            <option value="{{ $value }}" @selected($row['planned'] === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($canManage)
                        <div class="border-t border-line p-3">
                            <button type="submit" class="h-10 w-full rounded-lg bg-brand-600 text-sm font-semibold text-white hover:bg-brand-700">Save {{ $day->format('M j') }}</button>
                        </div>
                    @endif
                </form>
            </section>
        </div>

        {{-- Each CRA's month --}}
        <section aria-labelledby="totals-title" class="overflow-hidden rounded-xl bg-white shadow-sm">
            <h2 id="totals-title" class="border-b border-line px-4 py-3 font-semibold">{{ $month->format('F') }} per CRA</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[30rem] text-left text-sm">
                    <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">CRA</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Rest days</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Absent / Leave</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Logged in on duty</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($cras as $cra)
                            <tr>
                                <td class="px-4 py-2.5 font-medium"><span class="flex items-center gap-2"><x-avatar :user="$cra" class="size-6" />{{ $cra->displayName() }}</span></td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ $totals[$cra->id]['rest'] }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ $totals[$cra->id]['leave'] }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ $totals[$cra->id]['duty'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.app>
