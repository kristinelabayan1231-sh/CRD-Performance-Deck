<x-layouts.app title="Segmentation Tracker">
    @php($control = 'h-10 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')
    @php($quota = config('segmentation.leads_per_cra'))

    <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
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

    @include('segmentation._tabs')

    @if ($syncError)
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">
            Automatic sync couldn't reach the retention API ({{ $syncError }}). Showing the last synced leads.
        </div>
    @elseif (($lastSyncResult['source'] ?? null) === 'fallback')
        <div role="status" class="mb-4 rounded-lg border border-[#c9970e]/50 bg-[#ffe5a0]/40 px-4 py-3 text-sm text-[#473821]">
            <strong>Backup mode:</strong> the retention API is down, so this day's new leads were worked out from saved delivered orders (retention API + Pancake)
            and Settings → Product Consumption days. Leads that already existed were left as they are.
        </div>
    @endif

    @error('transfer')
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $message }}</div>
    @enderror

    @error('sync')
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $message }}</div>
    @enderror

    {{-- Filters --}}
    <form method="GET" action="{{ route('segmentation.index') }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm">
        <label>
            <span class="mb-1 block text-xs font-medium text-muted">Month</span>
            <input type="month" name="month" value="{{ $filters['month'] }}" class="{{ $control }}"
                   onchange="this.form.date.value=''; this.form.submit()">
        </label>
        <label>
            <span class="mb-1 block text-xs font-medium text-muted">Date</span>
            <input type="date" name="date" value="{{ $filters['date'] ?? '' }}" class="{{ $control }}" onchange="this.form.submit()">
        </label>
        @if ($canViewAll)
            <label>
                <span class="mb-1 block text-xs font-medium text-muted">CRA</span>
                <select name="cra" class="{{ $control }}" onchange="this.form.submit()">
                    <option value="all" @selected($filters['cra'] === 'all')>All</option>
                    @foreach ($cras as $cra)
                        <option value="{{ $cra->id }}" @selected($filters['cra'] === (string) $cra->id)>{{ $cra->displayName() }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label>
            <span class="mb-1 block text-xs font-medium text-muted">Type</span>
            <select name="type" class="{{ $control }}" onchange="this.form.submit()">
                <option value="">All types</option>
                @foreach (\App\Models\Lead::TYPES as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span class="mb-1 block text-xs font-medium text-muted">Status</span>
            <select name="status" class="{{ $control }}" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <option value="none" @selected(($filters['status'] ?? '') === 'none')>No status yet</option>
                @foreach ($statuses as $value => [$label])
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex gap-2">
            <noscript><button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">Apply</button></noscript>
            <a href="{{ route('segmentation.index') }}" class="grid h-10 place-items-center rounded-lg border border-line px-4 text-sm font-medium hover:border-brand-400 hover:text-brand-600">Today</a>
        </div>
    </form>

    {{-- Summary --}}
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
         @class(['mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3', 'xl:grid-cols-6' => count($tiles) === 6, 'xl:grid-cols-5' => count($tiles) === 5])>
        @foreach ($tiles as $key => $tile)
            @php($pressable = $key === 'per_cra')
            <{{ $pressable ? 'button' : 'div' }} @if ($pressable) type="button" data-per-cra-open aria-haspopup="dialog" @endif
                class="relative overflow-hidden rounded-xl bg-gradient-to-br {{ $tileStyles[$key][1] }} px-4 py-3 text-left text-white shadow-sm {{ $pressable ? 'cursor-pointer transition hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral' : '' }}">
                <span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/15"></span>
                <span class="relative flex items-center justify-between text-xs font-medium">
                    {{ $tileStyles[$key][0] }}
                    @if ($pressable)
                        <svg class="size-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>
                    @endif
                </span>
                <span class="relative mt-0.5 block text-2xl font-bold tabular-nums" data-tile-value="{{ $key }}">{{ $tile['value'] }}</span>
                <span class="relative block min-h-4 text-[11px] font-medium text-white/90" data-tile-note="{{ $key }}">{{ $tile['note'] }}</span>
            </{{ $pressable ? 'button' : 'div' }}>
        @endforeach
    </div>

    @if ($canViewAll && $cras->isEmpty())
        <p class="mb-4 rounded-lg border border-sky/50 bg-sky/10 px-4 py-3 text-sm text-sky-900">
            No CRAs yet. Give users the <strong>CRA</strong> role in User Access and leads will be assigned to them ({{ $quota }} each per day, CRD Leads first).
        </p>
    @endif

    {{-- Leads --}}
    <section class="overflow-hidden rounded-xl bg-white shadow-sm">
        @if ($leads->isEmpty())
            <p class="px-4 py-12 text-center text-sm text-muted">
                No leads for this filter.
                @if ($canManage) Past days only have leads if they were synced then — use <strong>Sync now</strong> to fill one in. @endif
            </p>
        @else
            {{-- Column picker --}}
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <p class="text-sm text-muted">{{ number_format($leads->total()) }} {{ \Illuminate\Support\Str::plural('lead', $leads->total()) }}</p>
                <details class="relative" data-column-picker>
                    <summary class="flex h-9 cursor-pointer list-none items-center gap-2 rounded-lg border border-line px-3 text-sm font-medium hover:border-brand-400 hover:text-brand-600 [&::-webkit-details-marker]:hidden">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M9 4v16M15 4v16M4 4h16v16H4z"/></svg>
                        Columns
                    </summary>
                    <div class="absolute right-0 z-30 mt-2 w-72 rounded-xl border border-line bg-white p-3 shadow-lg">
                        <p class="px-1 pb-2 text-xs text-muted">Customer Name to Status are always shown.</p>
                        @foreach ($optionalColumns as $key => $column)
                            <label @class(['flex items-center justify-between gap-2 rounded-lg px-1 py-1.5 text-sm', 'text-muted' => $column['coming_soon'] ?? false])>
                                <span class="flex items-center gap-2">
                                    <input type="checkbox" class="size-4 accent-brand-500" data-column-toggle="{{ $key }}"
                                           @if ($column['coming_soon'] ?? false) disabled @else checked @endif>
                                    {{ $column['label'] }}
                                </span>
                                @if ($column['coming_soon'] ?? false)
                                    <span class="rounded-full bg-canvas px-2 py-0.5 text-xs font-semibold">Coming soon</span>
                                @endif
                            </label>
                        @endforeach
                        <div class="mt-2 flex gap-2 border-t border-line pt-2">
                            <button type="button" data-columns-all="show" class="flex-1 rounded-lg px-2 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Show all</button>
                            <button type="button" data-columns-all="hide" class="flex-1 rounded-lg px-2 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Hide all</button>
                        </div>
                    </div>
                </details>
            </div>

            @php($visibleOptional = collect($optionalColumns)->reject(fn ($c) => $c['coming_soon'] ?? false))

            <div class="overflow-x-auto">
                <table class="w-max min-w-full text-left text-sm">
                    <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="sticky left-0 z-10 bg-[#f7f4f8] px-4 py-3 font-semibold">Customer Name</th>
                            <th class="px-4 py-3 text-right font-semibold">Qty</th>
                            <th class="px-4 py-3 font-semibold">Product</th>
                            <th class="px-4 py-3 font-semibold">Contact #</th>
                            <th class="px-4 py-3 font-semibold">Delivered Date</th>
                            <th class="bg-[#fff4d6] px-4 py-3 text-center font-semibold text-[#7a5a00]">Days since Delivered</th>
                            <th class="px-4 py-3 font-semibold">Est. Out of Stock</th>
                            <th class="px-4 py-3 font-semibold">Recommended Replenishment Day</th>
                            <th class="px-4 py-3 font-semibold">Type</th>
                            <th class="px-4 py-3 font-semibold">Assigned to</th>
                            <th class="px-4 py-3 font-semibold">Status</th>
                            @foreach ($visibleOptional as $key => $column)
                                <th data-col="{{ $key }}" class="bg-[#e3f1ea] px-4 py-3 font-semibold text-[#1d5b45]">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($leads as $lead)
                            @include('segmentation._row', ['lead' => $lead, 'backlogRow' => false])
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($leads->hasPages())
                <div class="border-t border-line px-4 py-3">{{ $leads->links() }}</div>
            @endif
        @endif
    </section>

    {{-- Backlog: unprocessed leads carried over from earlier days --}}
    @if ($backlog->isNotEmpty())
        @php($visibleOptional ??= collect($optionalColumns)->reject(fn ($c) => $c['coming_soon'] ?? false))
        <section class="mt-8 overflow-hidden rounded-xl bg-white shadow-sm" aria-labelledby="backlog-title">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h2 id="backlog-title" class="font-semibold">Carry-over <span class="ml-1 rounded-full bg-coral/15 px-2 py-0.5 text-xs font-semibold text-coral-700">{{ $backlog->filter->carriesOver()->count() }}</span></h2>
                    <p class="text-xs text-muted">No status, PJR, Repeat Purchase or Inactive — stays with the same CRA.</p>
                </div>
                @if ($canTransfer)
                    <button type="button" data-transfer-selected disabled
                            class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-40">
                        Transfer selected
                    </button>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-max min-w-full text-left text-sm">
                    <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="sticky left-0 z-10 bg-[#f7f4f8] px-4 py-3 font-semibold">Customer Name</th>
                            <th class="px-4 py-3 text-right font-semibold">Qty</th>
                            <th class="px-4 py-3 font-semibold">Product</th>
                            <th class="px-4 py-3 font-semibold">Contact #</th>
                            <th class="px-4 py-3 font-semibold">Delivered Date</th>
                            <th class="bg-[#fff4d6] px-4 py-3 text-center font-semibold text-[#7a5a00]">Days since Delivered</th>
                            <th class="px-4 py-3 font-semibold">Est. Out of Stock</th>
                            <th class="px-4 py-3 font-semibold">Recommended Replenishment Day</th>
                            <th class="px-4 py-3 font-semibold">Type</th>
                            <th class="px-4 py-3 font-semibold">Assigned to</th>
                            <th class="px-4 py-3 font-semibold">Status</th>
                            @foreach ($visibleOptional as $key => $column)
                                <th data-col="{{ $key }}" class="bg-[#e3f1ea] px-4 py-3 font-semibold text-[#1d5b45]">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($backlog as $lead)
                            @include('segmentation._row', ['lead' => $lead, 'backlogRow' => true])
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

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

    {{-- Note editor (one dialog shared by every row) --}}
    <dialog id="note-dialog" class="m-auto w-[min(28rem,calc(100%-2rem))] rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
        <form method="dialog" class="p-5" data-note-form>
            <h2 class="text-base font-semibold">Note</h2>
            <p class="text-sm text-muted" data-note-title></p>
            <textarea name="notes" rows="6" maxlength="5000" placeholder="Add a note…"
                      class="mt-3 w-full rounded-lg border border-line p-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"></textarea>
            <p data-note-error role="alert" class="mt-1 hidden text-sm text-coral-700"></p>
            <div class="mt-4 flex items-center justify-between gap-2">
                <button type="button" data-note-delete class="rounded-lg px-3 py-2 text-sm font-medium text-coral-700 hover:bg-coral/10">Delete note</button>
                <div class="flex gap-2">
                    <button type="button" data-note-cancel class="rounded-lg border border-line px-4 py-2 text-sm font-medium hover:border-brand-400">Cancel</button>
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save</button>
                </div>
            </div>
        </form>
    </dialog>
</x-layouts.app>
