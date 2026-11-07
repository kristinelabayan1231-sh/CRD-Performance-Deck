{{-- Settings: the page's limits ($canSetLimits) and which CRAs work from it instead of the Segmentation Tracker ($settingsCras). --}}
@php($button = 'flex h-9 cursor-pointer list-none items-center rounded-lg border border-line bg-white px-3 text-sm font-semibold hover:border-brand-400 hover:text-brand-600 [&::-webkit-details-marker]:hidden')
<dialog id="exclusive-settings" aria-labelledby="exclusive-settings-title" class="m-auto w-[min(34rem,calc(100%-2rem))] rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
    <div class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
        <div>
            <h2 id="exclusive-settings-title" class="text-base font-semibold">Settings · Exclusive Lead Tracker</h2>
            <p class="text-sm text-muted">{{ $settingsCras ? 'A CRA here leaves the Segmentation Tracker. Their leads stay with them.' : 'The leads and orders each exclusive CRA aims for.' }}</p>
        </div>
        <button type="button" data-dialog-close aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>

    <div class="max-h-[65vh] overflow-y-auto">
        @if ($canSetLimits)
            @php($limitField = 'h-9 w-full rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')
            <h3 class="bg-canvas/60 px-5 py-2 text-xs font-semibold tracking-wide text-muted uppercase">Limits</h3>
            <div class="grid gap-3 px-5 py-4">
                <p class="text-sm">Now: <strong>{{ number_format($limitsNow['daily_leads']) }}</strong> leads a day · <strong>{{ number_format($limitsNow['month_target']) }}</strong> leads a month · <strong>{{ number_format($limitsNow['orders_per_day']) }}</strong> orders a day</p>

                <form method="POST" action="{{ route('settings.exclusive-lead-limits.store') }}" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @csrf
                    <label class="grid gap-1 text-xs font-semibold text-muted">Starts on
                        <input type="date" name="effective_from" required min="{{ $today->toDateString() }}" value="{{ old('effective_from', $today->toDateString()) }}" class="{{ $limitField }} text-ink">
                    </label>
                    <label class="grid gap-1 text-xs font-semibold text-muted">Leads a day
                        <input type="number" name="daily_leads" required min="1" max="2000" value="{{ old('daily_leads', $limitsNow['daily_leads']) }}" class="{{ $limitField }} text-ink">
                    </label>
                    <label class="grid gap-1 text-xs font-semibold text-muted">Leads a month
                        <input type="number" name="month_target" required min="1" max="100000" value="{{ old('month_target', $limitsNow['month_target']) }}" class="{{ $limitField }} text-ink">
                    </label>
                    <label class="grid gap-1 text-xs font-semibold text-muted">Orders a day
                        <input type="number" name="orders_per_day" required min="1" max="1000" value="{{ old('orders_per_day', $limitsNow['orders_per_day']) }}" class="{{ $limitField }} text-ink">
                    </label>
                    <div class="col-span-2 flex flex-wrap items-center justify-between gap-3 sm:col-span-4">
                        <p class="min-w-0 flex-1 text-xs text-muted">Holds from the start date to the end of that month. A month with no limits of its own keeps the last ones.</p>
                        <button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700">Save limits</button>
                    </div>
                </form>

                @if ($limitChanges->isNotEmpty())
                    <ul class="divide-y divide-line rounded-lg border border-line text-sm">
                        @foreach ($limitChanges as $change)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                <div class="min-w-0">
                                    <p class="font-medium">From {{ $change->effective_from->format('M j, Y') }}</p>
                                    <p class="text-xs text-muted">{{ number_format($change->daily_leads) }} leads a day · {{ number_format($change->month_target) }} leads a month · {{ number_format($change->orders_per_day) }} orders a day{{ $change->setter ? ' · set by '.$change->setter->displayName() : '' }}</p>
                                </div>
                                @if ($change->effective_from->greaterThan($today))
                                    <form method="POST" action="{{ route('settings.exclusive-lead-limits.destroy', $change) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" aria-label="Remove the limits from {{ $change->effective_from->format('M j, Y') }}" class="h-9 rounded-lg px-2 text-sm font-semibold text-muted hover:text-coral-700">Remove</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if ($settingsCras)
        <h3 class="bg-canvas/60 px-5 py-2 text-xs font-semibold tracking-wide text-muted uppercase">On this page ({{ $settingsCras['current']->count() }})</h3>
        <ul class="divide-y divide-line">
            @forelse ($settingsCras['current'] as $member)
                <li class="px-5 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $member->displayName() }}</p>
                            <p class="text-xs text-muted">Since {{ $member->exclusive_from->format('M j, Y') }}</p>
                        </div>
                        <details class="group">
                            <summary class="{{ $button }}">Move back</summary>
                            <form method="POST" action="{{ route('settings.exclusive-leads.destroy', $member) }}" class="mt-2 w-64 rounded-lg border border-line bg-canvas/50 p-3 text-sm">
                                @csrf @method('DELETE')
                                <p class="mb-3">Move {{ $member->displayName() }} back to the Segmentation Tracker? Leads already assigned stay with them.</p>
                                <button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700">Yes, move back</button>
                            </form>
                        </details>
                    </div>
                </li>
            @empty
                <li class="px-5 py-5 text-sm text-muted">Nobody yet. Move a CRA in from the list below.</li>
            @endforelse
        </ul>

        <h3 class="bg-canvas/60 px-5 py-2 text-xs font-semibold tracking-wide text-muted uppercase">In the Segmentation Tracker ({{ $settingsCras['available']->count() }})</h3>
        <ul class="divide-y divide-line">
            @forelse ($settingsCras['available'] as $member)
                <li class="px-5 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="min-w-0 truncate font-medium">{{ $member->displayName() }}</p>
                        <details>
                            <summary class="{{ $button }}">Move to Exclusive</summary>
                            <form method="POST" action="{{ route('settings.exclusive-leads.store', $member) }}" class="mt-2 w-64 rounded-lg border border-line bg-canvas/50 p-3 text-sm">
                                @csrf
                                <p class="mb-3">From today, {{ $member->displayName() }} works from this page with {{ number_format($limitsNow['daily_leads']) }} leads a day, taken before the other CRAs. They leave the Segmentation Tracker.</p>
                                <button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700">Yes, move to Exclusive</button>
                            </form>
                        </details>
                    </div>
                </li>
            @empty
                <li class="px-5 py-5 text-sm text-muted">No other active CRAs.</li>
            @endforelse
        </ul>
        @endif
    </div>
</dialog>
