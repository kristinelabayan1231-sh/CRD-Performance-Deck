@props(['issues'])

{{--
    Header issues: a short summary that opens (zoom) a pop-up listing every issue, grouped by CRA.
    Issues come from App\Services\CraIssues (this month, each order's latest tags).
--}}
@use('App\Services\CraIssues')
@php
    $byType = $issues->countBy('type');
    $summary = collect(CraIssues::LABELS)->filter(fn ($label, $type) => $byType[$type] ?? 0)
        ->map(fn ($label, $type) => $byType[$type].' '.strtolower($label));
    $month = \App\Support\WorkingDate::realToday();
@endphp

@if ($issues->isEmpty())
    <span class="flex items-center gap-1.5 rounded-full bg-teal/10 px-3 py-1.5 text-xs font-semibold text-teal-700" title="No issues in the CRAs' orders this month">
        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
        No order issues
    </span>
@else
    <button type="button" onclick="document.getElementById('issues-dialog').showModal()"
            title="Show all issues" aria-haspopup="dialog"
            class="flex min-w-0 items-center gap-2 rounded-full border border-coral/50 bg-coral/10 py-1 pr-1.5 pl-3 text-xs font-semibold text-coral-700 hover:bg-coral/20">
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
        <span class="tabular-nums">{{ $issues->count() }} {{ Str::plural('issue', $issues->count()) }}</span>
        <span class="hidden truncate font-medium md:inline">· {{ $summary->join(', ') }}</span>
        {{-- Zoom: open the full list --}}
        <span class="grid size-6 shrink-0 place-items-center rounded-full bg-white text-coral-700" aria-hidden="true">
            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="6"/><path stroke-linecap="round" d="m20 20-4.5-4.5M11 8.5v5M8.5 11h5"/></svg>
        </span>
    </button>

    <dialog id="issues-dialog" aria-labelledby="issues-title" onclick="if (event.target === this) this.close()"
            class="m-auto h-[min(48rem,calc(100%-2rem))] w-[min(64rem,calc(100%-2rem))] overflow-hidden rounded-2xl p-0 shadow-2xl backdrop:bg-ink/40">
        <div class="flex h-full flex-col">
            <div class="flex shrink-0 items-start justify-between gap-4 border-b border-line px-5 py-4">
                <div class="min-w-0">
                    <h2 id="issues-title" class="text-base font-semibold">Order issues · {{ $month->format('F') }} 1–{{ $month->format('j') }}</h2>
                    <p class="text-sm text-muted">These leave orders out of confirmed orders and gross sales. Fix them in Pancake; numbers update on the next sync.</p>
                </div>
                <button type="button" onclick="this.closest('dialog').close()" aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                <ul class="space-y-1 text-xs text-muted">
                    @foreach ($summary as $type => $text)
                        <li><span class="font-semibold text-coral-700">{{ ucfirst($text) }}</span> · {{ CraIssues::FIXES[$type] }}</li>
                    @endforeach
                </ul>

                @foreach ($issues->groupBy(fn ($issue) => $issue['cra']->id)->sortBy(fn ($mine) => $mine->first()['cra']->displayName(), SORT_NATURAL | SORT_FLAG_CASE) as $mine)
                    @php($cra = $mine->first()['cra'])
                    <section class="overflow-hidden rounded-xl border border-line">
                        <h3 class="flex items-center gap-2 bg-canvas/60 px-4 py-2.5 text-sm font-semibold">
                            {{ $cra->displayName() }}
                            <span class="rounded-full bg-coral/15 px-2 py-0.5 text-xs text-coral-700 tabular-nums">{{ $mine->count() }}</span>
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="text-xs tracking-wide text-muted uppercase">
                                    <tr>
                                        <th class="px-4 py-2 font-semibold">Issue</th>
                                        <th class="px-4 py-2 font-semibold">Date</th>
                                        <th class="px-4 py-2 font-semibold">Order #</th>
                                        <th class="px-4 py-2 font-semibold">Customer</th>
                                        <th class="px-4 py-2 text-right font-semibold">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    @foreach ($mine as $issue)
                                        <tr>
                                            <td class="px-4 py-2"><span class="rounded-full bg-coral/15 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-coral-700">{{ CraIssues::LABELS[$issue['type']] }}</span></td>
                                            <td class="px-4 py-2 whitespace-nowrap">{{ $issue['day']?->format('M j') ?? '—' }}</td>
                                            <td class="px-4 py-2 tabular-nums">{{ $issue['order_id'] ?? '—' }}</td>
                                            <td class="px-4 py-2">{{ $issue['customer'] ?? '—' }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ $issue['amount'] !== null ? '₱'.number_format($issue['amount']) : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    </dialog>
@endif
