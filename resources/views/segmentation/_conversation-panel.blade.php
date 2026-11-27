{{-- A customer's SMS conversation beside the tracker, opened from a row's SMS cell (sms-panel.js). --}}
@can('sms_reminders.view')
    <aside hidden data-conversation-panel aria-label="SMS conversation" class="fixed inset-y-0 right-0 z-40 flex w-full flex-col bg-white shadow-2xl sm:w-[30rem]">
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-line px-4 py-3">
            <h2 class="min-w-0 truncate text-base font-semibold" data-conversation-title></h2>
            <button type="button" data-conversation-close aria-label="Close conversation" class="grid size-9 shrink-0 place-items-center rounded-lg border border-line text-muted hover:border-brand-400 hover:text-ink">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <iframe title="SMS conversation" class="min-h-0 w-full flex-1 border-0" src="about:blank"></iframe>
    </aside>
@endcan
