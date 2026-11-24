{{--
    The floating schedule (supervisors and managers, on_duty.view): a calendar button at the bottom right of every page
    that opens a panel with every CRA's week or month, loaded from cra-schedule.peek when it opens (schedule-peek.js).
    The page stays usable behind it; Esc or the × closes it.
--}}
<div data-schedule-peek data-url="{{ route('cra-schedule.peek') }}" class="contents">
    <button type="button" data-peek-toggle aria-expanded="false" aria-controls="schedule-peek-panel" title="CRA schedule"
            class="fixed right-5 bottom-5 z-40 flex h-12 items-center gap-2 rounded-full bg-brand-600 pr-4 pl-3.5 text-sm font-semibold text-white shadow-lg transition hover:bg-brand-700 focus-visible:ring-4 focus-visible:ring-brand-200 focus-visible:outline-none aria-expanded:bg-brand-700">
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4m8-4v4"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 14h.01M12 14h.01M16 14h.01M8 17.5h.01M12 17.5h.01"/></svg>
        <span class="hidden sm:inline">CRA schedule</span>
    </button>

    <section id="schedule-peek-panel" data-peek-panel hidden role="dialog" aria-labelledby="schedule-peek-title"
             class="fixed right-5 bottom-20 z-40 flex max-h-[min(36rem,calc(100dvh-7rem))] min-h-56 w-[min(56rem,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-2xl border border-line bg-white text-ink shadow-2xl">
        <header class="flex items-center gap-2 bg-brand-600 pr-2 pl-4 text-white">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4m8-4v4"/></svg>
            <h2 id="schedule-peek-title" class="flex-1 text-sm font-semibold">CRA schedule</h2>
            <button type="button" data-peek-close aria-label="Close" class="grid size-11 place-items-center rounded-lg hover:bg-white/15">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </header>
        <div data-peek-content class="flex min-h-0 flex-1 flex-col" aria-live="polite">
            <p class="m-auto flex items-center gap-2 text-sm font-semibold text-brand-600"><span class="spinner" aria-hidden="true"></span>Loading…</p>
        </div>
    </section>
</div>
