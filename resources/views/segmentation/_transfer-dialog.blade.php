{{-- Backlog transfer: step 1 shows every CRA's current load, step 2 confirms. Needs $workload. --}}
<dialog id="transfer-dialog" aria-labelledby="transfer-title" data-workload='@json($workload)'
        class="m-auto w-[min(28rem,calc(100%-2rem))] rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
    <form method="POST" action="{{ route('segmentation.transfer') }}" data-transfer-form>
        @csrf
        <div data-transfer-ids></div>
        <input type="hidden" name="to" value="">

        <div class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
            <div class="min-w-0">
                <h2 id="transfer-title" class="text-base font-semibold">Transfer backlog</h2>
                <p class="truncate text-sm text-muted" data-transfer-summary></p>
            </div>
            <button type="button" data-transfer-close aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        {{-- Step 1: pick a CRA, seeing their current load --}}
        <div data-transfer-step="choose">
            <p class="px-5 pt-4 text-sm font-medium">Choose a CRA. Current assigned leads:</p>
            <ul class="max-h-[50vh] divide-y divide-line overflow-y-auto py-2" data-transfer-targets></ul>
        </div>

        {{-- Step 2: confirm --}}
        <div data-transfer-step="confirm" hidden class="px-5 py-5">
            <p class="text-sm" data-transfer-confirm-text></p>
            <p class="mt-2 text-xs text-muted" data-transfer-after></p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" data-transfer-back class="rounded-lg border border-line px-4 py-2 text-sm font-medium hover:border-brand-400">Back</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Confirm transfer</button>
            </div>
        </div>
    </form>
</dialog>
