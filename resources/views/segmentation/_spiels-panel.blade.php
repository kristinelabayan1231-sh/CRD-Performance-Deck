{{--
    The customer panel on the right of the tracker. Clicking a lead's row opens it (and the customer's Pancake chat in its
    own window on the left of the screen, as before); a row's text button opens it on Conversation or Order.
    Header: the customer, Call and the Pancake chat. Tabs: Conversation (their SMS chat, sms-panel.js), Order (their order
    history) and Spiels (the user's spiels, Settings → My Spiels, {name} and {product} filled in to copy; spiels.js).
    $rowOpen = false (the full views): a row click doesn't open it, only the text buttons do.
    The JSON is escaped with {{ }} (not @json, which splits its argument on commas and loses its escaping): an apostrophe
    in a spiel would otherwise end the attribute, and the broken JSON stops every row click on the page.
--}}
@php($tabButton = 'flex h-9 flex-1 items-center justify-center gap-1.5 rounded-t-xl text-xs font-semibold text-white/70 hover:text-white aria-selected:bg-white aria-selected:text-ink')
<aside hidden data-spiels-panel aria-label="Customer" data-spiels="{{ auth()->user()->spiels->map->only(['id', 'title', 'body'])->values()->toJson() }}"
       @if (! ($rowOpen ?? true)) data-row-open="off" @endif
       class="fixed inset-y-2 right-2 z-40 flex w-[calc(100%-1rem)] flex-col overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-black/10 sm:w-[28rem]">
    <div class="shrink-0 space-y-3 bg-[#111111] px-4 pt-4 text-white">
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <h2 class="truncate font-display text-lg font-bold" data-spiels-customer></h2>
                <p class="text-xs text-white/70" data-spiels-lead></p>
            </div>
            <button type="button" data-spiels-close aria-label="Close" class="grid size-9 shrink-0 place-items-center rounded-full bg-white/10 text-white hover:bg-white/20">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        {{-- Call (when the CRA's phone is linked) and the Pancake chat --}}
        <div class="flex gap-2">
            <button type="button" data-panel-call hidden class="flex h-9 flex-1 items-center justify-center gap-1.5 rounded-xl bg-white/10 text-sm font-semibold text-white hover:bg-white/20 disabled:opacity-60">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
                <span data-call-label>Call</span>
            </button>
            <p data-spiels-finding class="flex h-9 flex-1 items-center justify-center gap-2 rounded-xl bg-white/5 text-xs text-white/70">
                <svg class="size-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                Finding the Pancake chat…
            </p>
            <div data-spiels-chat hidden class="flex flex-1">
                <button type="button" data-spiels-open-chat class="flex h-9 flex-1 items-center justify-center gap-1.5 rounded-xl bg-brand-500 text-sm font-semibold text-ink hover:bg-brand-400">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                    Pancake chat
                </button>
            </div>
            <div data-spiels-no-chat hidden class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-white/5 px-2 text-xs text-white/80" role="status">
                <span>No Pancake chat for this order.</span>
                <button type="button" data-spiels-copy-phone class="h-7 rounded-full bg-white/15 px-2.5 font-semibold text-white hover:bg-white/25">Copy number</button>
            </div>
        </div>

        <div class="flex gap-1" role="tablist" aria-label="Customer">
            <button type="button" role="tab" data-panel-tab-button="conversation" aria-selected="false" hidden class="{{ $tabButton }}">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linejoin="round" d="M4 5h16v11H9l-5 4z"/></svg>
                Conversation
            </button>
            <button type="button" role="tab" data-panel-tab-button="order" aria-selected="false" class="{{ $tabButton }}">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h2l2.4 10.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6M10 20h.01M17 20h.01"/></svg>
                Order
            </button>
            <button type="button" role="tab" data-panel-tab-button="spiels" aria-selected="true" class="{{ $tabButton }}">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h6M5 4h14a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H9l-5 4V5a1 1 0 0 1 1-1Z"/></svg>
                Spiels
            </button>
        </div>
    </div>

    {{-- Conversation: the customer's SMS chat (sms-panel.js loads it when shown) --}}
    <div data-panel-pane="conversation" hidden class="min-h-0 flex-1">
        <iframe title="SMS conversation" class="size-full border-0" src="about:blank"></iframe>
    </div>

    {{-- Order: what they bought before (loaded when shown) --}}
    <div data-panel-pane="order" hidden class="min-h-0 flex-1 overflow-y-auto p-5">
        <h3 class="mb-3 text-xs font-semibold tracking-wide text-muted uppercase">Order history</h3>
        <ol data-panel-orders class="space-y-2"></ol>
        <p data-panel-orders-empty hidden class="rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-muted">No earlier orders found.</p>
    </div>

    {{-- Spiels --}}
    <div data-panel-pane="spiels" class="min-h-0 flex-1 overflow-y-auto p-5">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-xs font-semibold tracking-wide text-muted uppercase">My spiels</h3>
            <a href="{{ route('settings.spiels.index') }}" class="text-xs font-semibold text-ink hover:underline">Edit spiels</a>
        </div>
        <input type="search" data-spiels-search placeholder="Search spiels" aria-label="Search spiels"
               class="mb-3 h-10 w-full rounded-xl border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
        <ul class="space-y-2" data-spiels-list></ul>
        <p data-spiels-empty hidden class="rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-muted">
            No spiels yet. <a href="{{ route('settings.spiels.index') }}" class="font-semibold text-ink hover:underline">Add them in Settings → My Spiels</a>.
        </p>
        <p class="mt-3 text-xs text-muted">{name} and {product} are filled in for the customer.</p>
    </div>
</aside>
