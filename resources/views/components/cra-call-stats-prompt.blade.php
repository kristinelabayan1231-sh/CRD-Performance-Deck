@php
    $__callStatCra = auth()->user() ? \App\Models\Cra::where('email', auth()->user()->email)->first() : null;
    $__todayStat = $__callStatCra ? $__callStatCra->callStats->firstWhere('date', now()->toDateString()) : null;

    // The global cohort-setup modal (layouts/app.blade.php) already covers
    // this CRA if their weekly cohorts are incomplete — stacking a second
    // full-screen modal on top of it traps the user, so this one waits its
    // turn rather than competing for the same z-index.
    $__hasPendingCohortPrompt = false;
    if ($__callStatCra) {
        $__allPcsForPrompt = \App\Models\Pc::whereNotNull('facebook_page_id')->get();
        $__hasPendingCohortPrompt = $__callStatCra->missingPcsForWeek(now(), $__allPcsForPrompt)->isNotEmpty();
    }
@endphp

@if ($__callStatCra && ! $__todayStat && ! $__hasPendingCohortPrompt)
    <div id="call-stats-prompt-backdrop" class="fixed inset-0 z-[60] bg-slate-900/50"></div>
    <div id="call-stats-prompt-modal" class="fixed inset-0 z-[61] flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Log today's call stats</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ now()->format('l, M j, Y') }} — enter your Total Calls and Answered Calls for today.</p>
                </div>
                <button type="button" id="call-stats-prompt-close" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <form method="POST" action="{{ route('cra.call-stats.store') }}" class="mt-5 space-y-4">
                @csrf
                <input type="hidden" name="date" value="{{ now()->toDateString() }}">
                <div>
                    <label for="prompt_total_calls" class="block text-xs font-medium text-slate-500">Total Calls</label>
                    <input type="number" min="0" id="prompt_total_calls" name="total_calls" required
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                </div>
                <div>
                    <label for="prompt_answered_calls" class="block text-xs font-medium text-slate-500">Answered Calls</label>
                    <input type="number" min="0" id="prompt_answered_calls" name="answered_calls" required
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                </div>
                <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Save my stats
                </button>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const backdrop = document.getElementById('call-stats-prompt-backdrop');
            const modal = document.getElementById('call-stats-prompt-modal');
            const closeBtn = document.getElementById('call-stats-prompt-close');
            const hide = () => { backdrop?.remove(); modal?.remove(); };
            closeBtn?.addEventListener('click', hide);
            backdrop?.addEventListener('click', hide);
        })();
    </script>
@endif
