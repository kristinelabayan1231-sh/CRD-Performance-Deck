@php
    $fmt = fn ($value, string $type = 'count') => $value === null ? '—' : match ($type) {
        'percent' => number_format($value * 100, 2).'%',
        'money' => '₱'.number_format($value, 2),
        default => number_format($value),
    };
    // Change from the compare period: counts as a number, rates in points.
    $delta = function ($now, $before, string $type = 'count') {
        if ($now === null || $before === null) {
            return ['text' => 'no comparison', 'dir' => 'flat'];
        }
        $d = $type === 'percent' ? ($now - $before) * 100 : $now - $before;
        $dir = abs($d) < 0.05 ? 'flat' : ($d > 0 ? 'up' : 'down');
        $text = ($d > 0 ? '+' : ($d < 0 ? '−' : '±')).($type === 'percent' ? number_format(abs($d), 1).' pts' : number_format(abs($d)));

        return ['text' => $text, 'dir' => $dir];
    };
    [$metricLabel, $metricType] = $metrics[$metric];
    $query = fn (array $extra) => route('productivity.index', array_filter([...$filters, ...$extra], fn ($v) => $v !== null && $v !== []));
@endphp

<x-layouts.app title="Segmentation Productivity">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Segmentation Productivity <span class="text-sm font-normal text-muted">· {{ $period['label'] }} <span class="text-muted/80">· compared with {{ $compare['label'] }}</span></span></h1>
            <div class="flex flex-wrap items-center gap-3 text-xs text-muted">
                <span>
                    @if ($syncing)
                        Pancake syncing {{ $syncDay->format('M j') }}… refresh in a few minutes.
                    @else
                        Pancake synced {{ $lastSync ? $lastSync->timezone(config('segmentation.timezone'))->format('M j, g:i A') : 'never for '.$syncDay->format('M j') }}
                    @endif
                </span>
                @if ($canSync && ! $syncing)
                    <form method="POST" action="{{ route('productivity.sync') }}">
                        @csrf
                        <input type="hidden" name="date" value="{{ $syncDay->toDateString() }}">
                        <button type="submit" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-700">
                            Sync Pancake · {{ $syncDay->format('M j') }}
                        </button>
                    </form>
                @endif
            </div>
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        @if ($missingAccounts->isNotEmpty())
            <div role="status" class="rounded-lg border border-line bg-white px-4 py-3 text-sm">
                No Pancake account set for {{ $missingAccounts->map->displayName()->join(', ', ' and ') }}, so their chat engagements and Pancake orders show as 0.
                @can('user_access.manage') <a href="{{ route('user-access.index') }}" class="font-semibold text-brand-600 hover:underline">Set it in User Access</a>. @endcan
            </div>
        @endif

        {{-- Filters --}}
        <form method="GET" action="{{ route('productivity.index') }}" class="flex flex-wrap items-end gap-x-6 gap-y-4 rounded-xl bg-white p-4 shadow-sm">
            @if ($canViewAll)
                <fieldset class="min-w-0">
                    <legend class="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">CRA</legend>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($allCras as $cra)
                            <label class="cursor-pointer">
                                <input type="checkbox" name="cras[]" value="{{ $cra->id }}" class="peer sr-only" onchange="this.form.submit()" @checked(in_array($cra->id, $selected))>
                                <span class="flex items-center gap-1.5 rounded-full border border-line px-3 py-1 text-sm text-muted transition peer-checked:border-ink/40 peer-checked:bg-canvas peer-checked:text-ink peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200">
                                    <span class="size-2.5 rounded-full" style="background: {{ $colors[$cra->id] }}"></span>{{ $cra->displayName() }}
                                </span>
                            </label>
                        @endforeach
                        <a href="{{ $query(['cras' => null]) }}" class="rounded-full border border-dashed border-line px-3 py-1 text-sm text-muted hover:text-brand-600">All</a>
                    </div>
                </fieldset>
            @endif

            @php($control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')
            <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                Trend shows
                <select name="metric" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                    @foreach ($metrics as $key => [$label])
                        <option value="{{ $key }}" @selected($key === $metric)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            {{-- Dates on the right --}}
            <div class="ml-auto flex flex-wrap items-end gap-x-6 gap-y-4">
                <fieldset>
                    <legend class="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">View by</legend>
                    <div class="flex rounded-lg border border-line p-0.5 text-sm font-semibold">
                        @foreach (['day' => 'Day', 'week' => 'Week'] as $value => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="view" value="{{ $value }}" class="peer sr-only" onchange="this.form.submit()" @checked($view === $value)>
                                <span class="block rounded-md px-3 py-1 text-muted peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                @if ($view === 'day')
                    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                        Day
                        <input type="date" name="date" value="{{ $period['key'] }}" max="{{ $today->toDateString() }}" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                    </label>
                    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                        Compare with
                        <input type="date" name="compare" value="{{ $compare['key'] }}" max="{{ $today->toDateString() }}" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                    </label>
                @else
                    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                        Month
                        <input type="month" name="month" value="{{ $month->format('Y-m') }}" max="{{ $today->format('Y-m') }}" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                    </label>
                    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                        Week
                        <select name="week" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                            @foreach ($weeks->filter(fn ($w) => $w['from']->isSameMonth($month)) as $w)
                                <option value="{{ $w['number'] }}" @selected($w['key'] === $period['key'])>{{ $w['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                        Compare with
                        <select name="compare" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                            @foreach ($weeks as $w)
                                <option value="{{ $w['key'] }}" @selected($w['key'] === $compare['key'])>{{ $w['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>
            <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">Apply</button></noscript>
        </form>

        {{-- CRA cards --}}
        <section aria-label="CRA profiles" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            @forelse ($rows as $row)
                @php($now = $row['now'])
                @php($d = $delta($now['confirmed'], $row['before']['confirmed']))
                @php($max = max(1, $now['assigned'], $now['answered']))
                @php($focusUrl = $canViewAll ? $query(['cras' => [$row['cra']->id]]) : null)
                <article class="relative flex min-w-0 flex-col gap-3 rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        {{-- The dot is the CRA's colour in the charts --}}
                        <h2 class="flex items-center gap-2 font-semibold">
                            <span aria-hidden="true" class="size-2.5 shrink-0 rounded-full" style="background: {{ $row['color'] }}"></span>
                            @if ($focusUrl && count($selected) > 1)
                                <a href="{{ $focusUrl }}" class="after:absolute after:inset-0 hover:text-brand-600" title="Show only {{ $row['cra']->displayName() }}">{{ $row['cra']->displayName() }}</a>
                            @else
                                {{ $row['cra']->displayName() }}
                            @endif
                        </h2>
                        @if ($now['assigned'] === 0 && $now['answered'] === 0)
                            <span class="rounded-full bg-canvas px-2 py-0.5 text-[11px] font-semibold text-muted">No activity</span>
                        @endif
                    </div>

                    <div>
                        <p class="text-xs font-semibold tracking-wide text-muted uppercase">Confirmed orders</p>
                        <p class="flex items-baseline gap-2">
                            <span class="text-2xl font-bold tabular-nums">{{ number_format($now['confirmed']) }}</span>
                            <span @class(['text-xs font-semibold tabular-nums', 'text-teal-700' => $d['dir'] === 'up', 'text-coral-700' => $d['dir'] === 'down', 'text-muted' => $d['dir'] === 'flat'])>
                                {{ $d['dir'] === 'up' ? '▲' : ($d['dir'] === 'down' ? '▼' : '') }} {{ $d['text'] }}
                            </span>
                        </p>
                    </div>

                    {{-- Funnel: assigned → answered → confirmed (assigned lead + Pancake) --}}
                    <div class="space-y-1.5 text-xs">
                        @foreach ([['Assigned', $now['assigned'], null], ['Answered', $now['answered'], null], ['Confirmed', $now['alc'], $now['pc']]] as [$label, $value, $extra])
                            <div class="grid grid-cols-[70px_1fr_36px] items-center gap-2">
                                <span class="text-muted">{{ $label }}</span>
                                <span class="relative h-2 overflow-hidden rounded-r bg-canvas">
                                    <span class="absolute inset-y-0 left-0" style="width: {{ min(100, $value / $max * 100) }}%; background: {{ $row['color'] }}"></span>
                                    @if ($extra !== null)
                                        <span class="absolute inset-y-0" style="left: {{ min(100, $value / $max * 100) }}%; width: {{ min(100, $extra / $max * 100) }}%; background: {{ $row['color'] }}; opacity: .45"></span>
                                    @endif
                                </span>
                                <span class="text-right font-semibold tabular-nums">{{ number_format($value + ($extra ?? 0)) }}</span>
                            </div>
                        @endforeach
                        <p class="text-[11px] text-muted">
                            Answered: {{ number_format($now['calls']) }} calls + {{ number_format($now['chat']) }} chat ·
                            Confirmed: {{ number_format($now['alc']) }} assigned lead + {{ number_format($now['pc']) }} Pancake
                        </p>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 border-t border-line pt-3 text-sm">
                        <div><dt class="text-[11px] text-muted">Conversion</dt><dd class="font-semibold tabular-nums">{{ $fmt($now['conversion_rate'], 'percent') }}</dd></div>
                        <div><dt class="text-[11px] text-muted">Pick-up</dt><dd class="font-semibold tabular-nums">{{ $fmt($now['pickup_rate'], 'percent') }}</dd></div>
                        <div><dt class="text-[11px] text-muted">AOV</dt><dd class="font-semibold tabular-nums">{{ $fmt($now['aov'], 'money') }}</dd></div>
                        <div><dt class="text-[11px] text-muted">Gross sales</dt><dd class="font-semibold tabular-nums">{{ $fmt($now['gross'], 'money') }}</dd></div>
                    </dl>

                    {{-- Sparkline of the chosen metric --}}
                    @php($pts = $row['spark'])
                    @php($n = count($pts))
                    @php($vals = array_filter(array_column($pts, 'value'), fn ($v) => $v !== null))
                    @php($top = $vals ? max(max($vals), $metricType === 'percent' ? 0.0001 : 1) : 1)
                    @php($sx = fn ($i) => round(4 + ($n > 1 ? $i * 192 / ($n - 1) : 96), 1))
                    @php($sy = fn ($v) => round(40 - $v / $top * 34, 1))
                    <div class="border-t border-line pt-3">
                        <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">{{ $metricLabel }}, {{ $view === 'day' ? 'daily' : 'weekly' }}</p>
                        <svg viewBox="0 0 200 46" class="mt-1 h-12 w-full overflow-visible" preserveAspectRatio="none" role="img" aria-label="{{ $metricLabel }} trend for {{ $row['cra']->displayName() }}">
                            <line x1="0" x2="200" y1="40" y2="40" stroke="#ebe6ee" stroke-width="1" vector-effect="non-scaling-stroke" />
                            @php($path = '')
                            @php($pen = 'M')
                            @foreach ($pts as $i => $pt)
                                @if ($pt['value'] === null)
                                    @php($pen = 'M')
                                @else
                                    @php($path .= $pen.$sx($i).','.$sy($pt['value']))
                                    @php($pen = 'L')
                                @endif
                            @endforeach
                            <path d="{{ $path }}" fill="none" stroke="{{ $row['color'] }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                            @foreach ($pts as $i => $pt)
                                @if ($pt['selected'] && $pt['value'] !== null)
                                    <circle cx="{{ $sx($i) }}" cy="{{ $sy($pt['value']) }}" r="3.5" fill="{{ $row['color'] }}" stroke="#fff" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                                @endif
                                <rect x="{{ $sx($i) - ($n > 1 ? 96 / ($n - 1) : 100) }}" y="0" width="{{ $n > 1 ? 192 / ($n - 1) : 200 }}" height="46" fill="transparent">
                                    <title>{{ $pt['label'] }}: {{ $fmt($pt['value'], $metricType) }}</title>
                                </rect>
                            @endforeach
                        </svg>
                    </div>
                </article>
            @empty
                <p class="col-span-full rounded-xl bg-white p-8 text-center text-sm text-muted shadow-sm">No active CRAs yet. Give users the CRA role in User Access.</p>
            @endforelse
        </section>

        @if ($rows->isNotEmpty())
            <div class="grid gap-4 lg:grid-cols-2">
                {{-- Weekly confirmed orders, stacked by CRA --}}
                @php($weekTotals = $weekly->map(fn ($w) => array_sum(array_column($w['stack'], 'value'))))
                @php($wMax = max(4, (int) ceil(max(1, $weekTotals->max() ?? 1) / 4) * 4))
                @php($wn = max(1, $weekly->count()))
                @php($slot = 282 / $wn)
                @php($bw = min(46, $slot * 0.6))
                @php($wy = fn ($v) => 10 + (1 - $v / $wMax) * 120)
                <figure class="rounded-xl bg-white p-4 shadow-sm">
                    <figcaption class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span class="font-semibold">Confirmed orders per week · {{ $month->format('F Y') }}</span>
                        <span class="flex flex-wrap gap-3 text-xs text-muted">
                            @foreach ($rows as $row)
                                <span class="flex items-center gap-1"><span class="size-2.5 rounded-sm" style="background: {{ $row['color'] }}"></span>{{ $row['cra']->displayName() }}</span>
                            @endforeach
                        </span>
                    </figcaption>
                    <svg viewBox="0 0 320 156" class="h-auto w-full" role="img" aria-label="Confirmed orders per week by CRA, {{ $month->format('F Y') }}">
                        @foreach ([0, $wMax / 2, $wMax] as $tick)
                            <line x1="30" x2="312" y1="{{ $wy($tick) }}" y2="{{ $wy($tick) }}" stroke="#ebe6ee" stroke-width="1" />
                            <text x="24" y="{{ $wy($tick) + 3 }}" text-anchor="end" class="fill-muted text-[9px]">{{ (int) $tick }}</text>
                        @endforeach
                        @foreach ($weekly as $i => $w)
                            @php($cx = 30 + $slot * ($i + 0.5))
                            @if ($w['selected'])
                                <rect x="{{ $cx - $slot / 2 + 2 }}" y="6" width="{{ $slot - 4 }}" height="128" rx="4" fill="#f6efff" />
                            @endif
                            @php($stacked = 0)
                            @foreach ($w['stack'] as $seg)
                                @if ($seg['value'] > 0)
                                    <rect x="{{ $cx - $bw / 2 }}" y="{{ $wy($stacked + $seg['value']) }}" width="{{ $bw }}" height="{{ max(0, $wy($stacked) - $wy($stacked + $seg['value'])) }}"
                                          fill="{{ $seg['color'] }}" stroke="#fff" stroke-width="1">
                                        <title>{{ $w['label'] }} ({{ $w['range'] }}) · {{ $seg['name'] }}: {{ number_format($seg['value']) }}</title>
                                    </rect>
                                    @php($stacked += $seg['value'])
                                @endif
                            @endforeach
                            <text x="{{ $cx }}" y="{{ $wy($stacked) - 4 }}" text-anchor="middle" class="fill-ink text-[9px] font-semibold">{{ number_format($stacked) }}</text>
                            <text x="{{ $cx }}" y="148" text-anchor="middle" class="fill-muted text-[9px]">{{ $w['label'] }}</text>
                        @endforeach
                    </svg>
                </figure>

                {{-- Sales per CRA by day / week / month, with each bar's top seller --}}
                @php($sMax = max(1, $sales->max('total') ?? 1))
                @php($sStep = collect([500, 1000, 2000, 2500, 5000, 10000, 20000, 25000, 50000, 100000, 200000, 250000, 500000, 1000000])->first(fn ($st) => $st * 2 >= $sMax) ?? (int) ceil($sMax / 2))
                @php($sTop = $sStep * 2)
                @php($sn = max(1, $sales->count()))
                @php($sslot = 278 / $sn)
                @php($sbw = max(2, min(46, $sslot * 0.65)))
                @php($sy = fn ($v) => 10 + (1 - $v / $sTop) * 116)
                @php($focus = $sales->firstWhere('selected', true) ?? $sales->filter(fn ($b) => $b['top'])->last())
                <figure id="sales" class="rounded-xl bg-white p-4 shadow-sm">
                    <figcaption class="mb-2 space-y-2 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold">Sales per CRA</span>
                            <span class="flex rounded-lg border border-line p-0.5 text-xs font-semibold" role="group" aria-label="Group sales by">
                                @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $value => $label)
                                    <a href="{{ $query(['sales' => $value]) }}#sales" @if ($salesBy === $value) aria-current="true" @endif
                                       @class(['rounded-md px-2.5 py-1', 'bg-brand-600 text-white' => $salesBy === $value, 'text-muted hover:text-brand-600' => $salesBy !== $value])>{{ $label }}</a>
                                @endforeach
                            </span>
                        </div>
                        <span class="flex flex-wrap gap-3 text-xs text-muted">
                            @foreach ($rows as $row)
                                <span class="flex items-center gap-1"><span class="size-2.5 rounded-sm" style="background: {{ $row['color'] }}"></span>{{ $row['cra']->displayName() }}</span>
                            @endforeach
                        </span>
                    </figcaption>
                    <svg viewBox="0 0 320 166" class="h-auto w-full" role="img" aria-label="Gross sales per CRA by {{ $salesBy }}">
                        @foreach ([0, $sStep, $sTop] as $tick)
                            <line x1="34" x2="312" y1="{{ $sy($tick) }}" y2="{{ $sy($tick) }}" stroke="#ebe6ee" stroke-width="1" />
                            <text x="30" y="{{ $sy($tick) + 3 }}" text-anchor="end" class="fill-muted text-[8px]">{{ $tick >= 1000 ? '₱'.rtrim(rtrim(number_format($tick / 1000, 1), '0'), '.').'k' : '₱'.$tick }}</text>
                        @endforeach
                        @foreach ($sales as $i => $b)
                            @php($cx = 34 + $sslot * ($i + 0.5))
                            @if ($b['selected'])
                                <rect x="{{ $cx - $sslot / 2 }}" y="6" width="{{ $sslot }}" height="140" rx="2" fill="#f6efff" />
                            @endif
                            <g>
                                <title>{{ $b['long'] }}: ₱{{ number_format($b['total'], 2) }}@foreach (collect($b['stack'])->sortByDesc('value') as $seg)&#10;{{ $seg['name'] }}: ₱{{ number_format($seg['value'], 2) }}@endforeach</title>
                                <rect x="{{ $cx - $sslot / 2 }}" y="0" width="{{ $sslot }}" height="146" fill="transparent" />
                                @php($stacked = 0)
                                @foreach ($b['stack'] as $seg)
                                    @if ($seg['value'] > 0)
                                        <rect x="{{ $cx - $sbw / 2 }}" y="{{ $sy($stacked + $seg['value']) }}" width="{{ $sbw }}" height="{{ max(0, $sy($stacked) - $sy($stacked + $seg['value'])) }}"
                                              fill="{{ $seg['color'] }}" stroke="#fff" stroke-width="{{ $sbw > 6 ? 1 : 0.5 }}" />
                                        @php($stacked += $seg['value'])
                                    @endif
                                @endforeach
                                {{-- Top seller marker under each bar --}}
                                @if ($b['top'])
                                    <circle cx="{{ $cx }}" cy="135" r="{{ min(3.5, max(1.8, $sbw / 3)) }}" fill="{{ $b['top']['color'] }}" />
                                @endif
                            </g>
                        @endforeach
                        <text x="34" y="135" dy="3" text-anchor="end" class="fill-muted text-[7px]" dx="-4">Top</text>
                        @foreach ($sn > 8 ? array_unique([0, intdiv($sn - 1, 2), $sn - 1]) : range(0, $sn - 1) as $i)
                            @if (isset($sales[$i]))
                                <text x="{{ 34 + $sslot * ($i + 0.5) }}" y="158" text-anchor="middle" class="fill-muted text-[9px]">{{ $sales[$i]['label'] }}</text>
                            @endif
                        @endforeach
                    </svg>
                    <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-line pt-2 text-xs text-muted">
                        @if ($focus && $focus['top'])
                            <span>Top seller · {{ $focus['long'] }}</span>
                            <span class="flex items-center gap-1.5 font-semibold text-ink">
                                <span class="size-2.5 rounded-full" style="background: {{ $focus['top']['color'] }}"></span>{{ $focus['top']['name'] }}
                            </span>
                            <span class="tabular-nums">₱{{ number_format($focus['top']['value'], 2) }} of ₱{{ number_format($focus['total'], 2) }}</span>
                        @else
                            <span>No sales recorded for this range yet.</span>
                        @endif
                    </p>
                </figure>
            </div>

            {{-- The report as in the sheet --}}
            <x-panel :title="'Segmentation Productivity Report · '.$period['label']" icon="table" title-id="sheet-title" :tinted="false" body-class="">
                <x-slot:actions>
                    <p class="text-muted">Assigned base {{ $base }} per CRA per day; the actual count is shown.</p>
                </x-slot:actions>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[920px] text-sm">
                        <thead class="bg-canvas/60 text-[11px] tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-semibold">Team</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Assigned transactions</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Answered (calls &amp; chat)</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Assigned lead conversion</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Pancake conversion</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Total confirmed orders</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Conversion rate</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Pick-up rate</th>
                                <th class="px-3 py-2.5 text-right font-semibold">AOV</th>
                                <th class="px-4 py-2.5 text-right font-semibold">Gross sales</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line whitespace-nowrap tabular-nums">
                            @foreach ($rows as $row)
                                @php($r = $row['now'])
                                <tr>
                                    <td class="px-4 py-2.5 font-medium"><span class="flex items-center gap-2"><span class="size-2.5 shrink-0 rounded-full" style="background: {{ $row['color'] }}"></span>{{ $row['cra']->displayName() }}</span></td>
                                    <td class="px-3 py-2.5 text-right">{{ $fmt($r['assigned']) }}</td>
                                    <td class="px-3 py-2.5 text-right" title="{{ $r['calls'] }} calls + {{ $r['chat'] }} chat">{{ $fmt($r['answered']) }}</td>
                                    <td class="px-3 py-2.5 text-right">{{ $fmt($r['alc']) }}</td>
                                    <td class="px-3 py-2.5 text-right">{{ $fmt($r['pc']) }}</td>
                                    <td class="px-3 py-2.5 text-right font-semibold">{{ $fmt($r['confirmed']) }}</td>
                                    <td class="px-3 py-2.5 text-right">{{ $fmt($r['conversion_rate'], 'percent') }}</td>
                                    <td class="px-3 py-2.5 text-right">{{ $fmt($r['pickup_rate'], 'percent') }}</td>
                                    <td class="px-3 py-2.5 text-right">{{ $fmt($r['aov'], 'money') }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ $fmt($r['gross'], 'money') }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-ink font-semibold text-white">
                                <td class="px-4 py-2.5">TOTAL</td>
                                <td class="px-3 py-2.5 text-right">{{ $fmt($total['assigned']) }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $fmt($total['answered']) }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $fmt($total['alc']) }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $fmt($total['pc']) }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $fmt($total['confirmed']) }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $fmt($total['conversion_rate'], 'percent') }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $fmt($total['pickup_rate'], 'percent') }}</td>
                                <td class="px-3 py-2.5 text-right">{{ $fmt($total['aov'], 'money') }}</td>
                                <td class="px-4 py-2.5 text-right">{{ $fmt($total['gross'], 'money') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endif

        <details class="rounded-xl bg-white p-4 text-sm shadow-sm">
            <summary class="cursor-pointer font-semibold">How the numbers are worked out</summary>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-muted sm:grid-cols-2">
                <div><dt class="font-semibold text-ink">Assigned transactions</dt><dd>Segmentation Tracker leads assigned to the CRA for that lead day. The base is {{ $base }}; the actual count is used.</dd></div>
                <div><dt class="font-semibold text-ink">Answered (calls &amp; chat)</dt><dd>Tracker leads with that Contact Date, plus the CRA's Pancake customer engagements (Chat → Analytics → Engagements) across all pages.</dd></div>
                <div><dt class="font-semibold text-ink">Total confirmed orders</dt><dd>The CRA's own Pancake orders that day tagged CRD - BROADCAST or CRD - SEGMENTATION. Canceled and deleted orders don't count.</dd></div>
                <div><dt class="font-semibold text-ink">Assigned lead conversion</dt><dd>Confirmed orders whose customer is on the CRA's assigned leads.</dd></div>
                <div><dt class="font-semibold text-ink">Pancake conversion</dt><dd>All other confirmed orders.</dd></div>
                <div><dt class="font-semibold text-ink">Conversion rate</dt><dd>Total confirmed orders ÷ Answered.</dd></div>
                <div><dt class="font-semibold text-ink">Pick-up rate</dt><dd>Answered ÷ Assigned transactions.</dd></div>
                <div><dt class="font-semibold text-ink">AOV and Gross sales</dt><dd>Gross sales come from Conversion Breakdown: the CRA's own orders tagged CRD - BROADCAST plus CRD - SEGMENTATION, at Shecom's sales (without the child TSD row). Canceled and deleted orders don't count. AOV = Gross sales ÷ Total confirmed orders.</dd></div>
            </dl>
        </details>
    </div>
</x-layouts.app>
