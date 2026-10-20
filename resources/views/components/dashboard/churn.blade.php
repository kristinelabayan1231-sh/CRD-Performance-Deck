@props(['churn', 'range', 'query' => []])

@php($pct = fn (?float $value, int $decimals = 1) => $value === null ? '—' : number_format($value * 100, $decimals).'%')

{{--
    Customer churn as one short strip of its own, apart from Results, so it isn't read as these dates' customers: it judges
    customers delivered months earlier whose time to reorder ran out in these dates. Any rate opens the CRD / FSD / overall breakdown.
--}}
@php($deadlines = $range->label())
@php([$reorderFrom, $reorderTo] = [$range->from->subDays($churn['grace_days']), $range->to->subDays($churn['grace_days'])])
@php($reorderDates = $reorderFrom->equalTo($reorderTo) ? $reorderFrom->format('M j') : $reorderFrom->format('M j').'–'.$reorderTo->format($reorderFrom->isSameMonth($reorderTo) ? 'j' : 'M j'))
@php($monthLabel = fn (string $month) => \Carbon\CarbonImmutable::parse($month.'-01')->format($range->from->isSameYear($month.'-01') ? 'M' : 'M Y'))
@php($churnTeams = ['all' => 'Overall', 'crd' => 'CRD', 'fsd' => 'FSD'])
<section {{ $attributes->class('flex flex-wrap items-center gap-x-3.5 gap-y-2 rounded-xl bg-[#fdeeee] px-3 py-2 text-[#8f3236] ring-1 ring-[#f6d3d4]') }} aria-labelledby="churn-title">
    <h3 id="churn-title" class="flex items-center gap-2 text-sm font-bold">
        Customer churn
        <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-coral-700">Deadline {{ $deadlines }}</span>
    </h3>

    <ul class="flex flex-1 flex-wrap gap-1.5 tabular-nums">
        @foreach ($churnTeams as $team => $teamLabel)
            @php($teamChurn = $churn[$team])
            <li>
                <button type="button" onclick="document.getElementById('churn-dialog').showModal()" aria-haspopup="dialog"
                        class="inline-flex items-baseline gap-1.5 rounded-lg bg-white/70 px-2.5 py-1 transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-coral"
                        title="{{ $team === 'all' ? 'CRD and FSD' : $teamLabel }}-delivered customers from earlier months whose {{ $churn['grace_days'] }} days to reorder ended {{ $deadlines }}. Click for the breakdown.">
                    <span class="text-[10px] font-bold tracking-wide uppercase">{{ $teamLabel }}</span>
                    <span class="text-[15px] font-bold">{{ $pct($teamChurn['rate'], 2) }}</span>
                    <span class="text-[11px] text-[#8f3236]/80">{{ number_format($teamChurn['lost']) }} lost of {{ number_format($teamChurn['customers']) }}</span>
                </button>
            </li>
        @endforeach
    </ul>
    <button type="button" onclick="document.getElementById('churn-dialog').showModal()" aria-haspopup="dialog" class="text-xs font-semibold hover:underline">Breakdown &rarr;</button>

    <dialog id="churn-dialog" aria-labelledby="churn-dialog-title" onclick="if (event.target === this) this.close()"
            class="m-auto max-h-[90dvh] w-[min(44rem,calc(100%-2rem))] overflow-hidden rounded-2xl p-0 shadow-2xl backdrop:bg-ink/40">
        <div class="flex max-h-[90dvh] flex-col">
            <div class="flex shrink-0 items-start justify-between gap-4 border-b border-line px-5 py-4">
                <div class="min-w-0">
                    <h2 id="churn-dialog-title" class="text-base font-semibold">Customer churn · reorder deadline {{ $deadlines }}</h2>
                    <p class="text-sm text-muted">
                        Not the customers of {{ $deadlines }}: CRD- and FSD-delivered customers from earlier months who ran out, and whose {{ $churn['grace_days'] }} days to reorder ended in these dates.
                        Overall counts a customer on both lists once. Lower is better.
                    </p>
                </div>
                <button type="button" onclick="this.closest('dialog').close()" aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            {{-- Due / came back / lost counts open those customers in Customer Database → Churn, for the same dates --}}
            @php($churnLinks = auth()->user()->can('customers.view'))
            @php($churnStatus = ['Due to reorder' => 'due', 'Came back in time' => 'back', 'Lost' => 'lost'])
            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                <div class="overflow-x-auto rounded-xl border border-line">
                    <table class="w-full text-sm">
                        <thead class="bg-canvas/60 text-xs text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-2 text-left font-semibold"></th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">CRD</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold">FSD</th>
                                <th scope="col" class="px-4 py-2 text-right font-semibold text-ink">Overall</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line tabular-nums">
                            @foreach ([
                                'Churn rate' => fn ($c) => $pct($c['rate'], 2),
                                'Due to reorder' => fn ($c) => number_format($c['customers']),
                                'Came back in time' => fn ($c) => number_format($c['customers'] - $c['lost']),
                                'Lost' => fn ($c) => number_format($c['lost']),
                            ] as $row => $value)
                                <tr>
                                    <th scope="row" class="px-4 py-2 text-left font-medium">{{ $row }}</th>
                                    @foreach (['crd', 'fsd', 'all'] as $team)
                                        <td @class(['px-4 py-2 text-right', 'font-semibold' => $team === 'all', 'text-coral-700' => $row === 'Churn rate', 'text-teal-700' => $row === 'Came back in time'])>
                                            @if ($churnLinks && isset($churnStatus[$row]))
                                                <a href="{{ route('customers.churn', [...$query, 'status' => $churnStatus[$row], 'list' => $team]) }}" class="hover:underline" title="See these customers">{{ $value($churn[$team]) }}</a>
                                            @else
                                                {{ $value($churn[$team]) }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="max-w-prose text-xs text-muted">Due = their {{ $churn['grace_days'] }} days to reorder ended {{ $deadlines }}. Came back = a Pancake order (not canceled) or a new delivery in that time.</p>
                    @if ($churnLinks)
                        <a href="{{ route('customers.churn', $query) }}" class="shrink-0 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">See customers &rarr;</a>
                    @endif
                </div>

                <div class="rounded-xl border border-line p-4">
                    <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">Reorder date {{ $reorderDates }} <span class="normal-case">(+ {{ $churn['grace_days'] }} days grace = {{ $deadlines }})</span></p>
                    <p class="mt-1 text-xs text-muted">The Due to reorder customers above, by the month of the delivery that ran out.</p>
                    <dl class="mt-2 space-y-2 text-xs">
                        @foreach (['crd' => 'CRD', 'fsd' => 'FSD'] as $team => $teamLabel)
                            <div class="flex flex-wrap items-center gap-1.5">
                                <dt class="w-10 font-semibold text-ink">{{ $teamLabel }}</dt>
                                @forelse ($churn[$team]['delivered_months'] as $month => $count)
                                    <dd class="rounded-full bg-brand-50 px-2 py-0.5 text-ink tabular-nums">{{ $monthLabel($month) }} <span class="font-semibold">{{ number_format($count) }}</span></dd>
                                @empty
                                    <dd class="text-muted">No customers due in these dates.</dd>
                                @endforelse
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        </div>
    </dialog>
</section>
