{{-- Note editor (one dialog shared by every row) --}}
<dialog id="note-dialog" class="m-auto w-[min(28rem,calc(100%-2rem))] rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
    <form method="dialog" class="p-5" data-note-form>
        <h2 class="text-base font-semibold">Note</h2>
        <p class="text-sm text-muted" data-note-title></p>
        <textarea name="notes" rows="6" maxlength="5000" placeholder="Add a note…"
                  class="mt-3 w-full rounded-lg border border-line p-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"></textarea>
        <p data-note-error role="alert" class="mt-1 hidden text-sm text-coral-700"></p>
        <div class="mt-4 flex items-center justify-between gap-2">
            <button type="button" data-note-delete class="rounded-lg px-3 py-2 text-sm font-medium text-coral-700 hover:bg-coral/10">Delete note</button>
            <div class="flex gap-2">
                <button type="button" data-note-cancel class="rounded-lg border border-line px-4 py-2 text-sm font-medium hover:border-brand-400">Cancel</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save</button>
            </div>
        </div>
    </form>
</dialog>
