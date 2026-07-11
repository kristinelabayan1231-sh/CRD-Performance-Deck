@php
    $__craPrompt = auth()->user() ? \App\Models\Cra::where('email', auth()->user()->email)->first() : null;
    $__missingPcs = collect();

    if ($__craPrompt) {
        $__allPcs = \App\Models\Pc::with('facebookPage')->whereNotNull('facebook_page_id')->orderBy('label')->get();
        $__missingPcs = $__craPrompt->missingPcsForWeek(now(), $__allPcs);
    }
@endphp

@if ($__craPrompt && $__missingPcs->isNotEmpty())
    <div id="cohort-prompt-backdrop" class="fixed inset-0 z-[60] bg-slate-900/50"></div>
    <div id="cohort-prompt-modal" class="fixed inset-0 z-[61] flex items-center justify-center p-4">
        <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Set your cohort for this week</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Week of {{ \App\Support\WeekBlocks::label(now()) }} — {{ $__missingPcs->count() }} {{ $__missingPcs->count() === 1 ? 'PC' : 'PCs' }} still need a customer-creation cohort.
                    </p>
                </div>
                <button type="button" id="cohort-prompt-close" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="mt-4">
                <x-weekly-cohort-form
                    :action="route('cra.cohorts.store')"
                    :cra-id="$__craPrompt->id"
                    :week-start="now()->toDateString()"
                    :pcs="$__missingPcs"
                    :editable-week="false"
                    submit-label="Save my cohorts"
                />
            </div>
        </div>
    </div>

    <script>
        (function () {
            const backdrop = document.getElementById('cohort-prompt-backdrop');
            const modal = document.getElementById('cohort-prompt-modal');
            const closeBtn = document.getElementById('cohort-prompt-close');
            const hide = () => { backdrop?.remove(); modal?.remove(); };
            closeBtn?.addEventListener('click', hide);
            backdrop?.addEventListener('click', hide);
        })();
    </script>
@endif
