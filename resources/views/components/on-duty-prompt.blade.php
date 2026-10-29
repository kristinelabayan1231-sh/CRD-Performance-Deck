@props(['open' => false])

@php
    $calendar = \App\Support\OnDuty::calendar(auth()->user());
    $today = \App\Support\WorkingDate::realToday();
    $dayOff = $calendar['days_off'][$calendar['today']] ?? null;
@endphp

{{--
    A CRA's on-duty pop-up: log in for today (left) and My schedule (right), a calendar where they set their days off
    ahead (Absent / Leave, Rest Day). Opens by itself on their first Segmentation Tracker visit of the day (not on a day
    off; "Not now" asks again on the next page), after saving the schedule, and from the tracker's My schedule button.
    The calendar is drawn by duty-schedule.js from data-schedule.
--}}
<dialog id="on-duty-dialog" aria-labelledby="on-duty-title" @if ($open) data-auto-open @endif
        class="m-auto w-[min(54rem,calc(100%-2rem))] overflow-hidden rounded-2xl p-0 shadow-2xl backdrop:bg-ink/40">
    <div class="flex flex-col md:flex-row">
        <div class="flex flex-col gap-3 border-b border-line p-6 text-center md:w-72 md:shrink-0 md:border-r md:border-b-0">
            @if ($calendar['logged_in_at'])
                <span class="mx-auto grid size-12 place-items-center rounded-full bg-teal/15 text-teal-700" aria-hidden="true">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 5 5L19 7"/></svg>
                </span>
                <h2 id="on-duty-title" class="text-lg font-bold">You're on duty today</h2>
                <p class="text-sm text-muted">Logged in at {{ $calendar['logged_in_at'] }}, {{ $today->format('D, M j') }}. Change your days off here anytime.</p>
                <button type="button" data-on-duty-close class="rounded-lg border border-line px-4 py-2 text-sm font-semibold hover:border-brand-400">Close</button>
            @else
                <span class="mx-auto grid size-12 place-items-center rounded-full bg-teal/15 text-teal-700" aria-hidden="true">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
                </span>
                <h2 id="on-duty-title" class="text-lg font-bold">{{ $dayOff ? 'Today is your '.\App\Models\DutySchedule::TYPES[$dayOff] : 'Log in for today?' }}</h2>
                <p class="text-sm text-muted">
                    @if ($dayOff)
                        You set {{ $today->format('D, M j') }} as a day off, so your goal is shared with the CRAs on duty. Working after all? Log in.
                    @else
                        Logging in puts you on today's on-duty list ({{ $today->format('D, M j') }}). Your sales goal and the team's split follow it.
                    @endif
                </p>
                <form method="POST" action="{{ route('segmentation.on-duty') }}" class="mt-1 grid gap-2">
                    @csrf
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none">{{ $dayOff ? 'Log in anyway' : "Log in · I'm on duty" }}</button>
                    <button type="button" data-on-duty-close class="rounded-lg px-4 py-2 text-sm font-semibold text-muted hover:text-ink">Not now</button>
                </form>
            @endif

            <div class="mt-auto space-y-2 border-t border-line pt-3 text-left text-sm">
                <p class="text-[0.6875rem] font-semibold tracking-wide text-muted uppercase"><span data-schedule-month-name></span> at a glance</p>
                <p class="flex justify-between"><span class="flex items-center gap-2"><span aria-hidden="true" class="size-2.5 rounded-sm bg-sky"></span>Rest days</span><strong data-schedule-count="rest">0</strong></p>
                <p class="flex justify-between"><span class="flex items-center gap-2"><span aria-hidden="true" class="size-2.5 rounded-sm bg-coral"></span>Absent / Leave</span><strong data-schedule-count="leave">0</strong></p>
                <p class="flex justify-between"><span class="flex items-center gap-2"><span aria-hidden="true" class="size-2.5 rounded-full bg-teal"></span>Logged in on duty</span><strong data-schedule-count="duty">0</strong></p>
            </div>
        </div>

        <form method="POST" action="{{ route('segmentation.schedule') }}" class="flex min-w-0 flex-1 flex-col gap-3 p-6" data-schedule
              data-calendar='@json($calendar)'>
            @csrf
            @method('PUT')
            <div class="flex flex-wrap items-center gap-2">
                <div class="min-w-0 flex-1">
                    <h3 class="font-semibold">My schedule</h3>
                    <p class="text-sm text-muted">Pick your days off ahead so your goal is shared on those days.</p>
                </div>
                <button type="button" data-schedule-prev aria-label="Previous month" class="grid size-9 place-items-center rounded-lg border border-line hover:border-brand-400 disabled:opacity-40">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                </button>
                <span data-schedule-month class="min-w-32 text-center text-sm font-semibold"></span>
                <button type="button" data-schedule-next aria-label="Next month" class="grid size-9 place-items-center rounded-lg border border-line hover:border-brand-400 disabled:opacity-40">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>

            <div class="grid grid-cols-7 gap-1.5 text-center" data-schedule-grid>
                @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)
                    <span class="pb-0.5 text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">{{ $weekday }}</span>
                @endforeach
            </div>

            @error('dates')<p role="alert" class="text-sm text-coral-700">{{ $message }}</p>@enderror
            @error('dates.*')<p role="alert" class="text-sm text-coral-700">{{ $message }}</p>@enderror

            <div class="flex flex-wrap items-center gap-2.5 rounded-xl border border-brand-100 bg-brand-50 px-3.5 py-3">
                <p class="min-w-44 flex-1 text-sm" data-schedule-selected>Pick days on the calendar</p>
                <label class="flex items-center gap-2 text-sm font-semibold">
                    Set as
                    <select name="type" class="h-10 rounded-lg border border-line bg-white px-2.5 text-sm font-normal focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                        @foreach (\App\Models\DutySchedule::TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                        <option value="clear">On duty (clear)</option>
                    </select>
                </label>
                <button type="submit" data-schedule-save disabled class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">Save</button>
            </div>

            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                <span class="flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded border border-sky bg-sky/20"></span>Rest Day</span>
                <span class="flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded border border-coral bg-coral/20"></span>Absent / Leave</span>
                <span class="flex items-center gap-1.5"><span aria-hidden="true" class="size-2 rounded-full bg-teal"></span>Logged in on duty</span>
                <span class="sm:ml-auto">Past days are locked · ask your supervisor to fix one</span>
            </div>
        </form>
    </div>
</dialog>
