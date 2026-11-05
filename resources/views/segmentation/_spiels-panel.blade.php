{{--
    Spiels panel: clicking a lead's row opens the customer's Pancake chat in its own window (on the left of the screen)
    and this panel on the right with the user's spiels (Settings → My Spiels), {name} and {product} filled in for the
    customer, to copy into the chat. Filled in by spiels.js from the row's data-spiel-* and data-spiels.
--}}
<aside hidden data-spiels-panel aria-label="Spiels" data-spiels='@json(auth()->user()->spiels->map->only(['id', 'title', 'body'])->values())'
       class="fixed inset-y-0 right-0 z-40 flex w-full flex-col bg-white shadow-2xl sm:w-[26.25rem]">
    <div class="space-y-3 border-b border-line p-5">
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-base font-semibold" data-spiels-customer></h2>
                <p class="text-sm text-muted" data-spiels-lead></p>
            </div>
            <button type="button" data-spiels-close aria-label="Close spiels" class="grid size-9 place-items-center rounded-lg border border-line text-muted hover:border-brand-400 hover:text-ink">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <p data-spiels-finding class="flex h-10 items-center gap-2 text-sm text-muted">
            <svg class="size-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            Finding the Pancake chat…
        </p>

        <div data-spiels-chat hidden class="flex gap-2">
            <button type="button" data-spiels-open-chat class="flex h-10 flex-1 items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                Open Pancake chat
            </button>
            <a href="{{ route('settings.spiels.index') }}" class="grid h-10 place-items-center rounded-lg border border-line px-4 text-sm font-medium hover:border-brand-400 hover:text-brand-600">Edit spiels</a>
        </div>

        <div data-spiels-no-chat hidden role="status" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">
            <p class="font-semibold">No Pancake chat found for this order</p>
            <p class="mt-1">It wasn't ordered through a Messenger chat. Search the number in Pancake, or call the customer.</p>
            <button type="button" data-spiels-copy-phone class="mt-2 h-9 rounded-lg border border-line bg-white px-3 text-xs font-semibold text-brand-700 hover:border-brand-400">Copy number</button>
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto p-5">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-xs font-semibold tracking-wide text-muted uppercase">My spiels</h3>
            <span class="text-xs text-muted">{name}, {product} filled in</span>
        </div>
        <input type="search" data-spiels-search placeholder="Search spiels" aria-label="Search spiels"
               class="mb-3 h-10 w-full rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
        <ul class="space-y-2" data-spiels-list></ul>
        <p data-spiels-empty hidden class="rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-muted">
            No spiels yet. <a href="{{ route('settings.spiels.index') }}" class="font-semibold text-brand-600 hover:underline">Add them in Settings → My Spiels</a>.
        </p>
    </div>
</aside>
