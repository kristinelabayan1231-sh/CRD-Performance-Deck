<x-layouts.app title="Exclusive Lead Tracker">
    @php($standard = (int) config('exclusive_leads.daily_quota'))

    <div class="flex flex-col">
        <div class="mb-3 flex shrink-0 flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Exclusive Lead Tracker <span class="text-sm font-normal text-muted">· Pick a day on the calendar, then update the leads below.</span></h1>

            <div class="flex flex-wrap items-center gap-3 text-sm">
                @if ($cra && $canViewAll && $cras->count() > 1)
                    <form method="GET" action="{{ route('exclusive-leads.index') }}">
                        <label class="sr-only" for="exclusive-cra">CRA</label>
                        <select id="exclusive-cra" name="cra" onchange="this.form.submit()" class="h-9 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                            @foreach ($cras as $option)
                                <option value="{{ $option->id }}" @selected($option->id === $cra->id)>{{ $option->displayName() }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                @if ($canManageSettings)
                    <button type="button" onclick="document.getElementById('exclusive-settings').showModal()" aria-haspopup="dialog"
                            class="flex h-9 items-center gap-1.5 rounded-lg border border-line bg-white px-3 font-medium text-ink hover:border-brand-400 hover:text-brand-600">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>
                        Settings
                    </button>
                @endif
            </div>
        </div>

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
            @php($actual = $metrics['actual'])
            @php($targets = $metrics['targets'])
            @php($dow = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])
            @php($firstWeekday = $month->startOfMonth()->dayOfWeek)

            <div class="mb-3 grid gap-3 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                {{-- Month calendar: pick the day --}}
                <x-panel :title="$month->format('F Y').' · pick a day'" accent="brand" icon="calendar" title-id="exclusive-calendar-title" :tinted="false" body-class="">
                    <x-slot:actions>
                        <nav class="flex items-center gap-1" aria-label="Month">
                            @if ($canPrev)
                                <a href="{{ route('exclusive-leads.index', ['cra' => $cra->id, 'month' => $prevMonth]) }}" aria-label="Previous month" class="grid size-8 place-items-center rounded-lg bg-white/70 text-muted hover:bg-white hover:text-brand-600">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 6-6 6 6 6"/></svg>
                                </a>
                            @endif
                            @if ($nextMonth)
                                <a href="{{ route('exclusive-leads.index', ['cra' => $cra->id, 'month' => $nextMonth]) }}" aria-label="Next month" class="grid size-8 place-items-center rounded-lg bg-white/70 text-muted hover:bg-white hover:text-brand-600">
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
                            @php($before = $cell['quota'] === 0 && ! $future)
                            @php($selected = $cellDate->isSameDay($day))
                            @php($smallDay = $cell['quota'] > 0 && $cell['quota'] < $standard)
                            @if ($future || $before)
                                <div aria-disabled="true" @class(['flex min-h-20 flex-col gap-0.5 rounded-lg border border-dashed border-line px-2 py-1.5 text-muted', 'opacity-60' => $before])>
                                    <span class="text-sm font-bold tabular-nums">{{ $cellDate->day }}</span>
                                    <span class="text-xs tabular-nums">{{ $before ? 'Before transfer' : number_format($cell['quota']) }}</span>
                                </div>
                            @else
                                <a href="{{ route('exclusive-leads.index', ['cra' => $cra->id, 'date' => $cell['date']]) }}" @if ($selected) aria-current="date" @endif
                                   @class(['flex min-h-20 flex-col gap-0.5 rounded-lg border px-2 py-1.5 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
                                       'border-brand-500 bg-brand-50 ring-2 ring-brand-200' => $selected,
                                       'border-line bg-white hover:border-brand-400' => ! $selected])>
                                    <span class="flex items-center justify-between text-sm font-bold tabular-nums">
                                        {{ $cellDate->day }}
                                        @if ($cellDate->isSameDay($today))
                                            <span class="hidden rounded-full bg-brand-100 px-1.5 text-[0.625rem] font-semibold text-brand-700 sm:inline">today</span><span class="size-1.5 rounded-full bg-brand-600 sm:hidden" title="Today"></span>
                                        @endif
                                    </span>
                                    <span class="text-xs text-muted tabular-nums">{{ number_format($cell['listed']) }}{{ $smallDay ? ' leads' : '/'.number_format($cell['quota']) }}</span>
                                    <span class="hidden text-xs text-muted tabular-nums sm:block">{{ number_format($cell['repeat']) }} repeat</span>
                                    <span class="hidden text-xs text-muted tabular-nums sm:block">{{ number_format($cell['confirmed']) }}{{ $cell['confirmed_goal'] > 0 ? '/'.number_format($cell['confirmed_goal']).' orders' : ' '.\Illuminate\Support\Str::plural('order', $cell['confirmed']) }}</span>
                                    @if ($cell['is_day_off'])
                                        <span class="hidden text-xs font-semibold text-muted sm:block">Day off</span>
                                    @endif
                                </a>
                            @endif
                        @endforeach
                    </div>
                    <p class="border-t border-line px-4 py-2 text-xs text-muted">Each day: leads listed out of the daily quota, repeat purchases, confirmed orders out of the daily target. Dashed days have not started.
                        <a href="{{ route('segmentation.index', ['cra' => $cra->id, 'date' => $cra->exclusive_from->subDay()->toDateString()]) }}" class="font-medium text-brand-600 hover:underline">Days before the transfer are in the Segmentation Tracker.</a></p>
                </x-panel>

                {{-- Month goals --}}
                <x-panel :title="'Month goals · '.$cra->displayName()" accent="teal" icon="target" title-id="exclusive-goals-title" :tinted="false" body-class="">
                    <x-slot:badges><p class="text-xs text-muted">{{ $month->format('F') }} so far</p></x-slot:badges>
                    <div class="grid grid-cols-3 gap-2 p-4">
                        @include('exclusive-leads._ring', ['label' => 'Leads', 'value' => $actual['listed'], 'target' => $targets['leads'], 'stroke' => 'stroke-brand-500', 'sub' => $standard.' a day'])
                        @include('exclusive-leads._ring', ['label' => 'Repeat purchases', 'value' => $actual['repeat'], 'target' => $targets['repeat'], 'stroke' => 'stroke-teal-700', 'sub' => 'Goal '.$targets['repeat_percent'].'% of leads'])
                        @include('exclusive-leads._ring', ['label' => 'Confirmed orders', 'value' => $actual['confirmed'], 'target' => $targets['confirmed_orders'], 'stroke' => 'stroke-coral-700', 'sub' => number_format($targets['confirmed_orders_per_day']).' a day × '.$targets['working_days'].' working '.\Illuminate\Support\Str::plural('day', $targets['working_days'])])
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
                        <strong class="text-ink">{{ number_format($targets['leads']) }} leads a month</strong> is the target, {{ $standard }} a day. Her leads stay with her all month.
                        <br><strong class="text-ink">{{ number_format($targets['confirmed_orders_per_day']) }} confirmed orders a day</strong> is the target, {{ number_format($targets['confirmed_orders']) }} across {{ $month->format('F') }}'s working days. A day off has no target.
                        @if ($quotaNote)
                            <br><strong class="text-ink">{{ $quotaNote }}</strong>
                            Planned {{ number_format($metrics['planned']['leads']) }}, {{ number_format($metrics['planned']['short_of_target']) }} short of the target.
                        @endif
                    </div>
                </x-panel>
            </div>

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

            @php($lockAssignee = true)
            <div class="flex flex-col gap-3">
                @if ($unprocessed)
                    @include('segmentation._section', [
                        'leads' => $unprocessed, 'id' => 'unprocessed', 'title' => 'Pending',
                        'hint' => 'No status or contact date yet: pick a customer and reach out. Right-click a row to mark it catered.',
                        'empty' => $dayMetrics['listed'] === 0 && ! $day->isSameDay($today) ? 'No leads were listed this day.' : 'Nothing pending for this day.',
                    ])
                @endif
                @if ($processed)
                    @include('segmentation._section', [
                        'leads' => $processed, 'id' => 'processed', 'title' => 'Catered',
                        'hint' => 'Status or contact date set, or marked as catered. Right-click a row to unmark it.',
                        'empty' => 'No catered customers for this day yet.',
                    ])
                @endif
                {{-- Follow-ups of any month: they were on the Segmentation Tracker before the transfer --}}
                @if ($callbacks->total() > 0)
                    @include('segmentation._section', [
                        'leads' => $callbacks, 'id' => 'callback', 'title' => 'Call back for today', 'accent' => 'amber', 'icon' => 'calendar', 'sources' => null,
                        'hint' => 'Callback date is today or already passed, with no contact since.', 'empty' => 'No callbacks due today.',
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

    @if ($canManageSettings)
        @include('exclusive-leads._settings')
        <script>
            document.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
        </script>
    @endif
</x-layouts.app>
