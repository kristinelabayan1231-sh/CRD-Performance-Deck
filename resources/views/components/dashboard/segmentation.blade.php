@props(['periods'])

{{--
    Segmentation Tracker on the dashboard: 4 KPI cards, then trend / tags / top CRAs.
    Chart colors validated with the dataviz palette checker (white surface):
    trend  processed #0E8F7C · unprocessed #E0663F
    tags   Hot #E0663F · Cold #2F6FD6 · Warm #C9970E · High value #8B3FF0 (ring order), untagged #E6E1E8
--}}
<section {{ $attributes }} aria-labelledby="seg-dash-title" data-tabs="dashboard.segmentation">
    <header class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 id="seg-dash-title" class="text-lg font-semibold">Segmentation Tracker</h2>
        <div class="flex items-center gap-3">
            <div class="flex rounded-lg bg-white p-0.5 text-xs font-semibold shadow-sm" role="tablist" aria-label="Period">
                @foreach ($periods as $key => $p)
                    <button type="button" role="tab" data-tab="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                            class="rounded-md px-3 py-1.5 text-muted transition aria-selected:bg-brand-600 aria-selected:text-white">{{ $p['name'] }}</button>
                @endforeach
            </div>
            <a href="{{ route('segmentation.index') }}" class="text-xs font-semibold text-brand-600 hover:underline">Open &rarr;</a>
        </div>
    </header>

    @foreach ($periods as $key => $p)
        <div role="tabpanel" data-tab-panel="{{ $key }}" @unless ($loop->first) hidden @endunless class="space-y-4">

            {{-- KPI cards --}}
            @php($kpiColors = ['text-brand-600', 'text-teal-700', 'text-sky-800', 'text-coral-700'])
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach ($p['kpis'] as $i => $kpi)
                    <div class="rounded-xl bg-white p-4 shadow-sm">
                        <p class="text-sm font-medium text-muted">{{ $kpi['label'] }}</p>
                        <p class="mt-1 text-3xl font-bold tabular-nums {{ $kpiColors[$i] }}">{{ $kpi['value'] }}</p>
                        <p class="mt-2 flex items-center justify-between border-t border-line pt-2 text-xs text-muted">
                            <span>Prev <span class="tabular-nums text-ink">{{ $kpi['previous'] }}</span></span>
                            <span @class([
                                'flex items-center gap-0.5 font-semibold tabular-nums',
                                'text-teal-700' => $kpi['good'] === true,
                                'text-coral-700' => $kpi['good'] === false,
                            ])>
                                @if ($kpi['direction'] === 'up')
                                    <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 4l6 8H4z"/></svg>
                                @elseif ($kpi['direction'] === 'down')
                                    <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 16l6-8H4z"/></svg>
                                @endif
                                {{ $kpi['change'] }}
                            </span>
                        </p>
                        @if ($kpi['label'] === 'Converted')
                            <p class="mt-1 text-[11px] text-muted">Retained <span class="font-semibold text-ink">{{ $p['retained'] }}%</span> · New <span class="font-semibold text-ink">{{ $p['new_converted'] }}%</span></p>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="grid gap-4 lg:grid-cols-12">
                {{-- Trend: processed vs unprocessed per day --}}
                @php($pts = $p['series'])
                @php($n = count($pts))
                @php($peak = max(1, max(array_merge([0], array_column($pts, 'processed'), array_column($pts, 'unprocessed')))))
                @php($yMax = $peak <= 4 ? 4 : (int) (ceil($peak / 5) * 5))
                @php($x = fn ($i) => round(30 + ($n > 1 ? $i * 282 / ($n - 1) : 141), 1))
                @php($y = fn ($v) => round(10 + (1 - $v / $yMax) * 110, 1))
                <figure class="rounded-xl bg-white p-4 shadow-sm lg:col-span-5">
                    <figcaption class="mb-2 flex items-center justify-between text-sm">
                        <span class="font-semibold">Daily trend</span>
                        <span class="flex gap-3 text-xs text-muted">
                            <span class="flex items-center gap-1"><span class="h-0.5 w-3 rounded bg-[#0E8F7C]"></span>Processed</span>
                            <span class="flex items-center gap-1"><span class="h-0.5 w-3 rounded bg-[#E0663F]"></span>Unprocessed</span>
                        </span>
                    </figcaption>
                    <svg viewBox="0 0 320 140" class="h-auto w-full" role="img" aria-label="Processed and unprocessed leads per day, {{ $p['label'] }}">
                        @foreach ([0, $yMax / 2, $yMax] as $tick)
                            <line x1="30" x2="312" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" stroke="#ebe6ee" stroke-width="1" />
                            <text x="24" y="{{ $y($tick) + 3 }}" text-anchor="end" class="fill-muted text-[9px]">{{ (int) $tick }}</text>
                        @endforeach
                        @if ($n)
                            @foreach (['processed' => '#0E8F7C', 'unprocessed' => '#E0663F'] as $series => $color)
                                <polyline fill="none" stroke="{{ $color }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"
                                          points="{{ collect($pts)->map(fn ($pt, $i) => $x($i).','.$y($pt[$series]))->join(' ') }}" />
                            @endforeach
                            @foreach ($pts as $i => $pt)
                                <g class="group">
                                    {{-- Wide invisible hit area per day, with a tooltip --}}
                                    <rect x="{{ $x($i) - ($n > 1 ? 141 / ($n - 1) : 141) }}" y="0" width="{{ $n > 1 ? 282 / ($n - 1) : 282 }}" height="130" fill="transparent">
                                        <title>{{ $pt['label'] }}: {{ $pt['processed'] }} processed · {{ $pt['unprocessed'] }} unprocessed</title>
                                    </rect>
                                    <line x1="{{ $x($i) }}" x2="{{ $x($i) }}" y1="10" y2="120" stroke="#d9bdff" stroke-width="1" class="pointer-events-none opacity-0 group-hover:opacity-100" />
                                    <circle cx="{{ $x($i) }}" cy="{{ $y($pt['processed']) }}" r="4" fill="#0E8F7C" stroke="#fff" stroke-width="2" class="pointer-events-none" />
                                    <circle cx="{{ $x($i) }}" cy="{{ $y($pt['unprocessed']) }}" r="4" fill="#E0663F" stroke="#fff" stroke-width="2" class="pointer-events-none" />
                                </g>
                            @endforeach
                            @foreach (array_unique([0, intdiv($n - 1, 2), $n - 1]) as $i)
                                <text x="{{ $x($i) }}" y="136" text-anchor="{{ $i === 0 && $n > 1 ? 'start' : ($i === $n - 1 && $n > 1 ? 'end' : 'middle') }}" class="fill-muted text-[9px]">{{ $pts[$i]['label'] }}</text>
                            @endforeach
                        @endif
                    </svg>
                </figure>

                {{-- Customer tags donut --}}
                @php($tagTotal = max(1, $p['leads']))
                @php($circ = 2 * M_PI * 36)
                @php($slices = collect($p['tags'])->push(['label' => 'Untagged', 'value' => $p['untagged'], 'color' => '#E6E1E8'])->filter(fn ($s) => $s['value'] > 0)->values())
                @php($gap = $slices->count() > 1 ? 2 : 0)
                <figure class="rounded-xl bg-white p-4 shadow-sm lg:col-span-3">
                    <figcaption class="mb-2 text-sm font-semibold">Customer tags</figcaption>
                    <div class="flex items-center gap-4">
                        <svg viewBox="0 0 100 100" class="size-28 shrink-0 -rotate-90" role="img" aria-label="Leads by customer tag, {{ $p['label'] }}">
                            <circle cx="50" cy="50" r="36" fill="none" stroke="#f2edf3" stroke-width="14" />
                            @php($offset = 0)
                            @foreach ($slices as $s)
                                @php($len = $s['value'] / $tagTotal * $circ)
                                <circle cx="50" cy="50" r="36" fill="none" stroke="{{ $s['color'] }}" stroke-width="14"
                                        stroke-dasharray="{{ max(0, $len - $gap) }} {{ $circ }}" stroke-dashoffset="{{ -$offset }}">
                                    <title>{{ $s['label'] }}: {{ $s['value'] }}</title>
                                </circle>
                                @php($offset += $len)
                            @endforeach
                            <text x="50" y="50" text-anchor="middle" dominant-baseline="central" transform="rotate(90 50 50)" class="fill-ink text-[16px] font-bold">{{ number_format($p['leads']) }}</text>
                        </svg>
                        <ul class="min-w-0 flex-1 space-y-1.5 text-xs">
                            @foreach ($p['tags'] as $s)
                                <li class="flex items-center justify-between gap-2">
                                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: {{ $s['color'] }}"></span>{{ $s['label'] }}</span>
                                    <span class="font-semibold tabular-nums">{{ $s['value'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </figure>

                {{-- Top CRAs --}}
                @php($maxUnprocessed = collect($p['ranking'])->max('unprocessed'))
                <div class="rounded-xl bg-white p-4 shadow-sm lg:col-span-4">
                    <p class="mb-2 text-sm font-semibold">Top CRAs</p>
                    @if (empty($p['ranking']))
                        <p class="py-6 text-center text-sm text-muted">No CRAs yet.</p>
                    @else
                        <table class="w-full text-xs">
                            <thead class="text-muted">
                                <tr class="border-b border-line">
                                    <th class="pb-1.5 text-left font-medium">CRA</th>
                                    <th class="pb-1.5 text-right font-medium" title="Processed">Done</th>
                                    <th class="pb-1.5 text-right font-medium" title="Unprocessed">Open</th>
                                    <th class="pb-1.5 text-right font-medium" title="Converted">Conv.</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($p['ranking'] as $i => $row)
                                    <tr class="border-b border-line/60 last:border-0">
                                        <td class="py-1.5">
                                            <span class="flex items-center gap-2">
                                                <span @class([
                                                    'grid size-5 shrink-0 place-items-center rounded-full text-[10px] font-bold',
                                                    'bg-brand-600 text-white' => $i === 0 && $row['processed'] > 0,
                                                    'bg-canvas text-muted' => ! ($i === 0 && $row['processed'] > 0),
                                                ])>{{ $i + 1 }}</span>
                                                <span class="truncate font-medium">{{ $row['name'] }}</span>
                                            </span>
                                        </td>
                                        <td class="py-1.5 text-right font-semibold tabular-nums text-teal-700">{{ $row['processed'] }}</td>
                                        <td @class(['py-1.5 text-right tabular-nums', 'font-semibold text-coral-700' => $row['unprocessed'] > 0 && $row['unprocessed'] === $maxUnprocessed])>{{ $row['unprocessed'] }}</td>
                                        <td class="py-1.5 text-right tabular-nums">{{ $row['converted'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</section>
