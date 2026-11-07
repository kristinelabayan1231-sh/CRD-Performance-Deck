<x-layouts.app title="Exclusive Lead Tracker">
    <div class="flex flex-col">
        <div class="mb-3 flex shrink-0 flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Exclusive Lead Tracker <span class="text-sm font-normal text-muted">· Pick a day on the calendar, then update the leads below.</span></h1>

            <div class="flex flex-wrap items-center gap-3 text-sm">
                @if (auth()->user()->role?->slug === \App\Models\Role::CRA)
                    {{-- Opens the on-duty pop-up: log in for today, and the calendar to set days off ahead --}}
                    <button type="button" data-schedule-open class="flex h-9 items-center gap-1.5 rounded-lg border border-line bg-white px-3 font-medium text-ink hover:border-brand-400 hover:text-brand-600">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M16 3v4M8 3v4M3 10h18"/></svg>
                        My schedule
                    </button>
                @endif
                @if ($canManageSettings || $canSetLimits)
                    <button type="button" onclick="document.getElementById('exclusive-settings').showModal()" aria-haspopup="dialog"
                            class="flex h-9 items-center gap-1.5 rounded-lg border border-line bg-white px-3 font-medium text-ink hover:border-brand-400 hover:text-brand-600">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>
                        Settings
                    </button>
                @endif
            </div>
        </div>

        @if ($cra)
            {{-- Same search and filters as the Segmentation Tracker --}}
            <div class="mb-3 shrink-0">@include('segmentation._filters', ['filtersRoute' => 'exclusive-leads.index', 'oneCraAtATime' => true, 'rangeHint' => "Leads on the lists of every day in this range."])</div>
        @endif

        @if ($errors->hasAny(['effective_from', 'daily_leads', 'month_target', 'orders_per_day']))
            <p role="alert" class="mb-3 shrink-0 rounded-lg border border-coral/40 bg-coral/10 px-4 py-2 text-sm">Limits not saved. {{ $errors->first() }}</p>
        @endif

        @unless ($cra)
            <div class="rounded-2xl border border-line bg-white px-6 py-12 text-center shadow-sm">
                <p class="text-base font-semibold">{{ $canViewAll ? 'No CRA is on the Exclusive Lead Tracker yet.' : 'You are not on the Exclusive Lead Tracker.' }}</p>
                <p class="mt-1 text-sm text-muted">
                    {{ $canManageSettings ? 'Use Settings to move a CRA here from the Segmentation Tracker.' : ($canViewAll ? 'A supervisor with the right access can move a CRA here.' : 'Your leads are in the Segmentation Tracker.') }}
                </p>
                @if (! $canViewAll)
                    <a href="{{ route('segmentation.index') }}" class="mt-4 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Open Segmentation Tracker</a>
                @endif
            </div>
        @else
            @php($standard = $metrics['limits']['daily_leads'])
            @php($actual = $metrics['actual'])
            @php($targets = $metrics['targets'])
            @php($dow = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])
            @php($firstWeekday = $month->startOfMonth()->dayOfWeek)
            {{-- The calendar and month arrows keep the Type and Product picked --}}
            @php($kept = array_filter(['cra' => $cra->id, 'type' => $filters['type'], 'product' => $filters['product']]))

            @if ($search)
                <p class="mb-3 shrink-0 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm">
                    Search results for <strong>“{{ $search }}”</strong> across all days in {{ $cra->is(auth()->user()) ? 'your leads' : $cra->displayName()."'s leads" }}.
                    <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null, 'processed_page' => null]) }}" class="ml-1 font-semibold text-brand-600 hover:underline">Clear search</a>
                </p>
            @else
            <div class="mb-3 grid gap-3 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                {{-- Month calendar: pick the day --}}
                <x-panel :title="$month->format('F Y').' · pick a day'" accent="brand" icon="calendar" title-id="exclusive-calendar-title" :tinted="false" body-class="">
                    <x-slot:actions>
                        <nav class="flex items-center gap-1" aria-label="Month">
                            @if ($canPrev)
                                <a href="{{ route('exclusive-leads.index', [...$kept, 'month' => $prevMonth]) }}" aria-label="Previous month" class="grid size-8 place-items-center rounded-lg bg-white/70 text-muted hover:bg-white hover:text-brand-600">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 6-6 6 6 6"/></svg>
                                </a>
                            @endif
                            @if ($nextMonth)
                                <a href="{{ route('exclusive-leads.index', [...$kept, 'month' => $nextMonth]) }}" aria-label="Next month" class="grid size-8 place-items-center rounded-lg bg-white/70 text-muted hover:bg-white hover:text-brand-600">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>
                                </a>
                            @endif
                        </nav>
                    </x-slot:actions>
                    <div class="grid grid-cols-7 gap-1.5 p-3 sm:p-4">
                        @foreach ($dow as $name)
                            <div class="text-center text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">{{ $name }}</div>
                        @endforeach
                        @for ($blank = 0; $blank < $firstWeekday; $blank++)
                            <div aria-hidden="true"></div>
                        @endfor
                        @foreach ($metrics['days'] as $cell)
                            @php($cellDate = \Carbon\CarbonImmutable::parse($cell['date']))
                            @php($future = $cellDate->greaterThan($today))
                            @php($before = $cell['is_before_transfer'])
                            @php($selected = $cellDate->isSameDay($day))
                            @php($smallDay = $cell['quota'] > 0 && $cell['quota'] < $standard)
                            @if ($future || $before)
                                <div aria-disabled="true" @class(['flex min-h-20 flex-col gap-0.5 rounded-lg border border-dashed border-line px-2 py-1.5 text-muted', 'opacity-60' => $before])>
                                    <span class="text-sm font-bold tabular-nums">{{ $cellDate->day }}</span>
                                    <span class="text-xs tabular-nums">{{ $before ? 'Before transfer' : number_format($cell['quota']) }}</span>
                                </div>
                            @else
                                <a href="{{ route('exclusive-leads.index', [...$kept, 'date' => $cell['date']]) }}" @if ($selected) aria-current="date" @endif
                                   @class(['flex min-h-20 flex-col gap-0.5 rounded-lg border px-2 py-1.5 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
                                       'border-brand-500 bg-brand-50 ring-2 ring-brand-200' => $selected,
                                       'border-line bg-white hover:border-brand-400' => ! $selected])>
                                    <span class="flex items-center justify-between text-sm font-bold tabular-nums">
                                        {{ $cellDate->day }}
                                        @if ($cellDate->isSameDay($today))
                                            <span class="hidden rounded-full bg-brand-100 px-1.5 text-[0.625rem] font-semibold text-brand-700 sm:inline">today</span><span class="size-1.5 rounded-full bg-brand-600 sm:hidden" title="Today"></span>
                                        @endif
                                    </span>
                                    <span class="text-xs text-muted tabular-nums">{{ number_format($cell['new']) }}{{ $smallDay ? ' leads' : '/'.number_format($cell['quota']) }}</span>
                                    <span class="hidden text-xs text-muted tabular-nums sm:block">{{ number_format($cell['repeat']) }} repeat</span>
                                    <span class="hidden text-xs text-muted tabular-nums sm:block">{{ number_format($cell['confirmed']) }}{{ $cell['confirmed_goal'] > 0 ? '/'.number_format($cell['confirmed_goal']).' orders' : ' '.\Illuminate\Support\Str::plural('order', $cell['confirmed']) }}</span>
                                    @if ($cell['is_day_off'])
                                        <span class="hidden text-xs font-semibold text-muted sm:block">Day off</span>
                                    @endif
                                </a>
                            @endif
                        @endforeach
                    </div>
                    <p class="border-t border-line px-4 py-2 text-xs text-muted">Each day: new leads out of the daily quota, repeat purchases, confirmed orders out of the daily target. Dashed days have not started.
                        <a href="{{ route('segmentation.index', ['cra' => $cra->id, 'date' => $cra->exclusive_from->subDay()->toDateString()]) }}" class="font-medium text-brand-600 hover:underline">Days before the transfer are in the Segmentation Tracker.</a></p>
                </x-panel>

                {{-- Month goals --}}
                <x-panel :title="'Month goals · '.$cra->displayName()" accent="teal" icon="target" title-id="exclusive-goals-title" :tinted="false" body-class="">
                    <x-slot:badges><p class="text-xs text-muted">{{ $month->format('F') }} so far</p></x-slot:badges>
                    <div class="grid grid-cols-3 gap-2 p-4">
                        @include('exclusive-leads._ring', ['label' => 'Leads', 'value' => $actual['unique_leads'], 'target' => $targets['leads'], 'stroke' => 'stroke-brand-500', 'sub' => $standard.' a day'])
                        @include('exclusive-leads._ring', ['label' => 'Repeat purchases', 'value' => $actual['repeat'], 'target' => $targets['repeat'], 'stroke' => 'stroke-teal-700', 'sub' => 'Goal '.$targets['repeat_percent'].'% of leads'])
                        @include('exclusive-leads._ring', ['label' => 'Confirmed orders', 'value' => $actual['confirmed'], 'target' => $targets['confirmed_orders'], 'stroke' => 'stroke-coral-700', 'sub' => ($metrics['limits']['orders_rate_changed'] ? 'Across ' : number_format($targets['confirmed_orders_per_day']).' a day × ').$targets['working_days'].' working '.\Illuminate\Support\Str::plural('day', $targets['working_days'])])
                    </div>
                    <div class="grid gap-3 border-t border-line p-4 text-sm">
                        @php($aov = $actual['aov'])
                        <div>
                            <p class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-semibold">AOV</span>
                                <span class="tabular-nums"><strong class="text-lg">{{ $aov !== null ? '₱'.number_format($aov) : '—' }}</strong> <span class="text-muted">/ ₱{{ number_format($targets['high_aov_min']) }}</span></span>
                            </p>
                            <p class="text-xs {{ $aov === null ? 'text-muted' : ($aov >= $targets['high_aov_min'] ? 'text-teal-700' : 'text-coral-700') }}">
                                {{ $aov === null ? 'No confirmed orders yet.' : ($aov >= $targets['high_aov_min'] ? 'Meets the ₱'.number_format($targets['high_aov_min']).' High AOV line.' : '₱'.number_format($targets['high_aov_min'] - $aov).' below the ₱'.number_format($targets['high_aov_min']).' High AOV line.') }}
                            </p>
                        </div>
                        <div>
                            <p class="mb-1.5 font-semibold">Mix so far <span class="font-normal text-muted tabular-nums">({{ number_format(array_sum($actual['mix'])) }} listed)</span></p>
                            @include('exclusive-leads._mix', ['mix' => $actual['mix']])
                        </div>
                    </div>
                    <div class="border-t border-line bg-canvas/50 p-4 text-xs text-muted">
                        <strong class="text-ink">{{ number_format($targets['leads']) }} leads a month</strong> is the target, {{ $standard }} new leads a day until it's reached; leftovers carry on top. It can end short when too few leads qualify. Her leads stay with her all month.
                        <br><strong class="text-ink">{{ number_format($targets['confirmed_orders_per_day']) }} confirmed orders a day</strong> is the target, {{ number_format($targets['confirmed_orders']) }} across {{ $month->format('F') }}'s working days. A day off has no target.
                        @if ($quotaNote)
                            <br><strong class="text-ink">{{ $quotaNote }}</strong>
                            Planned {{ number_format($metrics['planned']['leads']) }}, {{ number_format($metrics['planned']['short_of_target']) }} short of the target.
                        @endif
                    </div>
                </x-panel>
            </div>

            {{-- This day or the whole month's list --}}
            <nav aria-label="Leads shown" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('exclusive-leads.index', [...$kept, 'date' => $day->toDateString()]) }}" @if (! $wholeMonth && ! $range) aria-current="page" @endif
                   @class(['flex h-9 items-center rounded-lg border px-3 font-semibold', 'border-brand-500 bg-brand-50 text-brand-700' => ! $wholeMonth && ! $range, 'border-line bg-white hover:border-brand-400 hover:text-brand-600' => $wholeMonth || $range])>{{ $day->format('M j') }} only</a>
                <a href="{{ route('exclusive-leads.index', [...$kept, 'date' => $day->toDateString(), 'view' => 'month']) }}" @if ($wholeMonth) aria-current="page" @endif
                   @class(['flex h-9 items-center rounded-lg border px-3 font-semibold', 'border-brand-500 bg-brand-50 text-brand-700' => $wholeMonth, 'border-line bg-white hover:border-brand-400 hover:text-brand-600' => ! $wholeMonth])>Whole month</a>
            </nav>

            @if ($range)
                <p class="mb-3 shrink-0 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm">
                    <strong>{{ number_format(count($listedDays)) }} different {{ \Illuminate\Support\Str::plural('lead', count($listedDays)) }}</strong> on {{ $cra->is(auth()->user()) ? 'your' : $cra->displayName()."'s" }} lists {{ \App\Http\Controllers\SegmentationController::periodLabel(...$range) }}, each listed once below.
                    <a href="{{ request()->fullUrlWithQuery(['from' => null, 'to' => null, 'page' => null, 'processed_page' => null]) }}" class="ml-1 font-semibold text-brand-600 hover:underline">Clear range</a>
                </p>
            @elseif ($wholeMonth)
                <p class="mb-3 shrink-0 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm">
                    <strong>{{ number_format($actual['unique_leads']) }} different {{ \Illuminate\Support\Str::plural('lead', $actual['unique_leads']) }}</strong> on {{ $cra->is(auth()->user()) ? 'your' : $cra->displayName()."'s" }} {{ $month->format('F') }} lists so far, plus <strong>{{ number_format($upcomingCount) }} upcoming</strong> held for the days ahead, out of the {{ number_format($targets['leads']) }} target. Upcoming leads can't be worked until they are handed out on their day.
                </p>
            @else
            {{-- The selected day --}}
            @php($quota = $dayMetrics['quota'])
            @php($dayAov = $dayMetrics['aov'])
            <x-panel :title="$day->format('l, M j').' · '.number_format($dayMetrics['listed']).' '.\Illuminate\Support\Str::plural('lead', $dayMetrics['listed']).($leadDay ? ' from '.$leadDay->format('M j') : '')" accent="sky" icon="chart" title-id="exclusive-day-title" :tinted="false" body-class="" class="mb-3">
                <x-slot:badges>
                    @if ($dayMetrics['is_history'])
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-muted shadow-sm">History · leads she already had</span>
                    @endif
                </x-slot:badges>
                <x-slot:actions>
                    <span class="text-xs text-muted">Daily goal: {{ number_format($dayMetrics['repeat_goal']) }} repeat purchases ({{ $targets['repeat_percent'] }}% of {{ number_format($quota) }}) · {{ $dayMetrics['is_day_off'] ? 'Day off, no order target' : number_format($dayMetrics['confirmed_goal']).' confirmed orders' }} · AOV ₱{{ number_format($targets['high_aov_min']) }}</span>
                </x-slot:actions>
                <div class="grid grid-cols-2 gap-3 p-4 lg:grid-cols-4">
                    @foreach ([
                        ['Repeat purchases', number_format($dayMetrics['repeat']), '/ '.number_format($dayMetrics['repeat_goal']), $targets['repeat_percent'].'% of the day\'s '.number_format($quota)],
                        ['Confirmed orders', number_format($dayMetrics['confirmed']), $dayMetrics['confirmed_goal'] > 0 ? '/ '.number_format($dayMetrics['confirmed_goal']).($day->isSameDay($today) ? ' today' : '') : null, ($dayMetrics['is_day_off'] ? 'Day off, no target · ' : '').'Month: '.number_format($actual['confirmed']).' of '.number_format($targets['confirmed_orders'])],
                        ['AOV', $dayAov !== null ? '₱'.number_format($dayAov) : '—', null, $dayAov === null ? 'No orders yet' : ($dayAov >= $targets['high_aov_min'] ? 'Meets ₱'.number_format($targets['high_aov_min']) : '₱'.number_format($targets['high_aov_min'] - $dayAov).' below ₱'.number_format($targets['high_aov_min']))],
                        ['Leads worked', number_format($dayMetrics['worked']), '/ '.number_format($dayMetrics['listed']), $dayMetrics['left'] > 0 ? number_format($dayMetrics['left']).' left'.($day->isSameDay($today) ? ' for tomorrow' : '') : ($dayMetrics['listed'] > 0 ? 'All handled' : 'Nothing listed')],
                    ] as [$label, $value, $of, $note])
                        <div class="rounded-xl border border-line bg-white px-4 py-3">
                            <p class="text-xs font-semibold text-muted">{{ $label }}</p>
                            <p class="text-2xl font-bold tabular-nums">{{ $value }} @if ($of)<small class="text-sm font-semibold text-muted">{{ $of }}</small>@endif</p>
                            <p class="text-xs text-muted">{{ $note }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-line px-4 py-3">
                    <p class="mb-1.5 text-xs font-semibold text-muted">Daily mix by priority</p>
                    @include('exclusive-leads._mix', ['mix' => $dayMetrics['mix']])
                    @if ($dayMetrics['pos'] > 0)
                        <p class="mt-1.5 text-xs text-muted">Plus {{ number_format($dayMetrics['pos']) }} of her own POS {{ \Illuminate\Support\Str::plural('sale', $dayMetrics['pos']) }}, not counted in the quota.</p>
                    @endif
                </div>
            </x-panel>
            @endif
            @endif

            @php($lockAssignee = true)
            <div class="flex flex-col gap-3">
                @if ($unprocessed)
                    @include('segmentation._section', [
                        'leads' => $unprocessed, 'id' => 'unprocessed', 'title' => 'Pending',
                        'hint' => 'No status or contact date yet: pick a customer and reach out. Right-click a row to mark it catered.',
                        'empty' => $search ? 'No pending leads match this search.' : ($filters['type'] || $filters['product'] ? 'Nothing pending for this filter.' : ($range ? 'Nothing pending on these days\' lists.' : ($wholeMonth ? 'Nothing pending on this month\'s lists.' : ($dayMetrics['listed'] === 0 && ! $day->isSameDay($today) ? 'No leads were listed this day.' : 'Nothing pending for this day.')))),
                    ])
                @endif
                @if ($processed)
                    @include('segmentation._section', [
                        'leads' => $processed, 'id' => 'processed', 'title' => 'Catered',
                        'hint' => 'Status or contact date set, or marked as catered. Right-click a row to unmark it.',
                        'empty' => $search ? 'No catered leads match this search.' : ($filters['type'] || $filters['product'] ? 'No catered customers for this filter yet.' : ($range ? 'No catered customers on these days\' lists yet.' : ($wholeMonth ? 'No catered customers on this month\'s lists yet.' : 'No catered customers for this day yet.'))),
                    ])
                @endif
            </div>
        @endunless
    </div>

    @if ($cra)
        <dialog id="expand-dialog" aria-labelledby="expand-title" class="m-auto h-[90dvh] w-[min(96rem,calc(100%-2rem))] overflow-hidden rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
            <div class="flex h-full flex-col">
                <div class="flex shrink-0 items-center justify-between gap-4 border-b border-line px-5 py-3">
                    <h2 id="expand-title" class="text-base font-semibold" data-expand-title></h2>
                    <button type="button" data-expand-close aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <iframe title="Full list" class="min-h-0 w-full flex-1 border-0" src="about:blank"></iframe>
            </div>
        </dialog>
        @include('segmentation._spiels-panel')
        @include('segmentation._note-dialog')
        @include('segmentation._recording-dialog')
    @endif

    @if ($canManageSettings || $canSetLimits)
        @include('exclusive-leads._settings')
        <script>
            document.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
        </script>
    @endif
</x-layouts.app>
