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
    $h2 = 'margin: 0; font-size: 16px; font-weight: 700';
    $muted = 'color: #bdb6cf';
    $note = 'margin: 0; font-size: 13px; line-height: 1.5; color: #bdb6cf';
    $fsd = '#2a9fc9';
    $fsdTint = 'rgb(42 159 201 / .35)';
    $crd = '#a05aff';
    $crdTint = 'rgb(160 90 255 / .38)';
    $added = '#3fd1b9';
    $addedTint = 'rgb(63 209 185 / .4)';
    $lost = '#ff9a9d';
    $reactivation = 'rgb(255 255 255 / .28)';
    $accent = '#c39bff';
    $axis = 'border-bottom: 1px solid rgb(255 255 255 / .3)';
    $rowLine = 'border-bottom: 1px solid rgb(255 255 255 / .08)';
    $swatch = 'width: 10px; height: 10px; border-radius: 3px';
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
                $link = 'color: inherit; text-decoration: underline; text-decoration-style: dotted; text-decoration-color: rgb(255 255 255 / .45); text-underline-offset: 3px';
                $listUrl = fn (string $metric, ?string $month = null) => route('presentation-board.customers', ['metric' => $metric, 'from' => $month ?? $from, 'to' => $month ?? $to]);
                $dates = fn (?string $month = null) => [
                    'from' => ($month ?? $from).'-01',
                    'to' => \Carbon\CarbonImmutable::parse(($month ?? $to).'-01')->endOfMonth()->min(\Carbon\CarbonImmutable::parse($snapshot['today']))->toDateString(),
                ];
                $churnUrl = fn (string $status, string $list, ?string $month = null) => route('customers.churn', [...$dates($month), 'status' => $status, 'list' => $list]);
                // The cards' rates cover complete months only, so their lists do too.
                $churnFrom = collect($months)->firstWhere('churn_partial', false)['key'] ?? $from;
                $cardUrl = fn (string $status, string $list) => route('customers.churn', ['from' => $churnFrom.'-01', 'to' => $dates()['to'], 'status' => $status, 'list' => $list]);
                $ordersUrl = fn (?string $month = null) => route('conversion.orders', $dates($month));
                $col = fn (string $key) => array_column($months, $key);
                $lastKey = $months[array_key_last($months)]['key'];
                $churnPeak = max(1, ...array_map(fn (array $month) => max($month['added'] + $month['added_waiting'], $month['all_lost'] + $month['all_reactivation']), $months));
                $salesPeak = max(1, ...$col('gross'));
                $trackerAt = collect($months)->search(fn (array $month) => $month['source'] === 'tracker');
                $hasPartial = $totals['churn_partial'];
                $star = fn (array $month) => $month['churn_partial'] ? '*' : '';
                $leadPanels = [
                    ['name' => 'FSD leads', 'key' => 'fsd_leads', 'wait' => 'fsd_waiting', 'color' => $fsd, 'tint' => $fsdTint],
                    ['name' => 'CRD leads', 'key' => 'crd_leads', 'wait' => 'crd_waiting', 'color' => $crd, 'tint' => $crdTint],
                ];
                $spark = function (array $values, string $color) {
                    $peak = max(1, ...array_map('abs', $values));
                    $last = array_key_last($values);

                    return collect($values)->map(fn ($value, $key) => ['h' => max(6, round(abs($value) / $peak * 100)), 'bg' => $key === $last ? $color : 'rgb(255 255 255 / .28)'])->all();
                };
                $tiles = [
                    ['label' => 'FSD leads', 'dot' => $fsd, 'value' => $number($totals['fsd_leads']), 'sub' => '+'.$number($totals['fsd_waiting']).' waiting', 'spark' => $spark($col('fsd_leads'), $fsd), 'url' => $listUrl('fsd_leads')],
                    ['label' => 'CRD leads', 'dot' => $crd, 'value' => $number($totals['crd_leads']), 'sub' => '+'.$number($totals['crd_waiting']).' waiting', 'spark' => $spark($col('crd_leads'), $crd), 'url' => $listUrl('crd_leads')],
                    ['label' => 'Added from FSD', 'dot' => $added, 'value' => $number($totals['added']), 'sub' => '+'.$number($totals['added_waiting']).' waiting', 'spark' => $spark($col('added'), $added), 'url' => $listUrl('added')],
                    ['label' => 'CRD repeat', 'dot' => $crd, 'value' => $number($totals['repeat']), 'sub' => $percent($totals['repeat_rate']).' of '.$number($totals['repeat_base']), 'spark' => $spark($col('repeat'), $crd), 'url' => $listUrl('repeat')],
                    ['label' => 'FSD retained', 'dot' => $fsd, 'value' => $number($totals['retained']), 'sub' => $percent($totals['retained_rate']).' of '.$number($totals['retained_base']), 'spark' => $spark($col('retained'), $fsd), 'url' => $listUrl('retained')],
                    ['label' => 'Conversion', 'dot' => $accent, 'value' => $percent($totals['conversion_rate']), 'sub' => 'From October', 'spark' => $spark(array_map(fn ($rate) => $rate ?? 0, $col('conversion_rate')), $accent)],
                ];
                $shecomShare = $totals['gross'] ? $totals['shecom_gross'] / $totals['gross'] : null;
                $rateCards = [
                    ['label' => 'Conversion', 'color' => $accent, 'value' => $totals['conversion_rate'], 'sub' => $totals['conversion_base'] ? $number($totals['conversion_customers']).' of '.$number($totals['conversion_base']).' · Segmentation '.$percent($totals['conversion_tabs']['sc']).' · Broadcast '.$percent($totals['conversion_tabs']['bc']) : 'No tracker data in these months'],
                    ['label' => 'Retain (FSD)', 'color' => $fsd, 'value' => $totals['retained_rate'], 'sub' => $number($totals['retained']).' of '.$number($totals['retained_base']).' FSD customers bought again from a CRA'],
                    ['label' => 'Repeat (CRD)', 'color' => $crd, 'value' => $totals['repeat_rate'], 'sub' => $number($totals['repeat']).' of '.$number($totals['repeat_base']).' CRD customers bought again'],
                ];
                $teams = [
                    ['name' => 'FSD leads', 'leads' => 'fsd_leads', 'wait' => 'fsd_waiting', 'bought' => 'retained', 'base' => 'retained_base', 'rate' => 'retained_rate', 'lost' => 'fsd_lost', 'churn' => 'fsd_churn_rate', 'boughtName' => 'Retained', 'color' => $fsd, 'tint' => $fsdTint, 'added' => true, 'id' => 'fsd'],
                    ['name' => 'CRD leads', 'leads' => 'crd_leads', 'wait' => 'crd_waiting', 'bought' => 'repeat', 'base' => 'repeat_base', 'rate' => 'repeat_rate', 'lost' => 'crd_lost', 'churn' => 'crd_churn_rate', 'boughtName' => 'Repeat', 'color' => $crd, 'tint' => $crdTint, 'added' => false, 'id' => 'crd'],
                ];
            @endphp

            <header class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 style="margin: 0; font-size: 28px; font-weight: 700">Presentation Board</h1>
                        <span style="border-radius: 999px; background: rgb(195 155 255 / .2); color: #e4d3ff; padding: 3px 10px; font-size: 12px; font-weight: 600">{{ $monthOptions[$from] }}@if ($from !== $to) – {{ $monthOptions[$to] }}@endif</span>
                    </div>
                    <p style="margin: 0; font-size: 14px; {{ $muted }}">The CRD team's year so far, and what the numbers mean.</p>
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

            <section aria-label="Data status" class="flex flex-wrap items-center gap-x-5 gap-y-2" style="{{ $glass }}; padding: 10px 16px; font-size: 13px">
                <span class="flex items-center gap-2" style="font-weight: 600"><span aria-hidden="true" style="width: 9px; height: 9px; border-radius: 50%; background: {{ $added }}"></span>Updated {{ \Carbon\CarbonImmutable::parse($snapshot['built_at'])->timezone(config('segmentation.timezone'))->format('M j, g:i A') }}</span>
                @unless ($progress['complete'])
                    <span style="font-weight: 700">Backfill ongoing: {{ number_format($progress['done']) }} of {{ number_format($progress['total']) }} page-months loaded.</span>
                    <span>Older months may still go up or down until it finishes.</span>
                    <span aria-hidden="true" class="min-w-20 flex-1" style="height: 8px; border-radius: 999px; background: rgb(255 255 255 / .12)"><span style="display: block; width: {{ $progress['total'] ? round($progress['done'] / $progress['total'] * 100) : 0 }}%; height: 8px; border-radius: 999px; background: {{ $accent }}"></span></span>
                @endunless
            </section>

            <section aria-label="Totals" class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr))">
                <div class="flex flex-col gap-1.5 sm:col-span-2" style="{{ $hero }}; padding: 16px">
                    <span style="font-size: 13px; font-weight: 600; color: {{ $accent }}">Overall total sales, every CRA</span>
                    <a href="{{ $ordersUrl() }}" title="See these orders" style="font-size: 44px; line-height: 1.1; font-weight: 700; letter-spacing: -1px; font-variant-numeric: normal; {{ $link }}">{{ $peso($totals['gross']) }}</a>
                    <span style="font-size: 13px; {{ $muted }}">{{ $number($totals['orders']) }} orders · AOV {{ $peso($totals['aov']) }}</span>
                    <span aria-hidden="true" class="flex items-end gap-0.5" style="height: 28px">
                        @foreach ($spark($col('gross'), $accent) as $bar)
                            <span style="flex: 1; max-width: 24px; height: {{ $bar['h'] }}%; border-radius: 2px 2px 0 0; background: {{ $bar['bg'] }}"></span>
                        @endforeach
                    </span>
                </div>
                @foreach ($tiles as $tile)
                    <div class="flex flex-col gap-1" style="{{ $glass }}; padding: 14px 16px">
                        <span class="flex items-center gap-1.5" style="font-size: 13px; font-weight: 600; {{ $muted }}"><span aria-hidden="true" style="width: 9px; height: 9px; border-radius: 50%; background: {{ $tile['dot'] }}"></span>{{ $tile['label'] }}</span>
                        @if ($tile['url'] ?? null)
                            <a href="{{ $tile['url'] }}" title="See these customers" style="font-size: 28px; line-height: 1.1; font-weight: 700; letter-spacing: -.5px; font-variant-numeric: normal; {{ $link }}">{{ $tile['value'] }}</a>
                        @else
                            <span style="font-size: 28px; line-height: 1.1; font-weight: 700; letter-spacing: -.5px; font-variant-numeric: normal">{{ $tile['value'] }}</span>
                        @endif
                        <span style="font-size: 13px; {{ $muted }}">{{ $tile['sub'] }}</span>
                        <span aria-hidden="true" class="flex items-end gap-0.5" style="height: 24px">
                            @foreach ($tile['spark'] as $bar)
                                <span style="flex: 1; max-width: 18px; height: {{ $bar['h'] }}%; border-radius: 2px 2px 0 0; background: {{ $bar['bg'] }}"></span>
                            @endforeach
                        </span>
                    </div>
                @endforeach
            </section>

            @if ($insights)
                <section aria-labelledby="short-title" style="{{ $glass }}; padding: 12px 16px">
                    <h2 id="short-title" style="margin: 0 0 6px; font-size: 13px; font-weight: 700; color: {{ $accent }}">In short <span style="font-weight: 500; {{ $muted }}">· click a dotted number to see the customers behind it</span></h2>
                    <ul class="grid gap-x-6 gap-y-1" style="margin: 0; padding: 0; list-style: none; font-size: 14px; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr))">
                        @foreach ($insights as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section id="rates" aria-labelledby="rates-title" style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 id="rates-title" style="{{ $h2 }}">Conversion, retain and repeat by month</h2>
                    <span id="board-read-rates" data-read-default="Point at a month for its rates" style="font-size: 13px; {{ $muted }}">Point at a month for its rates</span>
                </header>
                <div class="flex flex-wrap gap-5" style="padding: 16px">
                    <div class="flex flex-col gap-4" style="flex: 1 1 280px">
                        @foreach ($rateCards as $card)
                            <div class="flex flex-col gap-1">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="flex items-center gap-2" style="font-size: 14px; font-weight: 600"><span aria-hidden="true" style="width: 14px; height: 3px; border-radius: 2px; background: {{ $card['color'] }}"></span>{{ $card['label'] }}</span>
                                    <span style="font-size: 26px; font-weight: 700; font-variant-numeric: normal">{{ $percent($card['value']) }}</span>
                                </div>
                                <span style="font-size: 13px; {{ $muted }}">{{ $card['sub'] }}</span>
                            </div>
                        @endforeach
                        <p style="{{ $note }}">Each month matches its Dashboard tiles.</p>
                    </div>
                    <div class="min-w-0" style="flex: 999 1 560px">
                        <x-presentation-board.line-chart
                            :months="$months" read-target="board-read-rates"
                            :split="$trackerAt ? ['at' => $trackerAt, 'before' => 'Shecom deliveries', 'after' => 'Tracker'] : null"
                            :series="[['key' => 'conversion_rate', 'color' => $accent, 'name' => 'Conversion'], ['key' => 'retained_rate', 'color' => $fsd, 'name' => 'Retain'], ['key' => 'repeat_rate', 'color' => $crd, 'name' => 'Repeat']]"
                            :read="fn (array $month) => $month['label'].': conversion '.($month['conversion_rate'] === null ? 'no tracker data' : $percent($month['conversion_rate'])).' · retain '.$percent($month['retained_rate']).' · repeat '.$percent($month['repeat_rate'])" />
                    </div>
                </div>
            </section>

            <section id="customers" aria-labelledby="customers-title" style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 id="customers-title" style="{{ $h2 }}">Leads and customers</h2>
                    <span id="board-read-leads" data-read-default="1 customer = 1 lead per month" style="font-size: 13px; {{ $muted }}">1 customer = 1 lead per month</span>
                </header>
                <div class="flex flex-col gap-6" style="padding: 16px">
                    <div class="flex flex-wrap gap-5">
                        @foreach ($leadPanels as $panel)
                            @php
                                $peak = max(1, ...array_map(fn (array $month) => $month[$panel['key']] + $month[$panel['wait']], $months));
                                $best = collect($months)->sortByDesc(fn (array $month) => $month[$panel['key']] + $month[$panel['wait']])->first()['key'];
                            @endphp
                            <div class="min-w-0" style="flex: 1 1 460px">
                                <div class="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1" style="font-size: 13px">
                                    <a href="{{ $listUrl($panel['key']) }}" class="flex items-center gap-1.5" style="font-size: 15px; font-weight: 700; {{ $link }}"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $panel['color'] }}"></span>{{ $panel['name'] }} {{ $number($totals[$panel['key']]) }}</a>
                                    @if ($totals[$panel['wait']])
                                        <a href="{{ $listUrl($panel['wait']) }}" class="flex items-center gap-1.5" style="{{ $muted }}; {{ $link }}"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $panel['tint'] }}"></span>{{ $number($totals[$panel['wait']]) }} waiting for page check</a>
                                    @endif
                                </div>
                                <div data-chart class="flex items-end gap-1" style="height: 160px; {{ $axis }}">
                                    @foreach ($months as $month)
                                        @php
                                            $read = $month['label'].': FSD '.$number($month['fsd_leads']).' · CRD '.$number($month['crd_leads']).' · waiting '.$number($month['fsd_waiting'] + $month['crd_waiting']);
                                        @endphp
                                        <a href="{{ $listUrl($panel['key'], $month['key']) }}" data-point="{{ $month['key'] }}" data-read="{{ $read }}" data-read-target="board-read-leads" title="{{ $month['label'] }} {{ $panel['name'] }}: {{ $number($month[$panel['key']]) }}, plus {{ $number($month[$panel['wait']]) }} waiting. Click for the customers." class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-0.5" style="background: transparent; border-radius: 6px; color: inherit">
                                            <span style="font-size: 12px; font-weight: 700; white-space: nowrap">{{ $month['key'] === $best || $month['key'] === $lastKey ? $number($month[$panel['key']] + $month[$panel['wait']]) : '' }}</span>
                                            <span style="display: block; width: 100%; max-width: 24px; height: {{ round($month[$panel['wait']] / $peak * 84) }}%; border-radius: 4px 4px 0 0; background: {{ $panel['tint'] }}"></span>
                                            <span style="display: block; width: 100%; max-width: 24px; height: {{ round($month[$panel['key']] / $peak * 84) }}%; background: {{ $panel['color'] }}"></span>
                                        </a>
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
                </div>
            </section>

            <div id="sales" class="flex flex-wrap items-stretch gap-4">
                <section aria-labelledby="sales-title" class="min-w-0" style="flex: 1 1 520px; {{ $glass }}">
                    <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                        <h2 id="sales-title" style="{{ $h2 }}">Total sales by month</h2>
                        <span id="board-read-sales" data-read-default="{{ $number($totals['orders']) }} orders · AOV {{ $peso($totals['aov']) }}" style="font-size: 13px; {{ $muted }}">{{ $number($totals['orders']) }} orders · AOV {{ $peso($totals['aov']) }}</span>
                    </header>
                    <div class="flex flex-col gap-1.5" style="padding: 16px">
                        <div data-chart class="flex items-end gap-1" style="height: 380px; {{ $axis }}">
                            @foreach ($months as $month)
                                @php
                                    $read = $month['label'].': '.$peso($month['gross']).' · '.$number($month['orders']).' orders · AOV '.$peso($month['aov']);
                                @endphp
                                <a href="{{ $ordersUrl($month['key']) }}" data-point="{{ $month['key'] }}" data-read="{{ $read }}" data-read-target="board-read-sales" title="{{ $read }}. Click for the orders." class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-0.5" style="background: transparent; border-radius: 6px; color: inherit">
                                    <span style="font-size: 12px; font-weight: 700; white-space: nowrap">{{ $month['gross'] == $salesPeak || $month['key'] === $lastKey ? $millions($month['gross']) : '' }}</span>
                                    <span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['gross'] / $salesPeak * 84) }}%; border-radius: 4px 4px 0 0; background: {{ $crd }}"></span>
                                </a>
                            @endforeach
                        </div>
                        <div aria-hidden="true" class="flex gap-1 pt-1" style="font-size: 12px; font-weight: 600; {{ $muted }}">
                            @foreach ($months as $month)
                                <span class="flex-1 text-center">{{ $month['label'] }}</span>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section aria-labelledby="products-title" class="min-w-0" style="flex: 1 1 520px; {{ $glass }}">
                    <header style="{{ $head }}"><h2 id="products-title" style="{{ $h2 }}">Top products</h2></header>
                    <div class="overflow-x-auto" style="padding: 10px 16px 14px; font-size: 14px">
                        @php
                            $productPeak = max(1, collect($products)->reject(fn (array $product) => $product['name'] === \App\Services\PresentationBoard::OTHERS)->max('sales') ?? 1);
                            $productColumns = 'display: grid; grid-template-columns: minmax(150px, 1.6fr) 1fr 54px 64px 54px; gap: 10px; min-width: 470px; text-align: right';
                        @endphp
                        <div style="{{ $productColumns }}; padding-bottom: 6px; border-bottom: 1px solid rgb(255 255 255 / .14); font-size: 13px; font-weight: 600; {{ $muted }}">
                            <span class="text-left">Product</span><span>Sales</span><span>Share</span><span>Orders</span><span>Qty</span>
                        </div>
                        <div style="max-height: 400px; overflow-y: auto; min-width: 470px">
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

            <section id="months" aria-labelledby="months-title" style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 id="months-title" style="{{ $h2 }}">Month by month</h2>
                    @if ($totals['fsd_waiting'] || $totals['crd_waiting'])
                        <span style="font-size: 13px; {{ $muted }}">+ = waiting for page check, not in the lead count</span>
                    @endif
                </header>
                <div class="overflow-x-auto">
                    @php
                        $ledger = 'display: grid; grid-template-columns: 44px repeat(10, 1fr) .8fr .8fr 1.5fr 70px; gap: 6px; min-width: 1040px; padding-inline: 12px; text-align: right; font-size: 13px';
                    @endphp
                    <div style="{{ $ledger }}; padding-top: 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; {{ $muted }}">
                        <span></span>
                        <span style="grid-column: span 2; text-align: left; border-bottom: 1px solid rgb(255 255 255 / .3); padding-bottom: 3px">Leads</span>
                        <span style="grid-column: span 3; text-align: left; border-bottom: 1px solid rgb(255 255 255 / .3); padding-bottom: 3px">Customers</span>
                        <span style="grid-column: span 5; text-align: left; border-bottom: 1px solid rgb(255 255 255 / .3); padding-bottom: 3px">Rates</span>
                        <span style="grid-column: span 3; text-align: left; border-bottom: 1px solid rgb(255 255 255 / .3); padding-bottom: 3px">Sales</span>
                        <span></span>
                    </div>
                    <div style="{{ $ledger }}; padding-block: 7px; border-bottom: 1px solid rgb(255 255 255 / .14); font-weight: 600; {{ $muted }}">
                        <span class="text-left">Month</span><span>FSD</span><span>CRD</span><span>Added</span><span>Churned</span><span>Net</span><span>Retained</span><span>Retain %</span><span>Repeat</span><span>Repeat %</span><span>Conv. %</span><span>Orders</span><span>AOV</span><span>Gross</span><span class="text-left" title="Where Retained and Repeat come from">Source</span>
                    </div>
                    @foreach ($months as $month)
                        <div style="{{ $ledger }}; padding-block: 7px; {{ $rowLine }}">
                            <span class="text-left" style="font-weight: 700">{{ $month['label'] }}</span>
                            <span><a href="{{ $listUrl('fsd_leads', $month['key']) }}" style="{{ $link }}">{{ $number($month['fsd_leads']) }}</a>@if ($month['fsd_waiting'])<br><a href="{{ $listUrl('fsd_waiting', $month['key']) }}" style="font-size: 11px; {{ $muted }}; {{ $link }}">+{{ $number($month['fsd_waiting']) }}</a>@endif</span>
                            <span><a href="{{ $listUrl('crd_leads', $month['key']) }}" style="{{ $link }}">{{ $number($month['crd_leads']) }}</a>@if ($month['crd_waiting'])<br><a href="{{ $listUrl('crd_waiting', $month['key']) }}" style="font-size: 11px; {{ $muted }}; {{ $link }}">+{{ $number($month['crd_waiting']) }}</a>@endif</span>
                            <span><a href="{{ $listUrl('added', $month['key']) }}" style="{{ $link }}">{{ $number($month['added']) }}</a>@if ($month['added_waiting'])<br><a href="{{ $listUrl('added_waiting', $month['key']) }}" style="font-size: 11px; {{ $muted }}; {{ $link }}">+{{ $number($month['added_waiting']) }}</a>@endif</span><span><a href="{{ $churnUrl('lost', 'all', $month['key']) }}" style="{{ $link }}">{{ $number($month['all_lost']) }}</a></span><span style="font-weight: 700">{{ $signed($month['net']) }}</span>
                            <span><a href="{{ $listUrl('retained', $month['key']) }}" style="{{ $link }}">{{ $number($month['retained']) }}</a></span><span>{{ $percent($month['retained_rate']) }}</span><span><a href="{{ $listUrl('repeat', $month['key']) }}" style="{{ $link }}">{{ $number($month['repeat']) }}</a></span><span>{{ $percent($month['repeat_rate']) }}</span><span>{{ $percent($month['conversion_rate']) }}</span>
                            <span><a href="{{ $ordersUrl($month['key']) }}" style="{{ $link }}">{{ $number($month['orders']) }}</a></span><span>{{ $peso($month['aov']) }}</span><span style="font-weight: 600"><a href="{{ $ordersUrl($month['key']) }}" style="{{ $link }}">{{ $peso($month['gross']) }}</a></span>
                            <span class="text-left"><span style="border-radius: 999px; padding: 2px 6px; font-size: 11px; font-weight: 600; background: {{ $month['source'] === 'tracker' ? 'rgb(63 209 185 / .22)' : 'rgb(42 159 201 / .28)' }}">{{ $month['source'] === 'tracker' ? 'Tracker' : 'Deliveries' }}</span></span>
                        </div>
                    @endforeach
                    <div style="{{ $ledger }}; padding-block: 10px; font-weight: 700; background: rgb(70 58 108 / .5); border-radius: 0 0 16px 16px">
                        <span class="text-left">Total</span>
                        <span>{{ $number($totals['fsd_leads']) }}@if ($totals['fsd_waiting'])<br><span style="font-size: 11px; {{ $muted }}">+{{ $number($totals['fsd_waiting']) }}</span>@endif</span>
                        <span>{{ $number($totals['crd_leads']) }}@if ($totals['crd_waiting'])<br><span style="font-size: 11px; {{ $muted }}">+{{ $number($totals['crd_waiting']) }}</span>@endif</span>
                        <span>{{ $number($totals['added'] + $totals['added_waiting']) }}</span><span>{{ $number($totals['all_lost']) }}</span><span>{{ $signed($totals['net']) }}</span>
                        <span>{{ $number($totals['retained']) }}</span><span>{{ $percent($totals['retained_rate']) }}</span><span>{{ $number($totals['repeat']) }}</span><span>{{ $percent($totals['repeat_rate']) }}</span><span>{{ $percent($totals['conversion_rate']) }}</span>
                        <span>{{ $number($totals['orders']) }}</span><span>{{ $peso($totals['aov']) }}</span><span>{{ $peso($totals['gross']) }}</span><span></span>
                    </div>
                </div>
            </section>

            <section id="details" style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 style="{{ $h2 }}">Daily and weekly, FSD and CRD</h2>
                <nav aria-label="Period" class="flex w-fit gap-0.5" style="border-radius: 8px; background: rgb(33 31 45 / .6); padding: 2px">
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
                            $columns = 'display: grid; grid-template-columns: 1.3fr repeat('.($team['added'] ? 7 : 6).', 1fr); gap: 4px; min-width: 470px; padding: 6px; text-align: right';
                        @endphp
                        <div class="flex min-w-0 flex-col gap-2.5" style="flex: 1 1 520px; padding: 14px 16px">
                            <div class="flex flex-wrap items-center justify-between gap-2" style="font-size: 13px">
                                <h3 style="margin: 0; font-size: 15px; font-weight: 700">{{ $team['name'] }}</h3>
                                <span class="flex flex-wrap gap-3" style="{{ $muted }}">
                                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $team['tint'] }}"></span>Leads, with waiting</span>
                                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $team['color'] }}"></span>{{ $team['boughtName'] }}</span>
                                </span>
                            </div>
                            <div id="{{ $readId }}" data-read-default="{{ $defaultRead }}" style="min-height: 18px; font-size: 13px; font-weight: 600">{{ $defaultRead }}</div>
                            <div data-chart class="flex items-end justify-center" style="height: 120px; gap: {{ $period === 'daily' ? 0 : 1 }}px; {{ $axis }}">
                                @foreach ($periods as $index => $row)
                                    @php
                                        $read = $row['label'].': '.$number($row[$team['leads']]).' leads · '.$number($row[$team['wait']]).' waiting · '.$number($row[$team['bought']]).' '.strtolower($team['boughtName']).' · '.$number($row[$team['lost']]).' churned';
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
                                    <span title="What the rate is out of">Base</span><span>{{ $team['boughtName'] }}</span><span>Rate</span><span>Churned</span><span>Churn %</span>
                                </div>
                                @foreach ($periods as $row)
                                    <div style="{{ $columns }}; {{ $rowLine }}">
                                        <span class="text-left" style="font-weight: 600">{{ $row['label'] }}</span><span>{{ $number($row[$team['leads']]) }}@if ($row[$team['wait']])<br><span style="font-size: 11px; {{ $muted }}">+{{ $number($row[$team['wait']]) }}</span>@endif</span>
                                        @if ($team['added'])<span>{{ $number($row['added']) }}</span>@endif
                                        <span>{{ $number($row[$team['base']]) }}</span><span>{{ $number($row[$team['bought']]) }}</span><span>{{ $percent($row[$team['rate']]) }}</span><span>{{ $number($row[$team['lost']]) }}</span><span>{{ $percent($row[$team['churn']]) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section id="churn" aria-labelledby="churn-title" style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 id="churn-title" style="{{ $h2 }}">Acquisition vs churn</h2>
                    <ul class="flex flex-wrap gap-3.5" style="margin: 0; padding: 0; list-style: none; font-size: 13px">
                                <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $added }}"></span>Added {{ $number($totals['added']) }}</li>
                                @if ($totals['added_waiting'])
                                    <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $addedTint }}"></span>Added, waiting {{ $number($totals['added_waiting']) }}</li>
                                @endif
                                <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $lost }}"></span>Churned {{ $number($totals['all_lost']) }}</li>
                                <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $reactivation }}"></span>For reactivation {{ $number($totals['all_reactivation']) }}</li>
                                <li style="font-weight: 700">Net {{ $signed($totals['net']) }}</li>
                            </ul>
                </header>
                <div class="flex flex-col gap-4" style="padding: 16px">
                    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr))">
                        @foreach (['all', 'fsd', 'crd'] as $list)
                            @php
                                $figures = $totals['churn_lists'][$list];
                            @endphp
                            <div class="flex flex-col gap-1.5" style="border-radius: 14px; background: rgb(33 31 45 / .45); border: 1px solid rgb(255 255 255 / .08); padding: 12px 14px">
                                <div class="flex items-baseline justify-between gap-3">
                                    <h3 style="margin: 0; font-size: 14px; font-weight: 600">{{ $figures['name'] }} churn</h3>
                                    <span style="font-size: 26px; line-height: 1; font-weight: 700; font-variant-numeric: normal">{{ $percent($figures['rate']) }}</span>
                                </div>
                                <span aria-hidden="true" style="display: block; height: 8px; border-radius: 999px; background: rgb(255 255 255 / .12)"><span style="display: block; width: {{ $figures['due'] ? min(100, round($figures['lost'] / $figures['due'] * 100, 1)) : 0 }}%; height: 8px; border-radius: 999px; background: {{ $lost }}"></span></span>
                                <span style="font-size: 13px; {{ $muted }}"><a href="{{ $cardUrl('lost', $list) }}" style="{{ $link }}">{{ $number($figures['lost']) }}</a> of <a href="{{ $cardUrl('due', $list) }}" style="{{ $link }}">{{ $number($figures['due']) }}</a> due · <a href="{{ $cardUrl('back', $list) }}" style="{{ $link }}">{{ $number($figures['back']) }}</a> came back · <a href="{{ $cardUrl('reactivation', $list) }}" style="{{ $link }}">{{ $number($figures['reactivation']) }}</a> for reactivation</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="overflow-x-auto">
                            <div data-chart class="flex gap-1" style="min-width: 760px; font-size: 13px">
                                <div style="width: 90px; flex: none; font-weight: 600; {{ $muted }}">
                                    <div style="height: 222px"></div>
                                    @foreach (['Month', 'Added', 'Churned', 'Reactivation', 'Net', 'Churn %', 'FSD %', 'CRD %'] as $label)
                                        <div style="height: 24px; line-height: 24px">{{ $label }}</div>
                                    @endforeach
                                </div>
                                @foreach ($months as $month)
                                    <div data-point="{{ $month['key'] }}" title="{{ $month['label'] }}: added {{ $number($month['added']) }}, plus {{ $number($month['added_waiting']) }} waiting, {{ $number($month['all_lost']) }} churned and {{ $number($month['all_reactivation']) }} for reactivation of {{ $number($month['all_due']) }} due" class="min-w-0 flex-1 text-center" style="background: transparent; border-radius: 6px">
                                        <span class="flex flex-col items-center justify-end gap-0.5" style="height: 120px"><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['added_waiting'] / $churnPeak * 100) }}%; border-radius: 4px 4px 0 0; background: {{ $addedTint }}"></span><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['added'] / $churnPeak * 100) }}%; background: {{ $added }}"></span></span>
                                        <span style="display: block; height: 2px; background: rgb(255 255 255 / .35)"></span>
                                        <span class="flex flex-col items-center justify-start gap-0.5" style="height: 100px"><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['all_lost'] / $churnPeak * 100) }}%; background: {{ $lost }}"></span><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['all_reactivation'] / $churnPeak * 100) }}%; border-radius: 0 0 4px 4px; background: {{ $reactivation }}"></span></span>
                                        <span style="display: block; height: 24px; line-height: 24px; font-weight: 700">{{ $month['label'] }}</span>
                                        <a href="{{ $listUrl('added', $month['key']) }}" style="display: block; height: 24px; line-height: 24px; {{ $link }}">{{ $number($month['added'] + $month['added_waiting']) }}</a>
                                        <a href="{{ $churnUrl('lost', 'all', $month['key']) }}" style="display: block; height: 24px; line-height: 24px; {{ $link }}">{{ $number($month['all_lost']) }}</a>
                                        <a href="{{ $churnUrl('reactivation', 'all', $month['key']) }}" style="display: block; height: 24px; line-height: 24px; {{ $link }}">{{ $number($month['all_reactivation']) }}{{ $star($month) }}</a>
                                        <span style="display: block; height: 24px; line-height: 24px; font-weight: 700">{{ $signed($month['net']) }}</span>
                                <span style="display: block; height: 24px; line-height: 24px; font-weight: 700">{{ $percent($month['churn_rate']) }}{{ $star($month) }}</span>
                                <a href="{{ $churnUrl('lost', 'fsd', $month['key']) }}" style="display: block; height: 24px; line-height: 24px; {{ $link }}">{{ $percent($month['fsd_churn_rate']) }}{{ $star($month) }}</a>
                                <a href="{{ $churnUrl('lost', 'crd', $month['key']) }}" style="display: block; height: 24px; line-height: 24px; {{ $link }}">{{ $percent($month['crd_churn_rate']) }}{{ $star($month) }}</a>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    <div class="flex flex-col gap-1">
                        <p style="{{ $note }}">Monthly numbers open exactly those customers. Card lists cover the whole range and show each customer once, so they can be shorter.</p>
                        <p style="{{ $note }}"><strong style="color: #f1eef8">Churned</strong>: a CRA reached them, 30 days passed, no order. <strong style="color: #f1eef8">For reactivation</strong>: everyone else due, mostly not reached yet, not in the rate.</p>
                        @if ($hasPartial)
                            <p style="{{ $note }}">* Partial: tracker leads start in June. Rates above use {{ $totals['churn_label'] }} only.</p>
                        @endif
                        @if ($totals['added_waiting'])
                            <p style="{{ $note }}">Added and Net rows include customers waiting for their page check.</p>
                        @endif
                    </div>
                </div>
            </section>

            <details id="counted" style="{{ $glass }}">
                <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 style="{{ $h2 }}">How each number is counted</h2>
                    <span style="font-size: 13px; {{ $muted }}">Click to show or hide</span>
                </summary>
                <dl class="grid gap-x-4 gap-y-2" style="margin: 0; padding: 12px 16px 16px; grid-template-columns: minmax(110px, 170px) minmax(0, 1fr); font-size: 14px">
                    @foreach ([
                        'Leads' => '1 customer = 1 lead per month, by est. out-of-stock date. CRD-catered products and listed pages only. In both teams: counts under CRD. Jan–Mar months are approximate (quantity from order items).',
                        'Waiting' => 'Page not confirmed yet. Not in the totals until the backfill checks it.',
                        'Added' => 'First delivery on record (from June 2025), from FSD.',
                        'Churned' => 'A CRA reached them (contact date, status or attempt), 30 days passed, no order since, not marked bought or Reserve.',
                        'Churn rate' => 'Churned ÷ due, for FSD, CRD and overall. Range totals, not an average of months. Overall counts a customer on both lists once.',
                        'For reactivation' => 'Everyone else due with no order in time. Not in the rate.',
                        'Retain, repeat, conversion' => "Same as each month's Dashboard tiles. Conversion from October only.",
                        'Sales' => "Every CRA's gross, as Conversion Breakdown. Shecom's amount per order, Pancake's until synced.",
                        'Top products' => 'The same orders split by qty × SRP. Unlisted items go under Others.',
                    ] as $name => $how)
                        <dt style="font-weight: 700">{{ $name }}</dt>
                        <dd style="margin: 0; {{ $muted }}">{{ $how }}</dd>
                    @endforeach
                </dl>
            </details>
        @endif
    </div>
</x-layouts.app>
