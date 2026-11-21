@props(['count' => 0])

@php
    $url = route('sms-reminders.replies.index', ['show' => 'reorder']);
    $label = fn (int $n) => $n === 1 ? '1 customer wants to reorder' : "{$n} customers want to reorder";
@endphp

{{-- SMS Reminders: the CRA's customers who replied YES and still need a call. A top bar button while any are waiting,
     a pop-up when the number goes up (checked again every minute), dismissed until another one replies. --}}
<div data-reorder-alert data-count="{{ $count }}" data-poll-url="{{ route('sms-reminders.reorder-alerts') }}" class="contents">
    <a href="{{ $url }}" data-reorder-pill @if (! $count) hidden @endif
       class="inline-flex h-9 items-center gap-2 rounded-full bg-[#d4edbc] px-3 text-xs font-semibold text-[#0b5236] shadow-sm hover:bg-[#c3e4a5]">
        <span class="relative flex size-2" aria-hidden="true">
            <span class="absolute inline-flex size-full animate-ping rounded-full bg-[#11734b] opacity-60"></span>
            <span class="relative inline-flex size-2 rounded-full bg-[#11734b]"></span>
        </span>
        <span data-reorder-label>{{ $label($count) }}</span>
    </a>

    <div data-reorder-popup role="alertdialog" aria-labelledby="reorder-popup-title" hidden
         class="fixed right-4 bottom-4 z-50 w-80 max-w-[calc(100vw-2rem)] rounded-2xl border border-line bg-white p-4 shadow-xl">
        <div class="flex items-start gap-3">
            <span aria-hidden="true" class="grid size-10 shrink-0 place-items-center rounded-xl bg-[#d4edbc] text-[#11734b]">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
            </span>
            <div class="min-w-0 flex-1">
                <p id="reorder-popup-title" class="font-semibold"><span data-reorder-label>{{ $label($count) }}</span></p>
                <p class="mt-0.5 text-sm text-muted">They replied YES to their restock text. Call them from SMS Reminders.</p>
                <div class="mt-3 flex gap-2">
                    <a href="{{ $url }}" class="grid h-9 place-items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">See them</a>
                    <button type="button" data-reorder-dismiss class="h-9 rounded-lg border border-line px-3 text-sm font-semibold text-muted hover:text-ink">Later</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (() => {
        const root = document.currentScript.previousElementSibling;
        const pill = root.querySelector('[data-reorder-pill]');
        const popup = root.querySelector('[data-reorder-popup]');
        const onRepliesPage = @js(request()->routeIs('sms-reminders.replies.*'));
        const key = 'reorderAlertSeen';
        const seen = () => { try { return Number(sessionStorage.getItem(key) || 0); } catch (e) { return 0; } };
        const remember = (count) => { try { sessionStorage.setItem(key, String(count)); } catch (e) {} };
        const text = (n) => (n === 1 ? '1 customer wants to reorder' : `${n} customers want to reorder`);

        const show = (count) => {
            root.querySelectorAll('[data-reorder-label]').forEach((label) => { label.textContent = text(count); });
            pill.hidden = count === 0;
            // Pops up only when more customers are waiting than when it was last closed (or opened).
            if (count > seen() && !onRepliesPage) popup.hidden = false;
            if (count === 0 || onRepliesPage) { popup.hidden = true; remember(count); }
            if (count < seen()) remember(count);
        };

        root.querySelector('[data-reorder-dismiss]').addEventListener('click', () => {
            popup.hidden = true;
            remember(Number(root.dataset.count));
        });
        popup.querySelector('a').addEventListener('click', () => remember(Number(root.dataset.count)));

        show(Number(root.dataset.count));

        setInterval(async () => {
            if (document.hidden) return;
            try {
                const response = await fetch(root.dataset.pollUrl, { headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const body = await response.json();
                root.dataset.count = body.count;
                show(body.count);
            } catch (e) {}
        }, 60000);
    })();
</script>
