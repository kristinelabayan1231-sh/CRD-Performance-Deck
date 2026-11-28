<x-layouts.app title="Segmentation Tracker">
    @php $quota = config('segmentation.leads_per_cra'); @endphp
    <div class="flex flex-col">
        {{-- One row: the title, the tracker's tabs, then the working date, My schedule and Sync --}}
        <div class="mb-3 flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2">
            <h1 class="text-xl font-semibold" title="Customers whose product runs out on the lead day.">Segmentation Tracker</h1>
            @include('segmentation._tabs', ['tabsOnly' => true])

            <div class="ml-auto flex items-center gap-1.5">
                @php
                    $workingDate = \App\Support\WorkingDate::get();
                    $realToday = \App\Support\WorkingDate::realToday();
                    $dayTip = $workingDate
                        ? 'Working date: '.$workingDate->format('M j, Y').' · The Segmentation Tracker shows this lead day as today (real date '.$realToday->format('M j').'). Sales and conversion follow the real date.'
                        : 'Today';
                    $dayPill = 'inline-flex h-10 items-center gap-1.5 rounded-full bg-brand-100 px-3.5 text-[0.8125rem] font-bold whitespace-nowrap text-ink';
                    $roundButton = 'relative grid size-10 place-items-center rounded-full border border-line bg-white text-ink transition hover:border-ink';
                @endphp
                @if ($workingDate && auth()->user()->can('sales_goals.manage'))
                    <a href="{{ route('settings.working-date.index') }}" title="{{ $dayTip }} Change it in Settings → Working Date." class="{{ $dayPill }} hover:bg-brand-200">
                @else
                    <span title="{{ $dayTip }}" class="{{ $dayPill }} cursor-help">
                @endif
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M16 3v4M8 3v4M3 10h18"/></svg>
                    {{ ($workingDate ? $today : $realToday)->format('M j') }}
                    @if ($workingDate)
                        <small class="text-[0.6875rem] font-medium text-ink/70">real {{ $realToday->format('M j') }}</small>
                    @endif
                @if ($workingDate && auth()->user()->can('sales_goals.manage'))
                    </a>
                @else
                    </span>
                @endif

                @if (auth()->user()->role?->slug === \App\Models\Role::CRA)
                    {{-- Opens the on-duty pop-up's calendar to set days off ahead --}}
                    <button type="button" data-schedule-open title="My schedule" aria-label="My schedule" class="{{ $roundButton }}">
                        <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M16 3v4M8 3v4M3 10h18M9 15l2 2 4-4"/></svg>
                    </button>
                @endif

                {{-- Sync: the dot is teal when the last automatic sync worked, coral when it couldn't reach the API --}}
                @php $syncTip = $lastSync ? 'Auto-synced '.$lastSync->diffForHumans() : 'Syncs automatically every hour'; @endphp
                @php $syncIcon = '<svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 11a8 8 0 0 0-14.8-4M4 4v4h4M4 13a8 8 0 0 0 14.8 4M20 20v-4h-4"/></svg>'; @endphp
                @php $syncDot = '<i aria-hidden="true" class="absolute right-0.5 bottom-0.5 size-2.5 rounded-full border-2 border-white '.($syncError ? 'bg-coral' : 'bg-teal').'"></i>'; @endphp
                @if ($canManage)
                    <form method="POST" action="{{ route('segmentation.sync') }}">
                        @csrf
                        <input type="hidden" name="date" value="{{ $filters['date'] ?? $today->toDateString() }}">
                        <button type="submit" title="Sync now · {{ $syncTip }}" aria-label="Sync now, {{ $syncTip }}" class="{{ $roundButton }} disabled:opacity-60"
                                onclick="this.disabled=true; this.classList.add('animate-spin'); this.form.submit()">{!! $syncIcon !!}{!! $syncDot !!}</button>
                    </form>
                @else
                    <span role="img" title="{{ $syncTip }}" aria-label="{{ $syncTip }}" class="{{ $roundButton }} text-muted hover:border-line">{!! $syncIcon !!}{!! $syncDot !!}</span>
                @endif
            </div>
        </div>

        {{-- Search and filters on their own bar --}}
        <div class="mb-3 shrink-0 rounded-2xl border border-line bg-white p-2">@include('segmentation._filter-bar')</div>

        @if ($syncError)
            <div role="alert" class="mb-3 shrink-0 rounded-lg border border-coral/60 bg-coral/10 px-4 py-2 text-sm text-coral-700">
                Automatic sync couldn't reach the retention API ({{ $syncError }}). Showing the last synced leads.
            </div>
        @elseif (($lastSyncResult['source'] ?? null) === 'fallback')
            <div role="status" class="mb-3 shrink-0 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm text-ink">
                <strong>Backup mode:</strong> the retention API is down, so this day's new leads were worked out from saved delivered orders (retention API + Pancake)
                and Settings → Product Consumption days. Leads that already existed were left as they are.
            </div>
        @endif

        @error('transfer')
            <div role="alert" class="mb-3 shrink-0 rounded-lg border border-coral/60 bg-coral/10 px-4 py-2 text-sm text-coral-700">{{ $message }}</div>
        @enderror

        @error('sync')
            <div role="alert" class="mb-3 shrink-0 rounded-lg border border-coral/60 bg-coral/10 px-4 py-2 text-sm text-coral-700">{{ $message }}</div>
        @enderror

        {{-- Summary (refreshed live from segmentation.summary): one strip of figures --}}
        @php $tileStyles = [
            'total' => ['Leads', 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6M22 19v-1a4 4 0 0 0-3-3.9M16 4.1a3 3 0 0 1 0 5.8'],
            'crd' => ['CRD Leads', 'M12 3l2.7 5.6 6.1.8-4.5 4.2 1.1 6.1L12 16.8l-5.4 2.9 1.1-6.1-4.5-4.2 6.1-.8z'],
            'fsd' => ['FSD Leads', 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18M12 8v8M8 12h8'],
            'per_cra' => ['Per CRA', 'M12 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8M4.5 20c1-4 4-5.5 7.5-5.5s6.5 1.5 7.5 5.5'],
            'updated' => ['Status Updated', 'm5 13 4 4L19 7'],
            'converted' => ['Conversion', 'M3 17l6-6 4 4 8-8M15 7h6v6'],
        ]; @endphp
        <div data-live-summary data-url="{{ route('segmentation.summary', request()->query()) }}"
             class="mb-3 grid shrink-0 divide-line rounded-2xl border border-line bg-white sm:grid-cols-3 sm:divide-x xl:flex xl:divide-x">
            @foreach ($tiles as $key => $tile)
                @php $pressable = $key === 'per_cra'; @endphp
                <{{ $pressable ? 'button' : 'div' }} @if ($pressable) type="button" data-per-cra-open aria-haspopup="dialog" title="Assigned per CRA" @endif
                    class="flex min-w-0 flex-1 items-center gap-3 px-4 py-3 text-left {{ $pressable ? 'cursor-pointer rounded-2xl transition hover:bg-canvas' : '' }}">
                    <span aria-hidden="true" class="grid size-10 shrink-0 place-items-center rounded-full border border-line text-ink">
                        <svg class="size-[1.15rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $tileStyles[$key][1] }}"/></svg>
                    </span>
                    <span class="font-display text-2xl leading-none font-bold tabular-nums" data-tile-value="{{ $key }}">{{ $tile['value'] }}</span>
                    <span class="min-w-0 leading-tight">
                        <span class="flex items-center gap-1 text-sm font-semibold">{{ $tileStyles[$key][0] }}@if ($pressable)<svg class="size-3.5 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>@endif</span>
                        <span class="block truncate text-xs text-muted" data-tile-note="{{ $key }}">{{ $tile['note'] }}</span>
                    </span>
                </{{ $pressable ? 'button' : 'div' }}>
            @endforeach
        </div>

        {{-- The CRA's texts today: the same sending as Today's texts --}}
        @php $sendBar = \App\Support\SmsSendBar::for(auth()->user(), ctype_digit((string) $filters['cra']) ? $cras->firstWhere('id', (int) $filters['cra']) : null); @endphp
        @if ($sendBar)
            @include('segmentation._send-bar')
        @elseif (! ctype_digit((string) $filters['cra']) && auth()->user()->can('sms_reminders.view_all'))
            {{-- Every CRA: the team's texts today; sending is per CRA --}}
            @php $teamCounts = app(\App\Services\RestockReminders::class)->dayCounts(\App\Support\WorkingDate::realToday(), null); @endphp
            <section aria-label="Texts today" class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-white px-3 py-2.5">
                <dl class="flex flex-wrap items-center gap-1.5">
                    @foreach (['To text' => $teamCounts['toSend'], 'Sent' => $teamCounts['sent'], 'Unticked' => $teamCounts['excluded'], 'Failed' => $teamCounts['failed']] as $countLabel => $count)
                        <div class="flex h-10 items-center gap-1.5 rounded-full border border-[#d9d9d6] bg-white px-3.5 text-[0.8125rem] font-medium tabular-nums">
                            <dt>{{ $countLabel }}</dt><dd class="font-display text-[0.9375rem] font-bold">{{ number_format($count) }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="text-sm text-muted">Pick a CRA to send their texts, tick them and see their conversations.</p>
            </section>
        @endif

        @if ($canViewAll && $cras->isEmpty())
            <p class="mb-3 shrink-0 rounded-lg border border-sky/50 bg-sky/10 px-4 py-2 text-sm text-sky-900">
                No CRAs yet. Give users the <strong>CRA</strong> role in User Access and leads will be assigned to them ({{ $quota }} each per day, CRD Leads first).
            </p>
        @endif

        @if ($filters['q'])
            <p class="mb-3 shrink-0 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm">
                Search results for <strong>“{{ $filters['q'] }}”</strong> across all lead days{{ match (true) { $filters['cra'] === (string) auth()->id() => ' in your leads', ctype_digit($filters['cra']) => ' in '.($cras->firstWhere('id', (int) $filters['cra'])?->displayName() ?? 'this CRA')."'s leads", default => '' } }}.
                <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null, 'processed_page' => null]) }}" class="ml-1 font-semibold text-brand-600 hover:underline">Clear search</a>
            </p>
        @endif

        @if (($filters['repeat'] ?? null) && ! $filters['q'])
            <p class="mb-3 shrink-0 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm">
                Repeat Purchase = <strong>Yes</strong> bought {{ ($singleDay ? 'on ' : (! empty($filters['from']) ? 'from ' : 'in ')).$period }}, whatever their lead day{{ ! empty($filters['type']) ? ' · '.\App\Models\Lead::TYPES[$filters['type']].'s ('.($filters['type'] === \App\Models\Lead::TYPE_FSD ? 'Retained' : 'Repeat Customers').')' : '' }}.
                <a href="{{ request()->fullUrlWithQuery(['repeat' => null, 'page' => null, 'processed_page' => null]) }}" class="ml-1 font-semibold text-brand-600 hover:underline">Show all leads</a>
            </p>
        @endif

        {{-- The selected day's leads: what's left to do first, what's done below. --}}
        @php $switch = $unprocessed && $processed ? ['unprocessed' => $unprocessed->total(), 'processed' => $processed->total()] : null; @endphp
        {{-- The list, with the customer panel beside it once a customer is open (wide screens) --}}
        <div class="flex flex-col gap-3 lg:grid lg:items-start lg:has-[>[data-spiels-panel]:not([hidden])]:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="flex min-w-0 flex-col gap-3">
            @if ($unprocessed)
                @include('segmentation._section', [
                    'leads' => $unprocessed, 'id' => 'unprocessed', 'title' => 'Pending',
                    'hint' => 'No status or contact date yet: pick a customer and reach out. Right-click a row to mark it catered.',
                    'empty' => $canManage ? 'Nothing pending for this filter. Past days only have leads if they were synced then: use Sync now to fill one in.' : 'Nothing pending for this filter.',
                ])
            @endif
            @if ($processed)
                @include('segmentation._section', [
                    'leads' => $processed, 'id' => 'processed', 'title' => 'Catered',
                    'hint' => 'Status or contact date set, or marked as catered. Right-click a row to unmark it.',
                    'empty' => 'No catered customers for this filter yet.',
                ])
            @endif
        </div>
        @include('segmentation._spiels-panel', ['docked' => true])
        </div>
    </div>

    {{-- Messages: opens the first customer on the page with a reply to answer (spiels.js); hidden while the panel is open --}}
    <button type="button" data-panel-peek hidden aria-label="Open the replies to answer" title="Replies to answer"
            class="fixed right-4 bottom-20 z-30 grid size-14 place-items-center rounded-full bg-brand-600 text-white shadow-lg transition hover:scale-105">
        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linejoin="round" d="M4 5h16v11H9l-5 4z"/></svg>
        <em data-panel-peek-count class="absolute -top-1 -right-1 min-w-5.5 rounded-full bg-coral-700 px-1.5 text-xs leading-5.5 font-bold not-italic tabular-nums"></em>
    </button>

    @if ($canTransfer)
        @include('segmentation._transfer-dialog')
    @endif

    {{-- Per CRA breakdown (opened from the Per CRA tile, refreshed with the tiles) --}}
    @isset($tiles['per_cra'])
        <dialog id="per-cra-dialog" aria-labelledby="per-cra-title" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
            <div class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
                <div>
                    <h2 id="per-cra-title" class="text-base font-semibold">Assigned per CRA</h2>
                    <p class="text-sm text-muted" data-per-cra-period>{{ $tiles['per_cra']['period'] }}</p>
                </div>
                <button type="button" data-per-cra-close aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <ul class="max-h-[60vh] divide-y divide-line overflow-y-auto" data-per-cra-list>
                @forelse ($tiles['per_cra']['breakdown'] as $row)
                    <li @class(['flex items-center justify-between gap-4 px-5 py-3', 'bg-coral/10' => $row['odd']])>
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $row['name'] }}</p>
                            <p class="text-xs text-muted">{{ $row['crd'] }} CRD · {{ $row['fsd'] }} FSD</p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($row['odd'])
                                <span class="rounded-full bg-coral/20 px-2 py-0.5 text-xs font-semibold text-coral-700">Uneven</span>
                            @endif
                            <span class="text-2xl font-bold tabular-nums">{{ $row['total'] }}</span>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-sm text-muted">No CRAs yet.</li>
                @endforelse
            </ul>
            <div class="flex items-center justify-between border-t border-line bg-canvas/40 px-5 py-3 text-sm">
                <span class="text-muted">Total assigned</span>
                <span class="font-semibold tabular-nums" data-per-cra-total>{{ collect($tiles['per_cra']['breakdown'])->sum('total') }}</span>
            </div>
        </dialog>
    @endisset


    {{-- Full view of one list (opened from a section's expand arrows) --}}
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

    {{-- A CRA's hello on their first visit of the day --}}
    @if ($greeting)
        <dialog id="greeting-dialog" data-open-on-load aria-labelledby="greeting-title" class="m-auto w-[min(24rem,calc(100%-2rem))] overflow-hidden rounded-2xl p-0 shadow-2xl backdrop:bg-ink/40">
            <div class="bg-brand-600 px-6 pt-6 pb-5 text-white">
                <p class="text-sm font-medium text-white/80">{{ $greeting['day']->format('l, M j') }} leads</p>
                <h2 id="greeting-title" class="mt-1 text-2xl font-bold">Hey {{ $greeting['name'] }}!</h2>
            </div>
            <div class="space-y-3 px-6 py-5">
                <p class="text-base">You have <strong class="tabular-nums">{{ $greeting['total'] }}</strong> {{ Str::plural('lead', $greeting['total']) }} for today.</p>
                @if ($greeting['total'] > 0)
                    <p class="flex items-center gap-2 text-base">
                        <span aria-hidden="true" class="size-2.5 rounded-full {{ $greeting['unprocessed'] ? 'bg-coral' : 'bg-teal' }}"></span>
                        @if ($greeting['unprocessed'])
                            <span><strong class="tabular-nums">{{ $greeting['unprocessed'] }}</strong> pending</span>
                        @else
                            <span>All catered. Nice work!</span>
                        @endif
                    </p>
                @endif
                <div class="flex justify-end gap-2 pt-2">
                    @if ($greeting['unprocessed'])
                        <a href="{{ route('segmentation.index', ['show' => 'unprocessed']) }}" class="rounded-lg border border-line px-4 py-2 text-sm font-semibold hover:border-brand-400 hover:text-brand-600">Show pending</a>
                    @endif
                    <button type="button" data-greeting-close class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Let's go</button>
                </div>
            </div>
        </dialog>
    @endif

    @include('segmentation._note-dialog')
    @include('segmentation._recording-dialog')
</x-layouts.app>
