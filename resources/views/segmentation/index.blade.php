<x-layouts.app title="Segmentation Tracker">
    @php($quota = config('segmentation.leads_per_cra'))

    <div class="flex flex-col">
        <div class="mb-2 flex shrink-0 flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Segmentation Tracker <span class="text-sm font-normal text-muted">· Customers whose product runs out on the lead day.</span></h1>

            <div class="flex items-center gap-3 text-sm text-muted">
                <span class="flex items-center gap-1.5">
                    <span aria-hidden="true" class="size-2 rounded-full {{ $syncError ? 'bg-coral' : 'bg-teal' }}"></span>
                    @if ($lastSync)
                        Auto-synced {{ $lastSync->diffForHumans() }}
                    @else
                        Syncs automatically every hour
                    @endif
                </span>
                @if ($canManage)
                    <form method="POST" action="{{ route('segmentation.sync') }}">
                        @csrf
                        <input type="hidden" name="date" value="{{ $filters['date'] ?? $today->toDateString() }}">
                        <button type="submit" class="h-9 rounded-lg border border-line bg-white px-3 font-medium text-ink hover:border-brand-400 hover:text-brand-600"
                                onclick="this.disabled=true; this.textContent='Syncing…'; this.form.submit()">
                            Sync now
                        </button>
                    </form>
                @endif
            </div>
        </div>

        @include('segmentation._tabs', ['toolbar' => 'segmentation._filters'])

        @if ($syncError)
            <div role="alert" class="mb-3 shrink-0 rounded-lg border border-coral/60 bg-coral/10 px-4 py-2 text-sm text-coral-700">
                Automatic sync couldn't reach the retention API ({{ $syncError }}). Showing the last synced leads.
            </div>
        @elseif (($lastSyncResult['source'] ?? null) === 'fallback')
            <div role="status" class="mb-3 shrink-0 rounded-lg border border-[#c9970e]/50 bg-[#ffe5a0]/40 px-4 py-2 text-sm text-[#473821]">
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

        {{-- Summary tiles (refreshed live from segmentation.summary) --}}
        @php($tileStyles = [
            'total' => ['Leads', 'from-brand-600 to-brand-700'],
            'crd' => ['CRD Leads', 'from-[#0e8f7c] to-[#0b7d6c]'],
            'fsd' => ['FSD Leads', 'from-[#1f8fb8] to-[#1a7fa6]'],
            'per_cra' => ['Per CRA', 'from-[#e05a5f] to-[#d1494e]'],
            'updated' => ['Status Updated', 'from-[#7429d6] to-brand-600'],
            'converted' => ['Conversion', 'from-[#11734b] to-[#0e8f7c]'],
        ])
        <div data-live-summary data-url="{{ route('segmentation.summary', request()->query()) }}"
             @class(['mb-3 grid shrink-0 gap-3 sm:grid-cols-2 lg:grid-cols-3', 'xl:grid-cols-6' => count($tiles) === 6, 'xl:grid-cols-5' => count($tiles) === 5])>
            @foreach ($tiles as $key => $tile)
                @php($pressable = $key === 'per_cra')
                <{{ $pressable ? 'button' : 'div' }} @if ($pressable) type="button" data-per-cra-open aria-haspopup="dialog" @endif
                    class="relative overflow-hidden rounded-xl bg-gradient-to-br {{ $tileStyles[$key][1] }} px-4 py-2 text-left text-white shadow-sm {{ $pressable ? 'cursor-pointer transition hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral' : '' }}">
                    <span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/15"></span>
                    <span class="relative flex items-center justify-between text-xs font-medium">
                        {{ $tileStyles[$key][0] }}
                        @if ($pressable)
                            <svg class="size-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>
                        @endif
                    </span>
                    <span class="relative block text-xl font-bold tabular-nums" data-tile-value="{{ $key }}">{{ $tile['value'] }}</span>
                    <span class="relative block min-h-4 text-[11px] font-medium text-white/90" data-tile-note="{{ $key }}">{{ $tile['note'] }}</span>
                </{{ $pressable ? 'button' : 'div' }}>
            @endforeach
        </div>

        @if ($canViewAll && $cras->isEmpty())
            <p class="mb-3 shrink-0 rounded-lg border border-sky/50 bg-sky/10 px-4 py-2 text-sm text-sky-900">
                No CRAs yet. Give users the <strong>CRA</strong> role in User Access and leads will be assigned to them ({{ $quota }} each per day, CRD Leads first).
            </p>
        @endif

        @if ($filters['q'])
            <p class="mb-3 shrink-0 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2 text-sm">
                Search results for <strong>“{{ $filters['q'] }}”</strong> across all lead days{{ $canViewAll ? '' : ' in your leads' }}.
                <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null, 'processed_page' => null]) }}" class="ml-1 font-semibold text-brand-600 hover:underline">Clear search</a>
            </p>
        @endif

        {{-- The selected day's leads: what's left to do first, what's done below. --}}
        <div class="flex flex-col gap-3">
            @if ($unprocessed)
                @include('segmentation._section', [
                    'leads' => $unprocessed, 'id' => 'unprocessed', 'title' => 'Pending', 'dot' => 'bg-coral',
                    'hint' => 'No status or contact date yet: pick a customer and reach out. Right-click a row to mark it catered.',
                    'empty' => $canManage ? 'Nothing pending for this filter. Past days only have leads if they were synced then: use Sync now to fill one in.' : 'Nothing pending for this filter.',
                ])
            @endif
            @if ($processed)
                @include('segmentation._section', [
                    'leads' => $processed, 'id' => 'processed', 'title' => 'Catered', 'dot' => 'bg-teal',
                    'hint' => 'Status or contact date set, or marked as catered. Right-click a row to unmark it.',
                    'empty' => 'No catered customers for this filter yet.',
                ])
            @endif
        </div>
    </div>

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
            <div class="bg-gradient-to-br from-brand-600 to-brand-700 px-6 pt-6 pb-5 text-white">
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
</x-layouts.app>
