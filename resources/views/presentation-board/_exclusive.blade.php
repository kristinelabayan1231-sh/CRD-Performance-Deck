{{-- One exclusive CRA's leads, as the Exclusive Leads Dashboard counts them, a month at a time. Uses the board's helpers. --}}
@php
    $cra = $exclusiveCra['user'];
    $byMonth = $exclusiveCra['months'];
    $sourceLabels = \App\Http\Controllers\ExclusiveLeadController::SOURCE_LABELS;
    // Board months, null before the CRA's exclusive start.
    $per = fn (callable $value) => array_map(fn (array $month) => isset($byMonth[$month['key']]) ? $value($byMonth[$month['key']]) : null, $months);
    $sum = fn (callable $value) => array_sum(array_map($value, $byMonth));
    $bought = fn (array $entry) => $entry['figures']['actual']['repeat'] + $entry['figures']['actual']['retained'];
    $worked = fn (array $entry) => array_sum(array_column($entry['figures']['days'], 'worked'));
    $listedDays = fn (array $entry) => array_sum(array_column($entry['figures']['days'], 'listed'));
    $latestKey = array_key_last($byMonth);
    $latest = $byMonth[$latestKey];
    $latestLabel = \Carbon\CarbonImmutable::parse($latestKey.'-01')->format('M');
    $latestRunning = $isRunning && $latestKey === $lastKey;
    $leadsTotal = $sum(fn (array $entry) => $entry['figures']['actual']['unique_leads']);
    $boughtTotal = $sum($bought);
    $exclusiveGross = $sum(fn (array $entry) => $entry['sales']['exclusive']['gross']);
    $exclusiveOrders = $sum(fn (array $entry) => $entry['sales']['exclusive']['orders']);
    $exTiles = [
        ['Leads on her list', 'Leads still hers on the month\'s lists, each once. Target '.$number($latest['figures']['targets']['leads']).' a month.',
            $per(fn (array $entry) => $entry['figures']['actual']['unique_leads']), $compact, true, false, $number($leadsTotal), 'of '.$number($sum(fn (array $entry) => $entry['figures']['targets']['leads'])).' target'],
        ['Worked on the day', 'Leads processed while on that day\'s list ÷ leads listed.',
            $per(fn (array $entry) => $listedDays($entry) ? $worked($entry) / $listedDays($entry) : null), $percent, false, true,
            $percent($sum($listedDays) ? $sum($worked) / $sum($listedDays) : null), $number($sum($worked)).' of '.$number($sum($listedDays))],
        ['Bought (Repeat + Retained)', 'Customers on her lists who bought again. Goal '.$number($latest['figures']['targets']['repeat']).' this month ('.$latest['figures']['targets']['repeat_percent'].'%).',
            $per($bought), $compact, true, false, $number($boughtTotal), 'customers'],
        ['Bought ÷ leads', 'Repeat + Retained ÷ leads on her list.',
            $per(fn (array $entry) => $entry['figures']['actual']['unique_leads'] ? $bought($entry) / $entry['figures']['actual']['unique_leads'] : null), $percent, false, true,
            $percent($leadsTotal ? $boughtTotal / $leadsTotal : null), 'across the range'],
        ['Confirmed orders', 'From customers on her lists, POS sales included. Target '.$number($latest['figures']['targets']['confirmed_orders']).' this month.',
            $per(fn (array $entry) => $entry['figures']['actual']['confirmed']), $compact, true, false, $number($sum(fn (array $entry) => $entry['figures']['actual']['confirmed'])), 'orders'],
        ['Exclusive sales', 'Her sales to her exclusive leads (assigned or tagged in POS).',
            $per(fn (array $entry) => $entry['sales']['exclusive']['gross']), $millions, true, false, $peso($exclusiveGross), $number($exclusiveOrders).' orders · AOV '.$peso($exclusiveOrders ? $exclusiveGross / $exclusiveOrders : null)],
    ];
    // Her latest month against the team.
    $craRow = collect($craPerformance['rows'] ?? [])->first(fn (array $row) => $row['user']->id === $cra->id);
    $teamCapacities = collect($craPerformance['rows'] ?? [])->pluck('capacity')->filter(fn ($capacity) => $capacity !== null);
    $latestTeam = collect($months)->firstWhere('key', $latestKey);
    $herAov = $latest['sales']['exclusive']['orders'] ? $latest['sales']['exclusive']['gross'] / $latest['sales']['exclusive']['orders'] : null;
    $versusTeam = array_values(array_filter([
        ['Exclusive AOV · '.$latestLabel, $herAov, $latestTeam['aov'] ?? null, $peso, 'team AOV'],
        ['Capacity a day', $craRow['capacity'] ?? null, $teamCapacities->isNotEmpty() ? $teamCapacities->avg() : null, fn ($value) => $value === null ? '—' : number_format($value), 'team average'],
        ['Share of team gross', $craRow['share'] ?? null, ($craPerformance['rows'] ?? []) ? 1 / count($craPerformance['rows']) : null, $percent, 'an even share'],
    ], fn (array $row) => $row[1] !== null && $row[2] !== null));
    // The latest month's days: leads added to her list so far against the month's target, and buyers on a second scale.
    $days = $latest['figures']['days'];
    $dayCount = count($days);
    $target = max(1, $latest['figures']['targets']['leads']);
    $realToday = \App\Support\WorkingDate::realToday()->toDateString();
    $addedSoFar = 0;
    $boughtSoFar = 0;
    $leadPoints = ['0,200'];
    $boughtPoints = ['0,200'];
    $hits = [];
    $boughtPeak = max(1, (int) ceil(max($latest['figures']['targets']['repeat'], $bought($latest)) * 1.1));
    $dx = fn (int $day) => round($day / max(1, $dayCount) * 640, 1);
    $lastDay = 0;
    foreach ($days as $index => $day) {
        if ($day['date'] > $realToday) {
            break;
        }
        $addedSoFar += $day['new'];
        $boughtSoFar += $day['repeat'] + $day['retained'];
        $leadPoints[] = $dx($index + 1).','.round(200 - min($addedSoFar, $target * 1.2) / ($target * 1.2) * 180, 1);
        $boughtPoints[] = $dx($index + 1).','.round(200 - min($boughtSoFar, $boughtPeak) / $boughtPeak * 180, 1);
        $hits[] = [$dx($index), \Carbon\CarbonImmutable::parse($day['date'])->format('M j').': '.$number($day['new']).' added ('.$number($day['quota']).' quota) · running '.$number($added).' of '.$number($target).' · '.$number($day['repeat'] + $day['retained']).' bought'];
        $lastDay = $index + 1;
    }
    $ty = fn (float $leads) => round(200 - $leads / ($target * 1.2) * 180, 1);
    $mixParts = collect($latest['figures']['actual']['mix'])->filter()->map(fn (int $count, string $source) => [$sourceLabels[$source] ?? $source, $count, ['leftover' => 'var(--pb-bc)', 'tagged' => 'var(--pb-excl)', 'high_value' => 'var(--pb-crd)', 'handled' => 'var(--pb-added)', 'random' => 'var(--pb-fsd)'][$source] ?? 'var(--pb-react)', $number($count)])->values()->all();
    $splitPeak = max(1, ...array_map(fn (array $entry) => $entry['sales']['overall']['gross'], $byMonth));
@endphp

<section id="exclusive" aria-labelledby="exclusive-title-{{ $cra->id }}" style="{{ $glass }}">
    <header class="flex flex-wrap items-baseline justify-between gap-2" style="{{ $head }}">
        <h2 id="exclusive-title-{{ $cra->id }}" class="flex items-center gap-2" style="{{ $h2 }}"><span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 50%; background: {{ $exclusiveColor }}"></span>Exclusive leads · {{ $cra->displayName() }}</h2>
        <span style="font-size: 13px; {{ $muted }}">{{ $number($latest['figures']['targets']['leads']) }} leads a month, {{ $number($latest['figures']['limits']['daily_leads']) }} a day · as the Exclusive Leads Dashboard @can('exclusive_leads.view_all')· <a href="{{ route('exclusive-leads.dashboard', ['cra' => $cra->id]) }}" style="{{ $link }}">open it</a>@endcan</span>
    </header>
    <div class="flex flex-col gap-3.5" style="padding: 14px 16px 16px">
        @if (count($byMonth) < $monthCount)
            <p style="margin: 0; padding: 8px 12px; border-radius: 8px; background: var(--pb-chip); font-size: 12px; color: var(--pb-soft)">Exclusive leads started in {{ \App\Support\WorkingDate::realDayFor($cra->exclusive_from)->format('F Y') }}. Earlier months in the range show — here.</p>
        @endif

        @if ($versusTeam)
            <div class="grid gap-2.5" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
                @foreach ($versusTeam as [$label, $hers, $team, $format, $teamName])
                    @php
                        $scale = max($hers, $team) ?: 1;
                        $teamChip = $chip($hers, $team);
                    @endphp
                    <div class="flex flex-col gap-1.5" style="{{ $cell }}; padding: 12px 14px">
                        <span style="font-size: 12px; font-weight: 700; {{ $muted }}">{{ $label }}</span>
                        <span class="flex items-baseline gap-2"><span style="font-size: 22px; font-weight: 700">{{ $format($hers) }}</span><span style="font-size: 12px; {{ $muted }}">vs {{ $teamName }} {{ $format($team) }}</span></span>
                        <span aria-hidden="true" class="flex flex-col gap-1">
                            <span style="display: block; height: 7px; border-radius: 999px; width: {{ round($hers / $scale * 100, 1) }}%; background: {{ $exclusiveColor }}"></span>
                            <span style="display: block; height: 7px; border-radius: 999px; width: {{ round($team / $scale * 100, 1) }}%; background: var(--pb-react)"></span>
                        </span>
                        <span style="{{ $teamChip['style'] }}">{{ $teamChip['text'] }} vs {{ $teamName }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex flex-col gap-2" style="{{ $cell }}; padding: 14px">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 style="margin: 0; font-size: 14px; font-weight: 700">Leads added to her list · {{ $latestLabel }}{{ $latestRunning ? ' so far' : '' }}</h3>
                <span class="flex flex-wrap gap-3.5" style="font-size: 12px; color: var(--pb-soft)">
                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="width: 14px; height: 3px; border-radius: 2px; background: {{ $exclusiveColor }}"></span>Added, running total</span>
                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="width: 14px; border-top: 2px dashed var(--pb-muted)"></span>Target {{ $number($target) }}</span>
                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="width: 14px; height: 3px; border-radius: 2px; background: {{ $added }}"></span>Bought, running total (right scale)</span>
                </span>
            </div>
            <svg viewBox="0 0 640 210" role="img" aria-label="Line chart: leads added to {{ $cra->displayName() }}'s list each day of {{ $latestLabel }}, running total against the month's target, with buyers" style="display: block; width: 100%; height: auto; overflow: visible">
                @foreach ([0, .25, .5, .75, 1] as $share)
                    <line x1="0" x2="640" y1="{{ $ty($target * $share) }}" y2="{{ $ty($target * $share) }}" style="stroke: var(--pb-grid); stroke-width: 1"></line>
                    <text x="0" y="{{ $ty($target * $share) - 4 }}" style="fill: var(--pb-muted); font-size: 11px">{{ $compact($target * $share) }}</text>
                    <text x="640" y="{{ $ty($target * $share) - 4 }}" style="fill: {{ $added }}; font-size: 11px; text-anchor: end">{{ $share ? $number(round($boughtPeak * $share / 1.2)) : '' }}</text>
                @endforeach
                <polyline points="0,200 640,{{ $ty($target) }}" style="fill: none; stroke: var(--pb-muted); stroke-width: 2; stroke-dasharray: 6 5"></polyline>
                @if (count($leadPoints) > 1)
                    <path d="M{{ implode(' L', $leadPoints) }} L{{ $dx($lastDay) }},200 Z" style="fill: {{ $exclusiveColor }}; opacity: .14"></path>
                    <polyline points="{{ implode(' ', $leadPoints) }}" style="fill: none; stroke: {{ $exclusiveColor }}; stroke-width: 3; stroke-linejoin: round"></polyline>
                    <polyline points="{{ implode(' ', $boughtPoints) }}" style="fill: none; stroke: {{ $added }}; stroke-width: 2.5; stroke-linejoin: round"></polyline>
                @endif
                @if ($latestRunning && $lastDay)
                    <line x1="{{ $dx($lastDay) }}" x2="{{ $dx($lastDay) }}" y1="12" y2="200" style="stroke: var(--pb-line2); stroke-width: 1"></line>
                    <text x="{{ $dx($lastDay) }}" y="9" style="fill: var(--pb-muted); font-size: 11px; text-anchor: middle">today</text>
                @endif
                @foreach ($hits as [$x, $read])
                    <rect x="{{ $x }}" y="0" width="{{ round(640 / max(1, $dayCount), 1) }}" height="200" style="fill: transparent"><title>{{ $read }}</title></rect>
                @endforeach
            </svg>
            <div aria-hidden="true" class="flex justify-between" style="font-size: 11px; {{ $muted }}"><span>Day 1</span><span>Day {{ $dayCount }}</span></div>
            <p style="{{ $note }}; font-size: 12px">{{ $number($addedSoFar) }} added by day {{ $lastDay }}{{ $lastDay ? ' ('.$percent($addedSoFar / $target).' of the target)' : '' }} · {{ $number($latest['figures']['planned']['leads']) }} planned for the month{{ $latest['figures']['planned']['short_of_target'] ? ', '.$number($latest['figures']['planned']['short_of_target']).' short of the target' : '' }}. Hover a day for its numbers.</p>
        </div>

        <div class="grid gap-2.5" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr))">
            @foreach ($exTiles as [$label, $about, $values, $format, $flow, $isRate, $total, $totalNote])
                @php
                    $tileChip = $chip($flow ? $paced($values[$monthCount - 1] ?? null) : ($values[$monthCount - 1] ?? null), $monthCount > 1 ? $values[0] : null, $isRate);
                @endphp
                <div class="flex flex-col gap-2" style="{{ $cell }}; padding: 12px 14px">
                    <div class="flex flex-col gap-0.5">
                        <span style="font-size: 13px; font-weight: 700">{{ $label }}</span>
                        <span style="font-size: 12px; line-height: 1.4; {{ $muted }}">{{ $about }}</span>
                    </div>
                    <span class="flex flex-wrap items-baseline gap-2"><span style="font-size: 22px; line-height: 1.1; font-weight: 700">{{ $total }}</span><span style="font-size: 12px; {{ $muted }}">{{ $totalNote }}</span></span>
                    {!! $sparkline($values, $exclusiveColor, $latestRunning && $flow) !!}
                    {!! $valueRow($values, $format) !!}
                    <span style="{{ $tileChip['style'] }}">{{ $tileChip['text'] }} · {{ $versus }}</span>
                </div>
            @endforeach
        </div>

        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
            @if ($mixParts)
                <div class="flex flex-col gap-2.5" style="{{ $cell }}; padding: 14px">
                    <h3 style="margin: 0; font-size: 14px; font-weight: 700">Where {{ $latestLabel }}'s leads came from</h3>
                    <div class="flex flex-wrap items-center gap-4">
                        {!! $donut($mixParts, $number(array_sum(array_column($mixParts, 1))), 'leads listed', 'Donut: '.$latestLabel.' list by why each lead is on it') !!}
                        <ul class="flex flex-col gap-1.5" style="flex: 1 1 150px; margin: 0; padding: 0; list-style: none; font-size: 12px">
                            @foreach ($mixParts as [$name, $count, $color])
                                <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $color }}"></span><span style="flex: 1">{{ $name }}</span><strong>{{ $number($count) }}</strong></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
            <div class="flex flex-col gap-2.5" style="{{ $cell }}; padding: 14px">
                <h3 style="margin: 0; font-size: 14px; font-weight: 700">Her exclusive vs normal sales</h3>
                <span style="font-size: 12px; {{ $muted }}">Exclusive = her exclusive leads (assigned or tagged in POS). Normal = customers she caters on Pancake.</span>
                @foreach ($byMonth as $monthKey => $entry)
                    @php
                        $overall = $entry['sales']['overall']['gross'];
                        $exclusiveShare = $overall ? $entry['sales']['exclusive']['gross'] / $overall : 0;
                    @endphp
                    <div class="grid items-center gap-2" title="Exclusive {{ $peso($entry['sales']['exclusive']['gross']) }} ({{ $number($entry['sales']['exclusive']['orders']) }} orders) · normal {{ $peso($entry['sales']['normal']['gross']) }} ({{ $number($entry['sales']['normal']['orders']) }} orders)" style="grid-template-columns: 48px minmax(0, 1fr) 76px; font-size: 12px">
                        <span style="font-weight: 700; color: var(--pb-soft)">{{ \Carbon\CarbonImmutable::parse($monthKey.'-01')->format('M') }}</span>
                        <span class="flex overflow-hidden" style="height: 22px; width: {{ round($overall / $splitPeak * 100, 1) }}%; min-width: 24px; border-radius: 6px; background: var(--pb-react)">
                            <span class="flex items-center justify-center" style="width: {{ round($exclusiveShare * 100, 1) }}%; background: {{ $exclusiveColor }}; font-size: 11px; font-weight: 700; color: #fff">{{ $exclusiveShare >= .15 ? number_format($exclusiveShare * 100).'%' : '' }}</span>
                        </span>
                        <span style="text-align: right; font-weight: 700">{{ $millions($overall) }}</span>
                    </div>
                @endforeach
                <span class="flex flex-wrap gap-3.5" style="font-size: 12px; color: var(--pb-soft)">
                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $exclusiveColor }}"></span>Exclusive</span>
                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: var(--pb-react)"></span>Normal</span>
                </span>
            </div>
        </div>
    </div>
</section>
