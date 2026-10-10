{{-- Customer details, loaded when a row is clicked --}}
<dialog id="customer-dialog" aria-labelledby="customer-dialog-title" class="m-auto max-h-[90dvh] w-[min(56rem,calc(100%-2rem))] overflow-hidden rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
    <div class="flex max-h-[90dvh] flex-col">
        <div class="flex shrink-0 items-center justify-between gap-4 bg-gradient-to-r from-brand-600 to-brand-700 px-5 py-3 text-white">
            <h2 id="customer-dialog-title" class="text-base font-bold">Customer Life Time Value (CLTV)</h2>
            <button type="button" data-customer-close aria-label="Close" class="rounded-lg p-1.5 text-white/80 hover:bg-white/15 hover:text-white">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div data-customer-body class="min-h-0 overflow-y-auto p-5"></div>
    </div>
</dialog>
