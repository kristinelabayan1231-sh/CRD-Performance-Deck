{{-- Settings: which CRAs work from this page instead of the Segmentation Tracker. Needs $settingsCras. --}}
@php($button = 'flex h-9 cursor-pointer list-none items-center rounded-lg border border-line bg-white px-3 text-sm font-semibold hover:border-brand-400 hover:text-brand-600 [&::-webkit-details-marker]:hidden')
<dialog id="exclusive-settings" aria-labelledby="exclusive-settings-title" class="m-auto w-[min(34rem,calc(100%-2rem))] rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
    <div class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
        <div>
            <h2 id="exclusive-settings-title" class="text-base font-semibold">Settings · Exclusive Lead Tracker</h2>
            <p class="text-sm text-muted">A CRA here leaves the Segmentation Tracker. Their leads stay with them.</p>
        </div>
        <button type="button" data-dialog-close aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>

    <div class="max-h-[65vh] overflow-y-auto">
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
                                <p class="mb-3">From today, {{ $member->displayName() }} works from this page with {{ number_format(config('exclusive_leads.daily_quota')) }} leads a day, taken before the other CRAs. They leave the Segmentation Tracker.</p>
                                <button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700">Yes, move to Exclusive</button>
                            </form>
                        </details>
                    </div>
                </li>
            @empty
                <li class="px-5 py-5 text-sm text-muted">No other active CRAs.</li>
            @endforelse
        </ul>
    </div>
</dialog>
