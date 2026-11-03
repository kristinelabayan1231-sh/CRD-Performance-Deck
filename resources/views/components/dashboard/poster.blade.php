@can('daily_is.view_all')
    {{-- Board button (beside the page title) --}}
    <button type="button" data-poster-open aria-haspopup="dialog"
            class="group inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
            title="Open the CRD Board">
        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18M5 4v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4M12 16v4m-3 0h6M8 9h3m-3 3h5"/>
        </svg>
        CRD Board
    </button>

    {{-- The board: Daily I.S. figures, loaded fresh each time it opens (dashboard.board). --}}
    <dialog data-poster aria-label="CRD Board"
            class="m-auto w-[min(800px,calc(100vw-2rem))] max-w-none overflow-visible bg-transparent p-0 backdrop:bg-ink/70 backdrop:backdrop-blur-sm">
        <div data-poster-stage class="flex flex-col gap-3 bg-transparent p-2 [&:fullscreen]:items-center [&:fullscreen]:justify-center [&:fullscreen]:bg-[#16141f]">
            <div class="flex w-full max-w-[800px] items-center justify-end gap-2 text-xs font-semibold">
                <div role="group" aria-label="Day shown" class="mr-auto inline-flex rounded-full bg-white/90 p-0.5 shadow-sm">
                    <button type="button" data-poster-day="today" aria-pressed="true" class="rounded-full px-3 py-1 text-ink aria-pressed:bg-brand-600 aria-pressed:text-white">Today</button>
                    <button type="button" data-poster-day="yesterday" aria-pressed="false" class="rounded-full px-3 py-1 text-ink aria-pressed:bg-brand-600 aria-pressed:text-white">Yesterday</button>
                </div>
                <button type="button" data-poster-shot disabled class="inline-flex items-center gap-1 rounded-full bg-white/90 px-3 py-1 text-ink shadow-sm hover:bg-white disabled:opacity-60">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                    <span data-poster-shot-label>Screenshot</span>
                </button>
                <button type="button" data-poster-fullscreen class="rounded-full bg-white/90 px-3 py-1 text-ink shadow-sm hover:bg-white">Full screen</button>
                <button type="button" data-poster-close class="grid size-7 place-items-center rounded-full bg-white/90 text-ink shadow-sm hover:bg-white" aria-label="Close board">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <div data-poster-board data-url="{{ route('dashboard.board') }}" aria-live="polite" class="w-full max-w-[800px]"></div>
        </div>
    </dialog>
@endcan
