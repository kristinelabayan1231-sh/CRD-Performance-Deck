{{-- Call recording player (one dialog shared by every row); the audio streams from Google Drive through the app --}}
<dialog id="recording-dialog" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-xl p-0 shadow-2xl backdrop:bg-ink/40" aria-labelledby="recording-dialog-title">
    <div class="p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 id="recording-dialog-title" class="text-base font-semibold" data-recording-title>Call recording</h2>
                <p class="text-sm text-muted tabular-nums" data-recording-meta></p>
            </div>
            <button type="button" data-recording-close aria-label="Close" class="grid size-8 place-items-center rounded-lg text-muted hover:bg-canvas hover:text-ink">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <audio controls preload="none" class="mt-4 w-full" data-recording-audio></audio>
        <p data-recording-error role="alert" class="mt-2 hidden text-sm text-coral-700">The recording couldn't be loaded from Google Drive. Try again in a moment.</p>
    </div>
</dialog>
