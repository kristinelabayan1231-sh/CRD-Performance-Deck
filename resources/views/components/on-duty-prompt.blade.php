{{-- Opens on a CRA's first Segmentation Tracker visit of the day; "Not now" asks again on the next page. --}}
<dialog id="on-duty-dialog" aria-labelledby="on-duty-title" class="m-auto w-[min(22rem,calc(100%-2rem))] rounded-2xl p-6 text-center shadow-2xl backdrop:bg-ink/40">
    <span class="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-teal/15 text-teal-700" aria-hidden="true">
        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
    </span>
    <h2 id="on-duty-title" class="text-lg font-bold">Log in for today?</h2>
    <p class="mt-2 text-sm text-muted">Logging in puts you on today's on-duty list ({{ \App\Support\WorkingDate::realToday()->format('D, M j') }}). Supervisors use it to work out average sales per CRA.</p>
    <form method="POST" action="{{ route('segmentation.on-duty') }}" class="mt-5 grid gap-2">
        @csrf
        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none">Log in · I'm on duty</button>
        <button type="button" onclick="this.closest('dialog').close()" class="rounded-lg px-4 py-2 text-sm font-semibold text-muted hover:text-ink">Not now</button>
    </form>
</dialog>
<script>document.getElementById('on-duty-dialog').showModal();</script>
