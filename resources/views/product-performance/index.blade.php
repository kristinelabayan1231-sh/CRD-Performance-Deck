@php
    use App\Services\ProductPerformance;

    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $peso = fn (float $amount) => '₱'.number_format($amount);
    $previousLabel = \App\Support\OrderPeriod::label($previousFrom, $previousTo);
    $unitName = ['hour' => 'hour', 'day' => 'day', 'week' => 'week'][$unit];

    // One line per product on a 640 × 180 plot, all on the same scale so switching lines on and off keeps it.
    // The top five by sales show at first.
    $palette = ['#7429d6', '#e07a1f', '#1a8fb0', '#0e8f7c', '#c4484c', '#b5179e', '#3f5bd9', '#8a6d1f', '#5c6b2e', '#d15d9b'];
    $lines = $products->values()->map(fn (array $product, int $i) => [
        'name' => $product['product'],
        'series' => $product['series'],
        'color' => $palette[$i % count($palette)],
        'shown' => $i < 5,
    ]);
    $colors = $lines->pluck('color', 'name');
    $count = count($series);
    $max = max(1, $lines->flatMap(fn (array $line) => $line['series'])->max() ?? 0);
    $x = fn (int $i) => $count > 1 ? round($i / ($count - 1) * 640, 1) : 320;
    $y = fn (float $v) => round(170 - $v / $max * 150, 1);
    $points = fn (array $values) => collect($values)->map(fn (float $v, int $i) => $x($i).','.$y($v))->join(' ');
    $axis = collect([0, intdiv($count - 1, 2), $count - 1])->unique();
    $percent = fn (?float $rate) => $rate === null ? '—' : number_format($rate * 100, 1).'%';
    $goalLabel = rtrim(rtrim(number_format($retentionGoal * 100, 1), '0'), '.').'%';

    // The graph three ways on the same plot: gross sales, place by gross sales (#1 at the top) and retention (0–100%).
    // Each line is a list of paths, broken where a slot has no value (retention with nobody due).
    $places = max(2, $lines->count());
    $rankY = fn (int $place) => round(20 + ($place - 1) / ($places - 1) * 150, 1);
    $retentionY = fn (float $rate) => round(170 - $rate * 150, 1);
    $paths = fn (callable $point) => $products->values()->map(function (array $product) use ($point, $count) {
        $runs = [[]];
        for ($j = 0; $j < $count; $j++) {
            $value = $point($product, $j);
            if ($value === null) {
                $runs[] = [];

                continue;
            }
            $runs[array_key_last($runs)][] = $value;
        }

        return array_values(array_filter($runs));
    })->all();
    $graphs = [
        'sales' => [
            'label' => 'Gross sales', 'title' => "Gross sales per {$unitName}, by product",
            'note' => $peso($total['sales']).' · '.number_format($total['orders']).' '.Str::plural('order', $total['orders']).' · '.number_format($total['qty']).' pcs',
            'grid' => [[20, $peso($max)], [95, $peso($max / 2)], [170, '₱0']],
            'paths' => $paths(fn ($product, $j) => [$x($j), $y($product['series'][$j]), $labels[$j].': '.$peso($product['series'][$j])]),
        ],
        'rank' => [
            'label' => 'Rank', 'title' => "Rank per {$unitName}, by gross sales",
            'note' => '#1 is the top seller. A line going up means the product climbed.',
            'grid' => collect(range(1, $places))->map(fn ($place) => [$rankY($place), '#'.$place])->all(),
            'paths' => $paths(fn ($product, $j) => [$x($j), $rankY($product['rank_series'][$j]), $labels[$j].': #'.$product['rank_series'][$j]]),
        ],
    ];
    if ($unit !== 'hour') {
        $graphs['retention'] = [
            'label' => 'Retention', 'title' => "Retention rate per {$unitName}, by product",
            'note' => "Customers whose time to reorder ended that {$unitName} who came back in time · dashed line = {$goalLabel} goal",
            'grid' => [[20, '100%'], [57.5, '75%'], [95, '50%'], [132.5, '25%'], [170, '0%']],
            'paths' => $paths(fn ($product, $j) => $product['retention_series'][$j] === null ? null : [$x($j), $retentionY($product['retention_series'][$j]), $labels[$j].': '.$percent($product['retention_series'][$j]).' came back']),
        ];
    }

    // Pancake's statuses in the order orders move through them, with their badge colors.
    $posStatuses = config('customers.pos_statuses');

    $change = ProductPerformance::change($total['sales'], $previousTotal['sales']);
    $finished = $total['delivered'] + $total['rts'];

    // Status ring order and colors (delivered, shipped, RTS, cancelled, not shipped yet).
    $segments = [
        'delivered' => ['Delivered', '#0e8f7c'],
        'shipped' => ['Shipped', '#4bcbeb'],
        'rts' => ['RTS', '#c4484c'],
        'cancelled' => ['Cancelled', '#9a93a0'],
        'open' => ['Not shipped yet', '#d9bdff'],
    ];
@endphp

<x-layouts.app title="Product Performance">
    <div class="space-y-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Product Performance</h1>
                <p class="text-sm text-muted">{{ $label }} · every CRD account's Pancake POS orders (Settings → Pancake Accounts), by the day the order was created</p>
            </div>

            {{-- Day, week (Monday–Sunday), month, last 3 months or From–To; switching keeps the dates shown as the starting point --}}
            <form method="GET" action="{{ route('product-performance.index') }}" class="flex flex-wrap items-end gap-3" aria-label="Dates">
                @if ($period !== 'range')
                    <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                    <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                @endif
                <input type="hidden" name="period" value="{{ $period }}" disabled data-period>
                @php $submit = "this.form.querySelector('[data-period]').disabled = false; this.form.submit()"; @endphp

                <div class="flex flex-wrap rounded-lg border border-line bg-white p-0.5" role="group" aria-label="Period">
                    @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'quarter' => 'Last 3 months', 'range' => 'Range'] as $value => $name)
                        <button type="submit" name="period" value="{{ $value }}" aria-pressed="{{ $period === $value ? 'true' : 'false' }}"
                                @class(['h-8 rounded-md px-3 text-sm font-semibold', 'bg-brand-600 text-white' => $period === $value, 'text-muted hover:text-brand-600' => $period !== $value])>{{ $name }}</button>
                    @endforeach
                </div>

                @switch ($period)
                    @case ('day')
                    @case ('week')
                        <label class="flex items-center gap-2 text-sm text-muted">
                            {{ $period === 'day' ? 'Day' : 'Week of' }}
                            <input type="date" name="date" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}" onchange="{{ $submit }}" class="{{ $control }} text-ink">
                        </label>
                        @break
                    @case ('month')
                        <label>
                            <span class="sr-only">Month</span>
                            <select name="month" onchange="{{ $submit }}" class="{{ $control }} font-semibold text-ink">
                                @for ($m = 0; $m < 12; $m++)
                                    @php $option = $today->startOfMonth()->subMonths($m); @endphp
                                    <option value="{{ $option->format('Y-m') }}" @selected($from->isSameMonth($option))>{{ $option->format('F Y') }}</option>
                                @endfor
                            </select>
                        </label>
                        @break
                    @case ('range')
                        <div class="flex items-center gap-1.5">
                            <input type="date" name="from" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="From" onchange="{{ $submit }}" class="{{ $control }}">
                            <span class="text-sm text-muted">to</span>
                            <input type="date" name="to" value="{{ $to->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="To" onchange="{{ $submit }}" class="{{ $control }}">
                        </div>
                        @break
                @endswitch
                <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white">Apply</button></noscript>
            </form>
        </header>

        {{-- Summary --}}
        @php
            $top = $products->first();
            $tile = 'grid gap-0.5 rounded-xl bg-white px-4 py-3.5 shadow-sm';
            $tileLabel = 'text-[11px] font-semibold tracking-wide text-muted uppercase';
        @endphp
        <section class="grid grid-cols-2 gap-3 tabular-nums lg:grid-cols-4" aria-label="Summary">
            <div class="{{ $tile }}">
                <p class="{{ $tileLabel }}">Gross sales</p>
                <p class="text-2xl font-bold">{{ $peso($total['sales']) }}</p>
                @if ($change !== null)
                    <p @class(['text-xs font-semibold', 'text-teal-700' => $change >= 0, 'text-coral-700' => $change < 0])>{{ $change >= 0 ? '▲' : '▼' }} {{ abs($change) }}% vs {{ $previousLabel }}</p>
                @endif
            </div>
            <div class="{{ $tile }}">
                <p class="{{ $tileLabel }}">Orders</p>
                <p class="text-2xl font-bold">{{ number_format($total['orders']) }}</p>
                <p class="text-xs text-muted">{{ number_format($total['qty']) }} pcs</p>
            </div>
            <div class="{{ $tile }}">
                <p class="{{ $tileLabel }}">Top product</p>
                @if ($top)
                    <p class="flex min-w-0 items-center gap-2 text-2xl font-bold"><span class="size-2.5 shrink-0 rounded-full" style="background: {{ $colors[$top['product']] }}" aria-hidden="true"></span><span class="truncate">{{ $top['product'] }}</span></p>
                    <p class="text-xs text-muted">{{ ProductPerformance::rate($top['sales'], $total['sales']) }}% of gross sales</p>
                @else
                    <p class="text-2xl font-bold">—</p>
                @endif
            </div>
            <div class="{{ $tile }}">
                <p class="{{ $tileLabel }}">Retention</p>
                <p class="text-2xl font-bold">{{ $percent($retention['rate']) }}</p>
                <p class="text-xs text-muted">{{ number_format($retention['back']) }} came back of {{ number_format($retention['due']) }} due</p>
            </div>
        </section>

        {{-- Ranking, then graph & insights: one panel at a time, slid with ‹ › (resources/js/product-performance.js) --}}
        <section class="grid gap-2.5" aria-roledescription="carousel" aria-label="Ranking, graph and insights" data-pp-slider>
            <div class="flex items-center justify-end gap-2">
                <p class="mr-auto text-sm text-muted" aria-live="polite" data-pp-slide-note>Slide to see graph and insights</p>
                @foreach (['prev' => ['Previous', 'M15 6l-6 6 6 6'], 'next' => ['Next', 'M9 6l6 6-6 6']] as $direction => [$arrowLabel, $path])
                    <button type="button" data-pp-slide="{{ $direction }}" aria-label="{{ $arrowLabel }}"
                            class="grid size-9 place-items-center rounded-full border border-line bg-white text-brand-700 shadow-sm transition hover:border-brand-600 hover:bg-brand-600 hover:text-white focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none">
                        <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/></svg>
                    </button>
                @endforeach
            </div>

            <div class="touch-pan-y overflow-hidden rounded-xl transition-[height] duration-300 ease-out motion-reduce:transition-none" data-pp-viewport>
                <div class="flex items-start transition-transform duration-500 ease-out motion-reduce:transition-none" data-pp-track>
                    {{-- Ranking --}}
                    <section class="min-w-0 shrink-0 basis-full rounded-xl bg-white p-5 shadow-sm" aria-roledescription="slide" aria-labelledby="pp-ranking-title" data-pp-panel>
                        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 id="pp-ranking-title" class="font-semibold">Ranking</h2>
                                <p class="text-sm text-muted">▲ / ▼ = places up or down since {{ $previousLabel }}</p>
                            </div>
                            <div class="flex rounded-lg border border-line bg-white p-0.5" role="group" aria-label="Rank by">
                                @foreach (['sales' => 'Gross sales', 'retention' => 'Retention'] as $by => $byLabel)
                                    <button type="button" data-pp-rank-by="{{ $by }}" aria-pressed="{{ $by === 'sales' ? 'true' : 'false' }}"
                                            class="h-8 rounded-md px-3 text-sm font-semibold text-muted hover:text-brand-600 aria-pressed:bg-brand-600 aria-pressed:text-white">{{ $byLabel }}</button>
                                @endforeach
                            </div>
                        </div>

                        @if ($products->isEmpty())
                            <p class="py-8 text-center text-sm text-muted">No CRD orders in these dates.</p>
                        @else
                            @php
                                $maxSales = max(1, $products->max('sales'));
                                $rankings = [
                                    'sales' => $products->sortBy(fn ($p) => $p['rank'] ?? PHP_INT_MAX)->map(fn ($p) => [...$p, 'place' => $p['rank'], 'was' => $p['previous_rank']]),
                                    'retention' => $products->sortBy(fn ($p) => $p['retention_rank'] ?? PHP_INT_MAX)->map(fn ($p) => [...$p, 'place' => $p['retention_rank'], 'was' => $p['previous_retention_rank']]),
                                ];
                            @endphp
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[40rem] text-sm tabular-nums">
                                    <thead class="text-[11px] tracking-wide text-muted uppercase">
                                        <tr class="border-b border-line">
                                            <th scope="col" class="px-2.5 py-2 text-left font-semibold">#</th>
                                            <th scope="col" class="px-2.5 py-2 text-left font-semibold" title="Places up or down since {{ $previousLabel }}">vs before</th>
                                            <th scope="col" class="px-2.5 py-2 text-left font-semibold">Product</th>
                                            <th scope="col" class="px-2.5 py-2 text-left font-semibold">Share of gross sales</th>
                                            <th scope="col" class="px-2.5 py-2 text-right font-semibold">Gross sales</th>
                                            <th scope="col" class="px-2.5 py-2 text-right font-semibold">Delivered</th>
                                            <th scope="col" class="px-2.5 py-2 text-right font-semibold">Retention <span class="font-normal normal-case">(came back of due)</span></th>
                                        </tr>
                                    </thead>
                                    @foreach ($rankings as $by => $rows)
                                        <tbody class="divide-y divide-line" data-pp-ranking="{{ $by }}" @if ($by !== 'sales') hidden @endif>
                                            @foreach ($rows as $row)
                                                @php
                                                    $move = $row['place'] !== null && $row['was'] !== null ? $row['was'] - $row['place'] : null;
                                                    $done = $row['delivered'] + $row['rts'];
                                                    $rate = $row['retention']['rate'];
                                                @endphp
                                                <tr>
                                                    <td class="px-2.5 py-2.5">
                                                        <span @class(['grid size-7 place-items-center rounded-lg font-bold', 'bg-brand-600 text-white' => $row['place'] !== null && $row['place'] <= 3, 'bg-brand-50 text-brand-700' => $row['place'] === null || $row['place'] > 3])>{{ $row['place'] ?? '–' }}</span>
                                                    </td>
                                                    <td class="px-2.5 py-2.5 text-xs font-bold whitespace-nowrap">
                                                        @if ($move === null)
                                                            <span class="text-muted">{{ $row['place'] !== null && $row['was'] === null ? 'New' : '–' }}</span>
                                                        @elseif ($move > 0)
                                                            <span class="text-teal-700">▲ {{ $move }}</span>
                                                        @elseif ($move < 0)
                                                            <span class="text-coral-700">▼ {{ abs($move) }}</span>
                                                        @else
                                                            <span class="text-muted">=</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-2.5 py-2.5 font-semibold"><span class="flex items-center gap-2"><span class="size-2.5 shrink-0 rounded-full" style="background: {{ $colors[$row['product']] }}" aria-hidden="true"></span>{{ $row['product'] }}</span></td>
                                                    <td class="px-2.5 py-2.5">
                                                        <span class="flex items-center gap-2">
                                                            <span class="h-2 min-w-20 flex-1 overflow-hidden rounded-full bg-brand-50"><span class="block h-full rounded-full" style="width: {{ $row['sales'] / $maxSales * 100 }}%; background: {{ $colors[$row['product']] }}"></span></span>
                                                            <span class="w-11 text-right">{{ ProductPerformance::rate($row['sales'], $total['sales']) ?? 0 }}%</span>
                                                        </span>
                                                    </td>
                                                    <td class="px-2.5 py-2.5 text-right font-bold">{{ $peso($row['sales']) }}</td>
                                                    <td class="px-2.5 py-2.5 text-right">{{ $done ? ProductPerformance::rate($row['delivered'], $done).'%' : '–' }}</td>
                                                    <td class="px-2.5 py-2.5 text-right">
                                                        @if ($rate === null)
                                                            <span class="text-muted">No one due</span>
                                                        @else
                                                            <span @class(['rounded-full px-2 py-0.5 text-xs font-bold',
                                                                'bg-[#dff6f1] text-[#0b6e60]' => $rate >= $retentionGoal,
                                                                'bg-[#fff3d6] text-[#8a5f08]' => $rate < $retentionGoal && $rate >= $retentionGoal - 0.1,
                                                                'bg-[#fde4e4] text-[#9e2f33]' => $rate < $retentionGoal - 0.1])>{{ $percent($rate) }}</span>
                                                            <span class="mt-0.5 block text-[11px] text-muted">{{ number_format($row['retention']['back']) }} of {{ number_format($row['retention']['due']) }}</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    @endforeach
                                </table>
                            </div>
                        @endif
                    </section>

                    {{-- Graph & insights --}}
                    <div class="grid min-w-0 shrink-0 basis-full items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]" aria-roledescription="slide" aria-label="Graph and insights" data-pp-panel>
                        <section class="min-w-0 rounded-xl bg-white p-5 shadow-sm" aria-labelledby="pp-trend-title">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    @foreach ($graphs as $mode => $graph)
                                        <div data-pp-graph-head="{{ $mode }}" @if ($mode !== 'sales') hidden @endif>
                                            <h2 @if ($mode === 'sales') id="pp-trend-title" @endif class="font-semibold">{{ $graph['title'] }}</h2>
                                            <p class="text-sm text-muted">{{ $graph['note'] }}</p>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="flex rounded-lg border border-line bg-white p-0.5" role="group" aria-label="Graph shows">
                                    @foreach ($graphs as $mode => $graph)
                                        <button type="button" data-pp-graph="{{ $mode }}" aria-pressed="{{ $mode === 'sales' ? 'true' : 'false' }}"
                                                class="h-8 rounded-md px-3 text-sm font-semibold text-muted hover:text-brand-600 aria-pressed:bg-brand-600 aria-pressed:text-white">{{ $graph['label'] }}</button>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Switch a product's line on or off; the top five by sales show at first --}}
                            <div class="mt-3 flex flex-wrap gap-1.5" role="group" aria-label="Products on the chart" data-pp-chart>
                                @foreach ($lines as $i => $line)
                                    <button type="button" data-pp-toggle="{{ $i }}" aria-pressed="{{ $line['shown'] ? 'true' : 'false' }}"
                                            class="flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1 text-xs font-medium text-muted transition hover:border-brand-400 aria-pressed:bg-white aria-pressed:text-ink aria-[pressed=false]:border-dashed">
                                        <span class="size-2.5 rounded-full" style="background: {{ $line['color'] }}"></span>{{ $line['name'] }}
                                    </button>
                                @endforeach
                            </div>

                            <svg viewBox="-34 0 682 200" class="mt-3 h-auto w-full sm:h-60" role="img" aria-label="Gross sales per {{ $unitName }} per product, {{ $label }}" data-pp-svg>
                                @foreach ($graphs as $mode => $graph)
                                    <g data-pp-graph-body="{{ $mode }}" @if ($mode !== 'sales') display="none" @endif>
                                        @foreach ($graph['grid'] as [$gridY, $gridLabel])
                                            <line x1="0" y1="{{ $gridY }}" x2="640" y2="{{ $gridY }}" stroke="#ebe6ee"/>
                                            <text x="-6" y="{{ $gridY + 4 }}" font-size="11" fill="#4f5563" text-anchor="end">{{ $gridLabel }}</text>
                                        @endforeach
                                        @if ($mode === 'retention')
                                            <line x1="0" y1="{{ $retentionY($retentionGoal) }}" x2="640" y2="{{ $retentionY($retentionGoal) }}" stroke="#343a40" stroke-width="1.5" stroke-dasharray="5 4"/>
                                            <text x="640" y="{{ $retentionY($retentionGoal) - 5 }}" font-size="11" font-weight="600" fill="#343a40" text-anchor="end">Goal {{ $goalLabel }}</text>
                                        @endif
                                        @foreach ($lines as $i => $line)
                                            <g data-pp-line="{{ $i }}" @unless ($line['shown']) display="none" @endunless>
                                                @foreach ($graph['paths'][$i] as $path)
                                                    @if (count($path) > 1)
                                                        <polyline points="{{ collect($path)->map(fn ($point) => $point[0].','.$point[1])->join(' ') }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="2.2" stroke-linejoin="round"/>
                                                    @endif
                                                    @if ($count <= 40)
                                                        @foreach ($path as [$pointX, $pointY, $tip])
                                                            <circle cx="{{ $pointX }}" cy="{{ $pointY }}" r="2.6" fill="{{ $line['color'] }}"><title>{{ $line['name'] }} · {{ $tip }}</title></circle>
                                                        @endforeach
                                                    @endif
                                                @endforeach
                                            </g>
                                        @endforeach
                                    </g>
                                @endforeach
                                @foreach ($axis as $i)
                                    <text x="{{ $x($i) }}" y="194" font-size="11" fill="#4f5563" text-anchor="{{ $i === 0 ? 'start' : ($i === $count - 1 ? 'end' : 'middle') }}">{{ $labels[$i] }}</text>
                                @endforeach
                            </svg>
                        </section>

                        <aside class="rounded-xl bg-white p-5 shadow-sm" aria-labelledby="pp-insights-title">
                            <h2 id="pp-insights-title" class="mb-3 font-semibold">Insights <span class="text-sm font-normal text-muted">· {{ count($insights) }}</span></h2>
                            <ul class="divide-y divide-line">
                                @forelse ($insights as $insight)
                                    <li class="flex gap-2.5 py-3 text-sm first:pt-0 last:pb-0">
                                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-teal-700' => $insight['tone'] === 'good', 'bg-coral-700' => $insight['tone'] === 'bad', 'bg-sky' => $insight['tone'] === 'neutral']) aria-hidden="true"></span>
                                        <div>
                                            <p class="font-semibold">{{ $insight['title'] }}</p>
                                            <p class="text-muted">{{ $insight['body'] }}</p>
                                        </div>
                                    </li>
                                @empty
                                    <li class="text-sm text-muted">No orders in these dates.</li>
                                @endforelse
                            </ul>
                        </aside>
                    </div>
                </div>
            </div>
        </section>

        <section aria-labelledby="pp-products-title" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="pp-products-title" class="font-semibold">Products <span class="text-sm font-normal text-muted">· by gross sales</span></h2>
                <div class="flex flex-wrap gap-3 text-xs text-muted">
                    @foreach ($segments as [$name, $color])
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: {{ $color }}"></span>{{ $name }}</span>
                    @endforeach
                </div>
            </div>

            @if ($products->isEmpty())
                <p class="rounded-xl bg-white px-4 py-10 text-center text-sm text-muted shadow-sm">No CRD orders in these dates.</p>
            @endif

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($products as $product)
                    @php
                        $done = $product['delivered'] + $product['rts'];
                        $productChange = ProductPerformance::change($product['sales'], $product['previous']['sales']);
                        $offset = 25;
                        $href = $canOpenReport ? route('order-database.product-report', ['period' => 'range', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'product' => $product['product']]) : null;
                    @endphp
                    <article class="flex flex-col gap-4 rounded-xl bg-white p-5 shadow-sm">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="flex items-center gap-2 font-semibold">
                                <span class="size-2.5 shrink-0 rounded-full" style="background: {{ $colors[$product['product']] }}" aria-hidden="true"></span>
                                @if ($href)
                                    <a href="{{ $href }}" class="hover:text-brand-600 hover:underline">{{ $product['product'] }}</a>
                                @else
                                    {{ $product['product'] }}
                                @endif
                            </h3>
                            @if ($productChange !== null)
                                <span @class(['text-xs font-semibold', 'text-teal-700' => $productChange >= 0, 'text-coral-700' => $productChange < 0])>{{ $productChange >= 0 ? '▲' : '▼' }} {{ abs($productChange) }}% vs before</span>
                            @elseif ($product['sales'] > 0)
                                <span class="text-xs font-semibold text-muted">New this period</span>
                            @endif
                        </div>

                        <div class="flex items-center gap-4">
                            <svg viewBox="0 0 42 42" class="size-20 shrink-0" role="img"
                                 aria-label="{{ collect($segments)->map(fn ($s, $key) => $s[0].' '.$product[$key])->join(', ') }}">
                                <circle cx="21" cy="21" r="15.9155" fill="none" stroke="#ebe6ee" stroke-width="6"/>
                                @foreach ($segments as $key => [$name, $color])
                                    @php $share = $product['orders'] > 0 ? $product[$key] / $product['orders'] * 100 : 0; @endphp
                                    @if ($share > 0)
                                        <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $color }}" stroke-width="6" stroke-dasharray="{{ round($share, 2) }} {{ round(100 - $share, 2) }}" stroke-dashoffset="{{ round($offset, 2) }}"><title>{{ $name }}: {{ $product[$key] }}</title></circle>
                                        @php $offset -= $share; @endphp
                                    @endif
                                @endforeach
                            </svg>
                            <div>
                                <p class="text-2xl font-bold tabular-nums">{{ $peso($product['sales']) }}</p>
                                <p class="text-xs text-muted">{{ number_format($product['orders']) }} {{ Str::plural('order', $product['orders']) }} · {{ number_format($product['qty']) }} pcs
                                    @if ($product['shipped'] || $product['open'])
                                        · {{ $product['shipped'] }} shipped · {{ $product['open'] }} not shipped yet
                                    @endif
                                </p>
                            </div>
                        </div>

                        <dl class="grid grid-cols-3 gap-2 text-xs tabular-nums">
                            <div class="rounded-lg bg-[#dff6f1] p-2.5 text-[#0b6e60]">
                                <dt>Delivered</dt>
                                <dd class="text-base font-bold">{{ ProductPerformance::rate($product['delivered'], $done) ?? '–' }}{{ $done ? '%' : '' }}</dd>
                                <dd>{{ $product['delivered'] }}/{{ $done }}</dd>
                            </div>
                            <div class="rounded-lg bg-[#fde4e4] p-2.5 text-[#9e2f33]">
                                <dt>RTS</dt>
                                <dd class="text-base font-bold">{{ ProductPerformance::rate($product['rts'], $done) ?? '–' }}{{ $done ? '%' : '' }}</dd>
                                <dd>{{ $product['rts'] }}/{{ $done }}</dd>
                            </div>
                            <div class="rounded-lg bg-canvas p-2.5 text-ink">
                                <dt>Cancelled</dt>
                                <dd class="text-base font-bold">{{ ProductPerformance::rate($product['cancelled'], $product['orders']) ?? 0 }}%</dd>
                                <dd>{{ $product['cancelled'] }}/{{ $product['orders'] }}</dd>
                            </div>
                        </dl>

                        {{-- Every Pancake status this product has at least one order in --}}
                        <ul class="flex flex-wrap gap-1.5 text-xs" aria-label="{{ $product['product'] }} orders per status">
                            @foreach (collect($posStatuses)->keys()->merge(array_keys($product['statuses']))->unique()->filter(fn ($status) => ($product['statuses'][$status] ?? 0) > 0) as $status)
                                <li class="rounded-full px-2 py-0.5 font-medium {{ $posStatuses[$status][1] ?? 'bg-canvas text-ink' }}">{{ $posStatuses[$status][0] ?? ($status === -1 ? 'No status' : "Status {$status}") }} <span class="font-bold tabular-nums">{{ $product['statuses'][$status] }}</span></li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        </section>

        <details class="rounded-xl bg-white p-4 text-sm shadow-sm">
            <summary class="cursor-pointer font-semibold">How the numbers are worked out</summary>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-muted sm:grid-cols-2">
                <div><dt class="font-semibold text-ink">Orders</dt><dd>Pancake POS orders assigned to a CRD account (Settings → Pancake Accounts), by the day they were created, for the products CRD handles (Settings → Product Consumption); items not on that list are left out. Deleted orders are left out. An order with two products counts under each.</dd></div>
                <div><dt class="font-semibold text-ink">Gross sales</dt><dd>The order's sale before Pancake's discount (Shecom's sale once synced), every status. An order with several products splits it by SRP × qty, else by qty (same as Order Database → Product Report).</dd></div>
                <div><dt class="font-semibold text-ink">Delivered and RTS</dt><dd>Orders, not pesos: delivered (incl. payment collected) ÷ delivered + RTS (returning, returned, partially returned). E.g. 80/95 = 84%. Shipped orders count once they arrive or come back.</dd></div>
                <div><dt class="font-semibold text-ink">Cancelled</dt><dd>Cancelled orders ÷ every order of the product. Below the boxes: the product's orders in every Pancake status it has at least one order in.</dd></div>
                <div><dt class="font-semibold text-ink">Ranking</dt><dd>Each product's place by gross sales (or by retention) in these dates; ▲ / ▼ = places up or down against its place in {{ $previousLabel }}. New = no sales (or nobody due) then. The Rank graph shows the place in every {{ $unitName }}.</dd></div>
                <div><dt class="font-semibold text-ink">Retention</dt><dd>Customers whose time to reorder ended in these dates, counted as the dashboard's churn rate does (delivered date + qty × consumption days, then {{ config('customers.churn_grace_days') }} days to order again), under the product of that delivery. Retention = came back in time ÷ due; goal {{ $goalLabel }}.</dd></div>
                <div class="sm:col-span-2"><dt class="font-semibold text-ink">Compared with</dt><dd>The same number of days just before ({{ $previousLabel }}). The chart shows each product's gross sales per hour for a day, per day up to two months, and per week beyond; click a product above the chart to show or hide its line.</dd></div>
            </dl>
        </details>
    </div>
</x-layouts.app>
