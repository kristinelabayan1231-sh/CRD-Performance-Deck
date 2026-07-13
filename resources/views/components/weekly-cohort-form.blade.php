@props([
    'action',
    'craId',
    'weekStart',
    'pcs',
    'existing' => null,
    'submitLabel' => 'Save cohorts',
    'editableWeek' => true,
])

@php
    $existing ??= collect();
    $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    // 16 PCs in a single table was too long to scroll, but most pages own
    // only 1–2 PCs, so one-step-per-page would mean a dozen Next clicks.
    // Instead whole page-groups are packed into steps of up to $perStep
    // PCs — a page's PCs are never split across steps — giving a handful
    // of short steps. Hidden steps stay in the DOM, so one Save still
    // submits every PC across all steps.
    $groupedPcs = collect($pcs)
        ->groupBy(fn ($pc) => $pc->facebookPage?->page_name ?? 'No page')
        ->sortKeys();

    $perStep = 6;
    $steps = [];
    $currentStep = [];
    $currentCount = 0;

    foreach ($groupedPcs as $pageName => $pagePcs) {
        if ($currentCount > 0 && $currentCount + $pagePcs->count() > $perStep) {
            $steps[] = $currentStep;
            $currentStep = [];
            $currentCount = 0;
        }

        $currentStep[$pageName] = $pagePcs;
        $currentCount += $pagePcs->count();
    }

    if ($currentStep !== []) {
        $steps[] = $currentStep;
    }

    // The table's page subheaders carry the full names; the step header
    // just needs a short handle.
    $stepTitle = fn (array $step) => count($step) > 1
        ? array_key_first($step).' + '.(count($step) - 1).' more '.(count($step) === 2 ? 'page' : 'pages')
        : array_key_first($step);
    $stepPcCount = fn (array $step) => collect($step)->sum(fn ($pcsInPage) => $pcsInPage->count());
    $fieldIndex = 0;
@endphp

<form method="POST" action="{{ $action }}" class="space-y-3">
    @csrf
    <input type="hidden" name="cra_id" value="{{ $craId }}">

    @if ($editableWeek)
        <div>
            <label class="block text-xs font-medium text-slate-500">Any date in the target week</label>
            <input type="date" name="week_start" value="{{ $weekStart }}" required
                class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
            <p class="mt-1 text-[11px] text-slate-400">Snaps to that date's 7-day block (1–7, 8–14, 15–21, 22–28, 29–end).</p>
        </div>
    @else
        <input type="hidden" name="week_start" value="{{ $weekStart }}">
    @endif

    <div data-cohort-steps>
        <div class="flex items-center justify-between gap-3 rounded-t-lg border border-b-0 border-slate-200 bg-slate-50 px-3 py-2">
            <p class="min-w-0 truncate text-sm font-semibold text-slate-800">
                <span data-step-title>{{ $stepTitle($steps[0]) }}</span>
                <span data-step-pc-count class="ml-1 text-xs font-normal text-slate-400">{{ $stepPcCount($steps[0]) }} {{ $stepPcCount($steps[0]) === 1 ? 'PC' : 'PCs' }}</span>
            </p>
            @if (count($steps) > 1)
                <span data-step-counter class="shrink-0 text-xs tabular-nums text-slate-400">Step 1 of {{ count($steps) }}</span>
            @endif
        </div>

        @foreach ($steps as $step)
            <div data-cohort-step data-step-title="{{ $stepTitle($step) }}" data-pc-count="{{ $stepPcCount($step) }}"
                class="overflow-hidden rounded-b-lg border border-slate-200 {{ $loop->first ? '' : 'hidden' }}">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="px-3 py-2 font-medium">PC</th>
                            <th class="px-3 py-2 font-medium">Cohort From</th>
                            <th class="px-3 py-2 font-medium">Cohort To</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($step as $pageName => $pagePcs)
                        <tr class="border-b border-slate-100 bg-teal-50/50">
                            <td colspan="3" class="px-3 py-1.5 text-xs font-semibold text-teal-800">{{ $pageName }}</td>
                        </tr>
                        @foreach ($pagePcs as $pc)
                            @php
                                $row = $existing->get($pc->id);
                                $noCohort = $row !== null && ! $row->hasCohort();
                                $i = $fieldIndex++;
                            @endphp
                            <tr class="border-b border-slate-50 last:border-0" data-cohort-row>
                                <td class="px-3 py-2 align-top">
                                    <input type="hidden" name="pcs[{{ $i }}][pc_id]" value="{{ $pc->id }}">
                                    <span class="block font-medium text-slate-700">{{ $pc->label }}</span>
                                    <label class="mt-1 flex w-fit cursor-pointer items-center gap-1.5 text-xs text-slate-400">
                                        <input type="checkbox" name="pcs[{{ $i }}][no_cohort]" value="1" data-no-cohort @checked($noCohort)
                                            class="rounded border-slate-300 text-teal-600 focus:border-teal-500 focus:ring-1 focus:ring-teal-500">
                                        No cohort this week
                                    </label>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex gap-1">
                                        <select name="pcs[{{ $i }}][cohort_from_month]" required @disabled($noCohort)
                                            class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-300">
                                            @foreach ($monthNames as $mi => $mname)
                                                <option value="{{ $mi + 1 }}" @selected(($row->cohort_from_month ?? now()->month) === $mi + 1)>{{ $mname }}</option>
                                            @endforeach
                                        </select>
                                        <select name="pcs[{{ $i }}][cohort_from_year]" required @disabled($noCohort)
                                            class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-300">
                                            @foreach (range(now()->year - 3, now()->year + 1) as $year)
                                                <option value="{{ $year }}" @selected(($row->cohort_from_year ?? now()->year) === $year)>{{ $year }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex gap-1">
                                        <select name="pcs[{{ $i }}][cohort_to_month]" required @disabled($noCohort)
                                            class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-300">
                                            @foreach ($monthNames as $mi => $mname)
                                                <option value="{{ $mi + 1 }}" @selected(($row->cohort_to_month ?? now()->month) === $mi + 1)>{{ $mname }}</option>
                                            @endforeach
                                        </select>
                                        <select name="pcs[{{ $i }}][cohort_to_year]" required @disabled($noCohort)
                                            class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-300">
                                            @foreach (range(now()->year - 3, now()->year + 1) as $year)
                                                <option value="{{ $year }}" @selected(($row->cohort_to_year ?? now()->year) === $year)>{{ $year }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            @if (count($steps) > 1)
                <div class="flex items-center gap-2">
                    <button type="button" data-step-prev disabled
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40">
                        &larr; Back
                    </button>
                    <button type="button" data-step-next
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40">
                        Next &rarr;
                    </button>
                    <span class="text-[11px] text-slate-400">Saving applies to every step, not just this one.</span>
                </div>
            @else
                <span></span>
            @endif

            <button type="submit"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
                {{ $submitLabel }}
            </button>
        </div>
    </div>
</form>

@once
    <script>
        // Delegated so it works for every cohort form on the page (one per
        // CRA on the admin screen) and only ever binds once.
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-step-prev], [data-step-next]');

            if (!button) {
                return;
            }

            const root = button.closest('[data-cohort-steps]');
            const steps = Array.from(root.querySelectorAll('[data-cohort-step]'));
            const current = steps.findIndex((el) => !el.classList.contains('hidden'));
            const target = Math.min(Math.max(current + (button.hasAttribute('data-step-next') ? 1 : -1), 0), steps.length - 1);

            steps.forEach((el, i) => el.classList.toggle('hidden', i !== target));

            const pcCount = Number(steps[target].dataset.pcCount);
            root.querySelector('[data-step-title]').textContent = steps[target].dataset.stepTitle;
            root.querySelector('[data-step-pc-count]').textContent = `${pcCount} ${pcCount === 1 ? 'PC' : 'PCs'}`;
            root.querySelector('[data-step-counter]').textContent = `Step ${target + 1} of ${steps.length}`;
            root.querySelector('[data-step-prev]').disabled = target === 0;
            root.querySelector('[data-step-next]').disabled = target === steps.length - 1;
        });

        // "No cohort this week": disabled selects drop out of the POST,
        // and required_unless on the server accepts the row as cohort-less.
        document.addEventListener('change', (event) => {
            const checkbox = event.target.closest('[data-no-cohort]');

            if (!checkbox) {
                return;
            }

            checkbox.closest('[data-cohort-row]').querySelectorAll('select').forEach((select) => {
                select.disabled = checkbox.checked;
            });
        });
    </script>
@endonce
