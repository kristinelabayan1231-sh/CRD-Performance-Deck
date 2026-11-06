@php
    // Colours are inline: the board is dark glass in both themes, like the CRD Board, so the generated dark theme must not restyle it.
    $number = fn (int|float|null $value) => $value === null ? '—' : number_format($value);
    $signed = fn (int|float $value) => ($value > 0 ? '+' : ($value < 0 ? '−' : '')).number_format(abs($value));
    $peso = fn (int|float|null $value) => $value === null ? '—' : '₱'.number_format($value);
    $millions = fn (int|float $value) => '₱'.number_format($value / 1000000, 2).'M';
    $percent = fn (?float $value) => $value === null ? '—' : number_format($value * 100, 1).'%';
    $glass = 'background: rgb(45 42 62 / .55); -webkit-backdrop-filter: blur(18px) saturate(1.4); backdrop-filter: blur(18px) saturate(1.4); border: 1px solid rgb(255 255 255 / .12); border-radius: 16px';
    $hero = 'background: rgb(58 49 87 / .55); -webkit-backdrop-filter: blur(18px) saturate(1.4); backdrop-filter: blur(18px) saturate(1.4); border: 1px solid rgb(195 155 255 / .35); border-radius: 16px; box-shadow: 0 0 40px rgb(124 77 255 / .18)';
    $head = 'background: linear-gradient(90deg, rgb(70 58 108 / .65), rgb(58 49 87 / .3)); border-bottom: 1px solid rgb(255 255 255 / .1); border-radius: 16px 16px 0 0; padding: 12px 16px';
    $h2 = 'margin: 0; font-size: 15px; font-weight: 600';
    $muted = 'color: #bdb6cf';
    $fsd = '#2a9fc9';
    $fsdTint = 'rgb(42 159 201 / .35)';
    $crd = '#a05aff';
    $crdTint = 'rgb(160 90 255 / .38)';
    $added = '#3fd1b9';
    $lost = '#ff9a9d';
    $reactivation = 'rgb(255 255 255 / .28)';
    $accent = '#c39bff';
    $axis = 'border-bottom: 1px solid rgb(255 255 255 / .3)';
    $rowLine = 'border-bottom: 1px solid rgb(255 255 255 / .08)';
@endphp

<x-layouts.app title="Presentation Board">
    <div data-presentation-board class="flex flex-col gap-4 tabular-nums"
         style="margin: -4px; padding: 20px; border-radius: 22px; color: #f1eef8; background-color: #211f2d; background-image: radial-gradient(circle at 12% 3%, rgb(124 77 255 / .5), transparent 28%), radial-gradient(circle at 95% 15%, rgb(27 207 180 / .22), transparent 26%), radial-gradient(circle at 70% 65%, rgb(160 90 255 / .3), transparent 34%)">

        @if (! $snapshot)
            <h1 style="margin: 0; font-size: 28px; font-weight: 700">Presentation Board</h1>
            <p role="status" style="{{ $glass }}; margin: 0; padding: 20px">The year's figures are being prepared. They appear here after the next scheduled refresh, within {{ \App\Services\PresentationBoard::EVERY_HOURS }} hours.</p>
        @else
            @php
                $linkQuery = ['from' => $from, 'to' => $to];
                $spark = function (array $values, string $color) {
                    $peak = max(1, ...array_map('abs', $values));
                    $last = array_key_last($values);

                    return collect($values)->map(fn ($value, $key) => ['h' => max(6, round(abs($value) / $peak * 100)), 'bg' => $key === $last ? $color : 'rgb(255 255 255 / .28)'])->all();
                };
                $col = fn (string $key) => array_column($months, $key);
                $tiles = [
                    ['label' => 'FSD leads', 'dot' => $fsd, 'value' => $number($totals['fsd_leads']), 'sub' => 'Not yet handled by a CRA · '.$number($totals['fsd_waiting']).' waiting', 'spark' => $spark($col('fsd_leads'), $fsd)],
                    ['label' => 'CRD leads', 'dot' => $crd, 'value' => $number($totals['crd_leads']), 'sub' => 'Sold by the CRD team · '.$number($totals['crd_waiting']).' waiting', 'spark' => $spark($col('crd_leads'), $crd)],
                    ['label' => 'Added from FSD', 'dot' => $added, 'value' => $number($totals['added']), 'sub' => 'New customers · '.$number($totals['added_waiting']).' waiting', 'spark' => $spark($col('added'), $added)],
                    ['label' => 'CRD repeat', 'dot' => $crd, 'value' => $number($totals['repeat']), 'sub' => $percent($totals['repeat_rate']).' of '.$number($totals['repeat_base']), 'spark' => $spark($col('repeat'), $crd)],
                    ['label' => 'FSD retained', 'dot' => $fsd, 'value' => $number($totals['retained']), 'sub' => $percent($totals['retained_rate']).' of '.$number($totals['retained_base']), 'spark' => $spark($col('retained'), $fsd)],
                    ['label' => 'For reactivation', 'dot' => $reactivation, 'value' => $number($totals['all_reactivation']), 'sub' => 'Past 30 days, no CRA contact yet or ordered late', 'spark' => $spark($col('all_reactivation'), $reactivation)],
                    ['label' => 'Churn rate', 'dot' => $lost, 'value' => $percent($totals['churn_rate']), 'sub' => $number($totals['churn_lost']).' churned of '.$number($totals['churn_due']).' due · '.$totals['churn_label'], 'spark' => $spark(array_map(fn ($rate) => $rate ?? 0, $col('churn_rate')), $lost)],
                    ['label' => 'Net customers', 'dot' => $added, 'value' => $signed($totals['net']), 'sub' => $totals['added_waiting'] || $totals['churn_partial'] ? 'Added minus lost; see the notes under the chart' : 'Added minus lost', 'spark' => $spark($col('net'), $added)],
                ];

                $rateSeries = [
                    ['key' => 'conversion_rate', 'label' => 'Conversion rate', 'color' => $accent, 'value' => $totals['conversion_rate'], 'sub' => $totals['conversion_base'] ? $number($totals['conversion_customers']).' of '.$number($totals['conversion_base']).' · Segmentation '.$percent($totals['conversion_tabs']['sc']).' · Broadcast '.$percent($totals['conversion_tabs']['bc']).' · October on' : 'No tracker data in these months'],
                    ['key' => 'retained_rate', 'label' => 'Retain rate (FSD)', 'color' => $fsd, 'value' => $totals['retained_rate'], 'sub' => $number($totals['retained']).' of '.$number($totals['retained_base']).' · each month matches the Dashboard'],
                    ['key' => 'repeat_rate', 'label' => 'Repeat rate (CRD)', 'color' => $crd, 'value' => $totals['repeat_rate'], 'sub' => $number($totals['repeat']).' of '.$number($totals['repeat_base']).' · each month matches the Dashboard'],
                ];
                $ratePeak = collect($months)->flatMap(fn (array $month) => [$month['conversion_rate'], $month['retained_rate'], $month['repeat_rate']])->filter()->max() ?? 0;
                // One scale for all three, with round steps every 5% or 10%.
                $rateTop = max(20, (int) ceil($ratePeak * 5) * 20);
                $count = max(1, count($months));
                $x = fn (int $index) => round(($index + .5) / $count * 100, 2);
                $y = fn (float $rate) => round($rate * 100 / $rateTop * 100, 2);
                // End labels only where they don't collide; the legend, read-out and table carry the rest.
                $endLabels = [];
                foreach (collect($rateSeries)->sortByDesc(fn (array $series) => $months[array_key_last($months)][$series['key']] ?? -1) as $series) {
                    $value = $months[array_key_last($months)][$series['key']];
                    if ($value !== null && collect($endLabels)->every(fn (float $shown) => abs($shown - $value) * 100 / $rateTop >= .09)) {
                        $endLabels[$series['key']] = $value;
                    }
                }
                $trackerAt = collect($months)->search(fn (array $month) => $month['source'] === 'tracker');

                $leadPanels = [
                    ['name' => 'FSD leads', 'key' => 'fsd_leads', 'wait' => 'fsd_waiting', 'color' => $fsd, 'tint' => $fsdTint],
                    ['name' => 'CRD leads', 'key' => 'crd_leads', 'wait' => 'crd_waiting', 'color' => $crd, 'tint' => $crdTint],
                ];
                $churnPeak = max(1, ...array_map(fn (array $month) => max($month['added'] + $month['added_waiting'], $month['all_lost'] + $month['all_reactivation']), $months));
                $addedTint = 'rgb(63 209 185 / .4)';
                $salesPeak = max(1, ...$col('gross'));
                $lastKey = $months[array_key_last($months)]['key'];
                $hasPartial = $totals['churn_partial'];
                $shecomShare = $totals['gross'] ? $totals['shecom_gross'] / $totals['gross'] : null;
                $teams = [
                    ['name' => 'FSD leads', 'leads' => 'fsd_leads', 'bought' => 'retained', 'base' => 'retained_base', 'rate' => 'retained_rate', 'lost' => 'fsd_lost', 'reactivation' => 'fsd_reactivation', 'boughtName' => 'Retained', 'color' => $fsd, 'tint' => $fsdTint, 'added' => true, 'id' => 'fsd', 'wait' => 'fsd_waiting'],
                    ['name' => 'CRD leads', 'leads' => 'crd_leads', 'bought' => 'repeat', 'base' => 'repeat_base', 'rate' => 'repeat_rate', 'lost' => 'crd_lost', 'reactivation' => 'crd_reactivation', 'boughtName' => 'Repeat', 'color' => $crd, 'tint' => $crdTint, 'added' => false, 'id' => 'crd', 'wait' => 'crd_waiting'],
                ];
            @endphp

            <header class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 style="margin: 0; font-size: 28px; font-weight: 700">Presentation Board</h1>
                        <span style="border-radius: 999px; background: rgb(195 155 255 / .2); color: #e4d3ff; padding: 3px 10px; font-size: 12px; font-weight: 600">{{ $monthOptions[$from] }}@if ($from !== $to) – {{ $monthOptions[$to] }}@endif</span>
                    </div>
                    <p style="margin: 0; font-size: 14px; {{ $muted }}">The CRD team's year so far, running. Point at any month to see its numbers on every chart.</p>
                </div>
                <form method="GET" action="{{ route('presentation-board.index') }}" class="flex flex-wrap items-end gap-2">
                    <input type="hidden" name="period" value="{{ $period }}">
                    @foreach (['from' => 'From', 'to' => 'To'] as $field => $label)
                        <label class="flex flex-col gap-1" style="font-size: 12px; font-weight: 600; {{ $muted }}">{{ $label }}
                            <select name="{{ $field }}" style="height: 44px; min-width: 110px; border: 1px solid rgb(255 255 255 / .18); border-radius: 8px; background: #2d2a3e; padding: 0 10px; font-size: 14px; color: #f1eef8">
                                @foreach ($monthOptions as $value => $name)
                                    <option value="{{ $value }}" @selected(${$field} === $value)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                    <button type="submit" style="height: 44px; border-radius: 8px; background: #c39bff; color: #211f2d; padding: 0 16px; font-size: 14px; font-weight: 700; cursor: pointer">Apply</button>
                    <a href="{{ route('presentation-board.index', ['period' => $period]) }}" style="display: flex; align-items: center; height: 44px; border: 1px solid rgb(255 255 255 / .18); border-radius: 8px; padding: 0 14px; font-size: 14px; font-weight: 600; color: #f1eef8">Year to date</a>
                </form>
            </header>

            @error('to')
                <p role="alert" style="margin: 0; color: {{ $lost }}; font-size: 14px">{{ $message }}</p>
            @enderror

            <section aria-label="Data status" class="flex flex-wrap items-center gap-x-5 gap-y-2.5" style="{{ $glass }}; padding: 12px 16px; font-size: 14px">
                <span class="flex items-center gap-2" style="font-weight: 600"><span aria-hidden="true" style="width: 9px; height: 9px; border-radius: 50%; background: {{ $added }}"></span>Updated {{ \Carbon\CarbonImmutable::parse($snapshot['built_at'])->timezone(config('segmentation.timezone'))->format('M j, g:i A') }} · every {{ \App\Services\PresentationBoard::EVERY_HOURS }} hours</span>
                @unless ($progress['complete'])
                    <span class="flex min-w-72 flex-1 flex-wrap items-center gap-2.5">
                        <span style="font-weight: 700">Backfill ongoing: {{ number_format($progress['done']) }} of {{ number_format($progress['total']) }} page-months loaded.</span>
                        <span>Older months may still go up or down until it finishes.</span>
                        <span aria-hidden="true" class="min-w-20 flex-1" style="height: 8px; border-radius: 999px; background: rgb(255 255 255 / .12)"><span style="display: block; width: {{ $progress['total'] ? round($progress['done'] / $progress['total'] * 100) : 0 }}%; height: 8px; border-radius: 999px; background: {{ $accent }}"></span></span>
                    </span>
                    <span style="{{ $muted }}">Waiting for page check, not in the totals: FSD {{ $number($totals['fsd_waiting']) }} · CRD {{ $number($totals['crd_waiting']) }}</span>
                @endunless
            </section>

            <section aria-label="Totals" class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(205px, 1fr))">
                <div class="flex flex-col gap-1.5 sm:col-span-2" style="{{ $hero }}; padding: 16px">
                    <span style="font-size: 13px; font-weight: 600; color: {{ $accent }}">Overall total sales, every CRA</span>
                    <span style="font-size: 44px; line-height: 1.1; font-weight: 700; letter-spacing: -1px; font-variant-numeric: normal">{{ $peso($totals['gross']) }}</span>
                    <span style="font-size: 13px; {{ $muted }}">{{ $number($totals['orders']) }} confirmed orders · AOV {{ $peso($totals['aov']) }} · {{ $percent($shecomShare) }} at Shecom's amount, the rest at Pancake's until synced</span>
                    <span aria-hidden="true" class="flex items-end gap-0.5" style="height: 28px">
                        @foreach ($spark($col('gross'), $accent) as $bar)
                            <span style="flex: 1; max-width: 24px; height: {{ $bar['h'] }}%; border-radius: 2px 2px 0 0; background: {{ $bar['bg'] }}"></span>
                        @endforeach
                    </span>
                </div>
                @foreach ($tiles as $tile)
                    <div class="flex flex-col gap-1" style="{{ $glass }}; padding: 14px 16px">
                        <span class="flex items-center gap-1.5" style="font-size: 13px; font-weight: 600; {{ $muted }}"><span aria-hidden="true" style="width: 9px; height: 9px; border-radius: 50%; background: {{ $tile['dot'] }}"></span>{{ $tile['label'] }}</span>
                        <span style="font-size: 28px; line-height: 1.1; font-weight: 700; letter-spacing: -.5px; font-variant-numeric: normal">{{ $tile['value'] }}</span>
                        <span style="font-size: 13px; {{ $muted }}">{{ $tile['sub'] }}</span>
                        <span aria-hidden="true" class="flex items-end gap-0.5" style="height: 24px">
                            @foreach ($tile['spark'] as $bar)
                                <span style="flex: 1; max-width: 18px; height: {{ $bar['h'] }}%; border-radius: 2px 2px 0 0; background: {{ $bar['bg'] }}"></span>
                            @endforeach
                        </span>
                    </div>
                @endforeach
            </section>

            <section style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 style="{{ $h2 }}">Conversion, retain and repeat rates by month</h2>
                    <span id="board-read-rates" data-read-default="Point at a month for its rates" style="font-size: 13px; {{ $muted }}">Point at a month for its rates</span>
                </header>
                <div class="flex flex-wrap gap-4" style="padding: 16px">
                    <div class="flex flex-col gap-3.5" style="flex: 1 1 280px">
                        @foreach ($rateSeries as $series)
                            <div class="flex flex-col gap-1">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="flex items-center gap-2" style="font-size: 14px; font-weight: 600"><span aria-hidden="true" style="width: 14px; height: 3px; border-radius: 2px; background: {{ $series['color'] }}"></span>{{ $series['label'] }}</span>
                                    <span style="font-size: 24px; font-weight: 700; font-variant-numeric: normal">{{ $percent($series['value']) }}</span>
                                </div>
                                <span style="font-size: 13px; {{ $muted }}">{{ $series['sub'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex min-w-0 gap-2" style="flex: 999 1 560px">
                        <div aria-hidden="true" class="flex flex-col justify-between text-right" style="width: 36px; height: 220px; font-size: 12px; {{ $muted }}">
                            @foreach ([1, .75, .5, .25, 0] as $step)
                                <span>{{ $rateTop * $step }}%</span>
                            @endforeach
                        </div>
                        <div class="min-w-0 flex-1">
                            <div data-chart style="position: relative; height: 220px; background-image: linear-gradient(rgb(255 255 255 / .1) 1px, transparent 1px); background-size: 100% 25%; {{ $axis }}">
                                @if ($trackerAt)
                                    @php
                                        $splitAt = round($trackerAt / $count * 100, 2);
                                    @endphp
                                    <span aria-hidden="true" style="position: absolute; top: 0; bottom: 0; left: {{ $splitAt }}%; width: 1px; background: rgb(255 255 255 / .35)"></span>
                                    <span style="position: absolute; top: 2px; left: {{ $splitAt }}%; transform: translateX(-100%); padding-right: 6px; font-size: 12px; white-space: nowrap; {{ $muted }}">Shecom deliveries</span>
                                    <span style="position: absolute; top: 2px; left: {{ $splitAt }}%; padding-left: 6px; font-size: 12px; white-space: nowrap; {{ $muted }}">Tracker</span>
                                @endif
                                <svg role="img" aria-label="Monthly conversion, retain and repeat rates; the numbers are in the month-by-month table below" viewBox="0 0 100 100" preserveAspectRatio="none" style="position: absolute; inset: 0; width: 100%; height: 100%">
                                    @foreach ($rateSeries as $series)
                                        <polyline fill="none" stroke="{{ $series['color'] }}" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"
                                                  points="{{ collect($months)->map(fn (array $month, int $index) => $month[$series['key']] === null ? null : $x($index).','.(100 - $y($month[$series['key']])))->filter()->join(' ') }}" />
                                    @endforeach
                                </svg>
                                @foreach ($rateSeries as $series)
                                    @foreach ($months as $index => $month)
                                        @if ($month[$series['key']] !== null)
                                            <span aria-hidden="true" style="position: absolute; left: {{ $x($index) }}%; bottom: {{ $y($month[$series['key']]) }}%; width: 8px; height: 8px; margin: 0 0 -4px -4px; border-radius: 50%; background: {{ $series['color'] }}; box-shadow: 0 0 0 2px #2d2a3e"></span>
                                            @if ($index === array_key_last($months) && isset($endLabels[$series['key']]))
                                                <span style="position: absolute; left: {{ $x($index) }}%; bottom: {{ $y($month[$series['key']]) }}%; transform: translate(-50%, -8px); font-size: 12px; font-weight: 700; white-space: nowrap">{{ $percent($month[$series['key']]) }}</span>
                                            @endif
                                        @endif
                                    @endforeach
                                @endforeach
                                <div class="flex" style="position: absolute; inset: 0">
                                    @foreach ($months as $month)
                                        @php
                                            $read = $month['label'].': conversion '.($month['conversion_rate'] === null ? 'no tracker data' : $percent($month['conversion_rate'])).' · retain '.$percent($month['retained_rate']).' · repeat '.$percent($month['repeat_rate']);
                                        @endphp
                                        <button type="button" data-point="{{ $month['key'] }}" data-read="{{ $read }}" data-read-target="board-read-rates" aria-label="{{ $read }}" title="{{ $read }}" class="flex-1 cursor-default" style="background: transparent; border-radius: 6px"></button>
                                    @endforeach
                                </div>
                            </div>
                            <div aria-hidden="true" class="flex pt-1" style="font-size: 12px; font-weight: 600; {{ $muted }}">
                                @foreach ($months as $month)
                                    <span class="flex-1 text-center">{{ $month['label'] }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 style="{{ $h2 }}">FSD and CRD leads by month</h2>
                    <span id="board-read-leads" data-read-default="1 customer = 1 lead per month, by est. out-of-stock date. Each chart has its own scale." style="font-size: 13px; {{ $muted }}">1 customer = 1 lead per month, by est. out-of-stock date. Each chart has its own scale.</span>
                </header>
                <div class="flex flex-wrap gap-5" style="padding: 16px">
                    @foreach ($leadPanels as $panel)
                        @php
                            $peak = max(1, ...array_map(fn (array $month) => $month[$panel['key']] + $month[$panel['wait']], $months));
                            $best = collect($months)->sortByDesc($panel['key'])->first()['key'];
                        @endphp
                        <div class="min-w-0" style="flex: 1 1 460px">
                            <div class="mb-2 flex flex-wrap items-center gap-3" style="font-size: 13px">
                                <span class="flex items-center gap-1.5" style="font-weight: 700"><span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 3px; background: {{ $panel['color'] }}"></span>{{ $panel['name'] }}: {{ $number($totals[$panel['key']]) }}</span>
                                <span class="flex items-center gap-1.5" style="{{ $muted }}"><span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 3px; background: {{ $panel['tint'] }}"></span>Waiting for page check: {{ $number($totals[$panel['wait']]) }}</span>
                            </div>
                            <div data-chart class="flex items-end gap-1" style="height: 170px; {{ $axis }}">
                                @foreach ($months as $month)
                                    @php
                                        $read = $month['label'].': FSD '.$number($month['fsd_leads']).' · CRD '.$number($month['crd_leads']).' · waiting '.$number($month['fsd_waiting'] + $month['crd_waiting']);
                                    @endphp
                                    <button type="button" data-point="{{ $month['key'] }}" data-read="{{ $read }}" data-read-target="board-read-leads" title="{{ $month['label'] }} {{ $panel['name'] }}: {{ $number($month[$panel['key']]) }}, plus {{ $number($month[$panel['wait']]) }} waiting" class="flex h-full min-w-0 flex-1 cursor-default flex-col items-center justify-end gap-0.5" style="background: transparent; border-radius: 6px">
                                        <span style="font-size: 12px; font-weight: 700; white-space: nowrap">{{ $month['key'] === $best || $month['key'] === $lastKey ? $number($month[$panel['key']]) : '' }}</span>
                                        <span style="display: block; width: 100%; max-width: 24px; height: {{ round($month[$panel['wait']] / $peak * 86) }}%; border-radius: 4px 4px 0 0; background: {{ $panel['tint'] }}"></span>
                                        <span style="display: block; width: 100%; max-width: 24px; height: {{ round($month[$panel['key']] / $peak * 86) }}%; background: {{ $panel['color'] }}"></span>
                                    </button>
                                @endforeach
                            </div>
                            <div aria-hidden="true" class="flex gap-1 pt-1" style="font-size: 12px; font-weight: 600; {{ $muted }}">
                                @foreach ($months as $month)
                                    <span class="flex-1 text-center">{{ $month['label'] }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 style="{{ $h2 }}">Acquisition vs churn: customers added and lost</h2>
                    <div class="flex flex-wrap gap-3.5" style="font-size: 13px">
                        <span class="flex items-center gap-1.5"><span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 3px; background: {{ $added }}"></span>Added {{ $number($totals['added']) }}</span>
                        @if ($totals['added_waiting'])
                            <span class="flex items-center gap-1.5"><span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 3px; background: {{ $addedTint }}"></span>Added, waiting for page check {{ $number($totals['added_waiting']) }}</span>
                        @endif
                        <span class="flex items-center gap-1.5"><span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 3px; background: {{ $lost }}"></span>Churned {{ $number($totals['all_lost']) }}</span>
                        <span class="flex items-center gap-1.5"><span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 3px; background: {{ $reactivation }}"></span>For reactivation {{ $number($totals['all_reactivation']) }}</span>
                        <span style="font-weight: 700">Net {{ $signed($totals['net']) }}</span>
                        <span style="font-weight: 700">Churn rate {{ $percent($totals['churn_rate']) }} ({{ $totals['churn_label'] }})</span>
                    </div>
                </header>
                <div class="overflow-x-auto" style="padding: 16px">
                    <div data-chart class="flex gap-1" style="min-width: 760px; font-size: 13px">
                        <div style="width: 84px; flex: none; font-weight: 600; {{ $muted }}">
                            <div style="height: 252px"></div>
                            @foreach (['Month', 'Added', 'Churned', 'Reactivation', 'Net', 'Churn %'] as $label)
                                <div style="height: 24px; line-height: 24px">{{ $label }}</div>
                            @endforeach
                        </div>
                        @foreach ($months as $month)
                            <button type="button" data-point="{{ $month['key'] }}" title="{{ $month['label'] }}: added {{ $number($month['added']) }}, plus {{ $number($month['added_waiting']) }} waiting, {{ $number($month['all_lost']) }} churned and {{ $number($month['all_reactivation']) }} for reactivation of {{ $number($month['all_due']) }} due" class="min-w-0 flex-1 cursor-default text-center" style="background: transparent; border-radius: 6px">
                                <span class="flex flex-col items-center justify-end gap-0.5" style="height: 130px"><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['added_waiting'] / $churnPeak * 100) }}%; border-radius: 4px 4px 0 0; background: {{ $addedTint }}"></span><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['added'] / $churnPeak * 100) }}%; background: {{ $added }}"></span></span>
                                <span style="display: block; height: 2px; background: rgb(255 255 255 / .35)"></span>
                                <span class="flex flex-col items-center justify-start gap-0.5" style="height: 120px"><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['all_lost'] / $churnPeak * 100) }}%; background: {{ $lost }}"></span><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['all_reactivation'] / $churnPeak * 100) }}%; border-radius: 0 0 4px 4px; background: {{ $reactivation }}"></span></span>
                                <span style="display: block; height: 24px; line-height: 24px; font-weight: 700">{{ $month['label'] }}</span>
                                <span style="display: block; height: 24px; line-height: 24px">{{ $number($month['added'] + $month['added_waiting']) }}</span>
                                <span style="display: block; height: 24px; line-height: 24px">{{ $number($month['all_lost']) }}</span>
                                <span style="display: block; height: 24px; line-height: 24px">{{ $number($month['all_reactivation']) }}{{ $month['churn_partial'] ? '*' : '' }}</span>
                                <span style="display: block; height: 24px; line-height: 24px; font-weight: 700">{{ $signed($month['net']) }}</span>
                                <span style="display: block; height: 24px; line-height: 24px">{{ $percent($month['churn_rate']) }}{{ $month['churn_partial'] ? '*' : '' }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="flex flex-col gap-1.5" style="padding: 0 16px 14px; font-size: 13px; {{ $muted }}">
                    @if ($hasPartial)
                        <p style="margin: 0; font-weight: 600; color: #f1eef8">* Partial data: the tracker's leads start with June 2026's out-of-stock dates, so few customers can count as churned before July, and quantities only start in April. These months stay out of the overall churn rate.</p>
                    @endif
                    @if ($totals['added_waiting'])
                        <p style="margin: 0">While the backfill runs, the Added and Net rows include customers waiting for their page check, because Churned isn't limited by page.</p>
                    @endif
                    <p style="margin: 0; font-weight: 600; color: #f1eef8">The rate only counts customers a CRA actually reached, so it can still rise for recent months as CRAs work their leads. Overall = churned ÷ due added up across {{ $totals['churn_label'] }}, not an average of the monthly rates.</p>
                    <p style="margin: 0"><span style="color: #f1eef8; font-weight: 600">FSD churn {{ $percent($totals['fsd_churn_rate']) }} · CRD churn {{ $percent($totals['crd_churn_rate']) }}.</span> A customer on both lists counts once in the overall rate.</p>
                </div>
            </section>

            <div class="flex flex-wrap items-stretch gap-4">
                <section class="min-w-0" style="flex: 1 1 520px; {{ $glass }}">
                    <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                        <h2 style="{{ $h2 }}">Total sales by month</h2>
                        <span id="board-read-sales" data-read-default="Point at a month for its sales" style="font-size: 13px; {{ $muted }}">Point at a month for its sales</span>
                    </header>
                    <div style="padding: 16px">
                        <div data-chart class="flex items-end gap-1" style="height: 380px; {{ $axis }}">
                            @foreach ($months as $month)
                                @php
                                    $read = $month['label'].': '.$peso($month['gross']).' · '.$number($month['orders']).' orders · AOV '.$peso($month['aov']);
                                @endphp
                                <button type="button" data-point="{{ $month['key'] }}" data-read="{{ $read }}" data-read-target="board-read-sales" title="{{ $read }}" class="flex h-full min-w-0 flex-1 cursor-default flex-col items-center justify-end gap-0.5" style="background: transparent; border-radius: 6px">
                                    <span style="font-size: 12px; font-weight: 700; white-space: nowrap">{{ $month['gross'] == $salesPeak || $month['key'] === $lastKey ? $millions($month['gross']) : '' }}</span>
                                    <span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['gross'] / $salesPeak * 84) }}%; border-radius: 4px 4px 0 0; background: {{ $crd }}"></span>
                                </button>
                            @endforeach
                        </div>
                        <div aria-hidden="true" class="flex gap-1 pt-1" style="font-size: 12px; font-weight: 600; {{ $muted }}">
                            @foreach ($months as $month)
                                <span class="flex-1 text-center">{{ $month['label'] }}</span>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="min-w-0" style="flex: 1 1 520px; {{ $glass }}">
                    <header style="{{ $head }}"><h2 style="{{ $h2 }}">Top products</h2></header>
                    <div class="overflow-x-auto" style="padding: 10px 16px 14px; font-size: 14px">
                        @php
                            $productPeak = max(1, collect($products)->reject(fn (array $product) => $product['name'] === \App\Services\PresentationBoard::OTHERS)->max('sales') ?? 1);
                            $productColumns = 'display: grid; grid-template-columns: minmax(150px, 1.6fr) 1fr 54px 64px 54px; gap: 10px; min-width: 470px; text-align: right';
                        @endphp
                        <div style="{{ $productColumns }}; padding-bottom: 6px; border-bottom: 1px solid rgb(255 255 255 / .14); font-size: 13px; font-weight: 600; {{ $muted }}">
                            <span class="text-left">Product</span><span>Sales</span><span>Share</span><span>Orders</span><span>Qty</span>
                        </div>
                        <div style="max-height: 440px; overflow-y: auto; min-width: 470px">
                            @foreach ($products as $rank => $product)
                                <div style="{{ $productColumns }}; align-items: center; padding: 7px 0; {{ $rowLine }}">
                                    <span class="flex flex-col gap-1 text-left"><span style="font-weight: 600">{{ $product['name'] === \App\Services\PresentationBoard::OTHERS ? '' : ($rank + 1).'. ' }}{{ $product['name'] }}</span><span aria-hidden="true" style="display: block; height: 6px; border-radius: 999px; background: rgb(255 255 255 / .1)"><span style="display: block; width: {{ min(100, round($product['sales'] / $productPeak * 100)) }}%; height: 6px; border-radius: 999px; background: {{ $crd }}"></span></span></span>
                                    <span>{{ $peso($product['sales']) }}</span><span style="{{ $muted }}">{{ number_format($product['share'] * 100) }}%</span><span>{{ $number($product['orders']) }}</span><span>{{ $number($product['qty']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            </div>

            <section style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 style="{{ $h2 }}">Month by month</h2>
                    @if ($totals['fsd_waiting'] || $totals['crd_waiting'])
                        <span style="font-size: 13px; {{ $muted }}">+ = waiting for page check, not in the lead count</span>
                    @endif
                </header>
                <div class="overflow-x-auto">
                    @php
                        $ledger = 'display: grid; grid-template-columns: 44px repeat(2, 1fr) repeat(5, 1fr) repeat(5, 1fr) .8fr .8fr 1.5fr 70px; gap: 4px; min-width: 1060px; padding-inline: 12px; text-align: right; font-size: 12px';
                    @endphp
                    <div style="{{ $ledger }}; padding-top: 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; {{ $muted }}">
                        <span></span>
                        <span style="grid-column: span 2; text-align: left; border-bottom: 2px solid {{ $crd }}; padding-bottom: 3px">Leads</span>
                        <span style="grid-column: span 5; text-align: left; border-bottom: 2px solid {{ $added }}; padding-bottom: 3px">Acquisition vs churn</span>
                        <span style="grid-column: span 5; text-align: left; border-bottom: 2px solid {{ $fsd }}; padding-bottom: 3px">Rates</span>
                        <span style="grid-column: span 3; text-align: left; border-bottom: 2px solid {{ $accent }}; padding-bottom: 3px">Sales</span>
                        <span></span>
                    </div>
                    <div style="{{ $ledger }}; padding-block: 7px; border-bottom: 1px solid rgb(255 255 255 / .14); font-weight: 600; {{ $muted }}">
                        <span class="text-left">Month</span><span>FSD</span><span>CRD</span><span>Added</span><span>Churned</span><span>Reactiv.</span><span>Net</span><span>Churn %</span><span>Retained</span><span>Retain %</span><span>Repeat</span><span>Repeat %</span><span>Conv. %</span><span>Orders</span><span>AOV</span><span>Gross</span><span class="text-left" title="Where Retained and Repeat come from">Source</span>
                    </div>
                    @foreach ($months as $month)
                        <div style="{{ $ledger }}; padding-block: 7px; {{ $rowLine }}">
                            <span class="text-left" style="font-weight: 700">{{ $month['label'] }}</span>
                            <span>{{ $number($month['fsd_leads']) }}@if ($month['fsd_waiting'])<br><span style="font-size: 11px; {{ $muted }}">+{{ $number($month['fsd_waiting']) }}</span>@endif</span>
                            <span>{{ $number($month['crd_leads']) }}@if ($month['crd_waiting'])<br><span style="font-size: 11px; {{ $muted }}">+{{ $number($month['crd_waiting']) }}</span>@endif</span>
                            <span>{{ $number($month['added'] + $month['added_waiting']) }}</span><span>{{ $number($month['all_lost']) }}</span><span>{{ $number($month['all_reactivation']) }}{{ $month['churn_partial'] ? '*' : '' }}</span><span style="font-weight: 700">{{ $signed($month['net']) }}</span><span>{{ $percent($month['churn_rate']) }}{{ $month['churn_partial'] ? '*' : '' }}</span>
                            <span>{{ $number($month['retained']) }}</span><span>{{ $percent($month['retained_rate']) }}</span><span>{{ $number($month['repeat']) }}</span><span>{{ $percent($month['repeat_rate']) }}</span><span>{{ $percent($month['conversion_rate']) }}</span>
                            <span>{{ $number($month['orders']) }}</span><span>{{ $peso($month['aov']) }}</span><span style="font-weight: 600">{{ $peso($month['gross']) }}</span>
                            <span class="text-left"><span style="border-radius: 999px; padding: 2px 6px; font-size: 11px; font-weight: 600; background: {{ $month['source'] === 'tracker' ? 'rgb(63 209 185 / .22)' : 'rgb(42 159 201 / .28)' }}">{{ $month['source'] === 'tracker' ? 'Tracker' : 'Deliveries' }}</span></span>
                        </div>
                    @endforeach
                    <div style="{{ $ledger }}; padding-block: 10px; font-weight: 700; background: rgb(70 58 108 / .5); border-radius: 0 0 16px 16px">
                        <span class="text-left">Total</span>
                        <span>{{ $number($totals['fsd_leads']) }}@if ($totals['fsd_waiting'])<br><span style="font-size: 11px; {{ $muted }}">+{{ $number($totals['fsd_waiting']) }}</span>@endif</span>
                        <span>{{ $number($totals['crd_leads']) }}@if ($totals['crd_waiting'])<br><span style="font-size: 11px; {{ $muted }}">+{{ $number($totals['crd_waiting']) }}</span>@endif</span>
                        <span>{{ $number($totals['added'] + $totals['added_waiting']) }}</span><span>{{ $number($totals['all_lost']) }}</span><span>{{ $number($totals['all_reactivation']) }}</span><span>{{ $signed($totals['net']) }}</span><span>{{ $percent($totals['churn_rate']) }}</span>
                        <span>{{ $number($totals['retained']) }}</span><span>{{ $percent($totals['retained_rate']) }}</span><span>{{ $number($totals['repeat']) }}</span><span>{{ $percent($totals['repeat_rate']) }}</span><span>{{ $percent($totals['conversion_rate']) }}</span>
                        <span>{{ $number($totals['orders']) }}</span><span>{{ $peso($totals['aov']) }}</span><span>{{ $peso($totals['gross']) }}</span><span></span>
                    </div>
                </div>
            </section>

            <section id="details" style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 style="{{ $h2 }}">Daily and weekly customers and leads</h2>
                    <nav aria-label="Period" class="flex gap-0.5" style="border-radius: 8px; background: rgb(33 31 45 / .6); padding: 2px">
                        @foreach (\App\Services\PresentationBoard::PERIODS as $value => $label)
                            <a href="{{ route('presentation-board.index', [...$linkQuery, 'period' => $value]) }}#details" @if ($period === $value) aria-current="page" @endif
                               class="flex items-center" style="min-height: 40px; border-radius: 6px; padding: 0 14px; font-size: 14px; font-weight: 600; {{ $period === $value ? 'background: #c39bff; color: #211f2d' : 'color: #f1eef8' }}">{{ $label }}</a>
                        @endforeach
                    </nav>
                </header>
                <div class="flex flex-wrap">
                    @foreach ($teams as $team)
                        @php
                            $peak = max(1, ...array_map(fn (array $row) => max($row[$team['leads']] + $row[$team['wait']], $row[$team['bought']]), $periods));
                            $readId = 'board-read-'.$team['id'];
                            $defaultRead = 'Point at a bar for its numbers';
                            $columns = 'display: grid; grid-template-columns: 1.3fr repeat('.($team['added'] ? 7 : 6).', 1fr); gap: 4px; min-width: 470px; padding: 6px 6px; text-align: right';
                        @endphp
                        <div class="flex min-w-0 flex-col gap-2.5" style="flex: 1 1 520px; padding: 14px 16px">
                            <div class="flex flex-wrap items-center justify-between gap-2" style="font-size: 13px">
                                <h3 style="margin: 0; font-size: 15px; font-weight: 700">{{ $team['name'] }}</h3>
                                <span class="flex flex-wrap gap-3" style="{{ $muted }}">
                                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 3px; background: {{ $team['tint'] }}"></span>Leads, with waiting</span>
                                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 3px; background: {{ $team['color'] }}"></span>{{ $team['boughtName'] }}</span>
                                </span>
                            </div>
                            <div id="{{ $readId }}" data-read-default="{{ $defaultRead }}" style="min-height: 18px; font-size: 13px; font-weight: 600">{{ $defaultRead }}</div>
                            <div data-chart class="flex items-end justify-center" style="height: 120px; gap: {{ $period === 'daily' ? 0 : 1 }}px; {{ $axis }}">
                                @foreach ($periods as $index => $row)
                                    @php
                                        $read = $row['label'].': '.$number($row[$team['leads']]).' leads · '.$number($row[$team['wait']]).' waiting · '.$number($row[$team['bought']]).' '.strtolower($team['boughtName']).' · '.$number($row[$team['lost']]).' churned · '.$number($row[$team['reactivation']]).' for reactivation';
                                    @endphp
                                    <div data-point="{{ $team['id'] }}-{{ $index }}" data-read="{{ $read }}" data-read-target="{{ $readId }}" title="{{ $read }}" class="relative h-full min-w-0 flex-1" style="max-width: 24px">
                                        <span style="position: absolute; left: 0; right: 0; bottom: 0; height: {{ round(($row[$team['leads']] + $row[$team['wait']]) / $peak * 100) }}%; border-radius: 2px 2px 0 0; background: {{ $team['tint'] }}"></span>
                                        <span style="position: absolute; left: 0; right: 0; bottom: 0; height: {{ round($row[$team['bought']] / $peak * 100) }}%; background: {{ $team['color'] }}"></span>
                                    </div>
                                @endforeach
                            </div>
                            <div aria-hidden="true" class="flex justify-between" style="font-size: 12px; font-weight: 600; {{ $muted }}"><span>{{ $periods[0]['label'] }}</span><span>{{ $periods[array_key_last($periods)]['label'] }}</span></div>
                            <div class="overflow-auto" style="max-height: 300px; font-size: 13px; border-radius: 8px">
                                <div style="{{ $columns }}; position: sticky; top: 0; background: #36324b; font-weight: 600; color: #e4dff0">
                                    <span class="text-left">{{ ['daily' => 'Day', 'weekly' => 'Week', 'monthly' => 'Month'][$period] }}</span><span>Leads</span>
                                    @if ($team['added'])<span title="New customers">New</span>@endif
                                    <span title="What the rate is out of">Base</span><span>{{ $team['boughtName'] }}</span><span>Rate</span><span>Churned</span><span title="For reactivation">React.</span>
                                </div>
                                @foreach ($periods as $row)
                                    <div style="{{ $columns }}; {{ $rowLine }}">
                                        <span class="text-left" style="font-weight: 600">{{ $row['label'] }}</span><span>{{ $number($row[$team['leads']]) }}@if ($row[$team['wait']])<br><span style="font-size: 11px; {{ $muted }}">+{{ $number($row[$team['wait']]) }}</span>@endif</span>
                                        @if ($team['added'])<span>{{ $number($row['added']) }}</span>@endif
                                        <span>{{ $number($row[$team['base']]) }}</span><span>{{ $number($row[$team['bought']]) }}</span><span>{{ $percent($row[$team['rate']]) }}</span><span>{{ $number($row[$team['lost']]) }}</span><span>{{ $number($row[$team['reactivation']]) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section style="{{ $glass }}">
                <header style="{{ $head }}"><h2 style="{{ $h2 }}">How each number is counted</h2></header>
                <dl class="grid gap-x-4 gap-y-2" style="margin: 0; padding: 12px 16px 16px; grid-template-columns: minmax(110px, 170px) minmax(0, 1fr); font-size: 14px">
                    @foreach ([
                        'FSD and CRD leads' => 'Est. out-of-stock date in the month, on CRD-catered products and pages in Settings → Pancake Pages. 1 customer = 1 lead per month, on their first out-of-stock day. CRD: sold by a CRD team account. FSD: not yet handled by a CRA by that date. A customer in both in one month counts under CRD. POS orders are included. Totals add the months up. Deliveries before Apr 5, 2026 have no saved quantity, so theirs come from the order items, which can read low: the month those customers fall in is approximate.',
                        'Waiting for page check' => 'Older orders were saved without their Pancake page. They stay out of the totals until the backfill confirms the page; orders on pages not in the list are then dropped.',
                        'Added' => "A customer's first delivery on record, from FSD, on a CRD-catered product. Delivery records start June 2025, so an older customer returning after that counts as new.",
                        'Churned and churn rate' => "Same as Customer Churn and the Dashboard's Churn tile: a CRA reached out at least once on the customer's lead (a contact date, a status a CRA set, or a logged attempt), with no order or delivery since, and the lead isn't marked as bought or Reserve. Churn rate = churned ÷ customers due, counted in the month their ".config('customers.churn_grace_days')."-day window ended. Overall adds up churned and due across complete months (July on), so a big month weighs more than a small one. A customer due in two months counts in each.",
                        'For reactivation' => 'Everyone else due: out of stock, '.config('customers.churn_grace_days').' days passed, no order in that time, and not churned, mostly customers no CRA has reached yet. Not part of the churn rate. Covers all pages.',
                        'Retained and Repeat' => "Same as each month's Dashboard tile, each out of its own rate base. Before October: Shecom deliveries in the month with a later CRA order. From October: Repeat Purchase = Yes in the Segmentation Tracker, out of the tracker's leads.",
                        'Conversion' => 'Same as the Dashboard, buyers ÷ (engagements + tracker leads). From October only; earlier months have no tracker purchases.',
                        'Sales and AOV' => "Every CRA's gross sales, as Conversion Breakdown: Shecom's amount per order, Pancake's until synced, canceled included and deleted left out. Before Jul 9 every CRA order counts, since tags began then. Orders = confirmed orders. AOV = gross ÷ orders.",
                        'Top products' => "The same orders split over their products by qty × SRP. Items not on Settings → Product Consumption count under Others.",
                    ] as $name => $how)
                        <dt style="font-weight: 700">{{ $name }}</dt>
                        <dd style="margin: 0; {{ $muted }}">{{ $how }}</dd>
                    @endforeach
                </dl>
            </section>
        @endif
    </div>
</x-layouts.app>
