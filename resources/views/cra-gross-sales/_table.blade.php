{{--
    The year so far per CRA and the months, one figure at a time ($show): re-drawn after a figure is saved
    (X-Cra-Gross-Sales-Table). On Gross & NI each month shows Gross above Net Income; in edit mode, a box for each.
--}}
@php
    $link = fn (int $y, ?bool $edit = null, ?string $to = null) => route('cra-gross-sales.index', array_filter([
        'year' => $y, 'show' => ($to ?? $show) === 'sales' ? null : ($to ?? $show), 'edit' => ($edit ?? $canEdit) ? 1 : null,
    ]));
    $money = fn (?float $value) => $value === null ? null : number_format($value, 2);
    $whole = fn (?float $value) => $value === null ? null : number_format($value);
    $aov = fn (?array $pos) => $pos !== null && $pos['orders'] > 0 ? $pos['sales'] / $pos['orders'] : null;
    $dash = '<span class="text-muted/60">—</span>';
    $grid = 'grid grid-cols-[13rem_repeat(12,minmax(5.25rem,1fr))_7.5rem]';
    $label = 'rounded px-1.5 text-[0.625rem] leading-4 font-bold tracking-wide uppercase';
    $rows = $cras->map(fn ($cra) => ['name' => $cra->displayName(), 'id' => $cra->id, 'typed' => $data['cras'][$cra->id], 'pos' => $data['pos'][$cra->id], 'acb' => $data['acb'][$cra->id]]);
@endphp

<section aria-labelledby="cra-gross-ytd" class="overflow-hidden rounded-2xl bg-white shadow-sm">
    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-line px-5 py-3">
        <h2 id="cra-gross-ytd" class="text-[0.9375rem] font-bold">{{ $year }} so far</h2>
        <span class="text-xs text-muted">Orders and AOV from POS to today · Gross and Net Income as typed</span>
    </div>
    <div class="x-scrollbar overflow-x-auto">
        <table class="min-w-full text-sm tabular-nums">
            <thead class="text-[0.6875rem] tracking-wide text-muted uppercase">
                <tr class="border-b border-line">
                    <th class="px-5 py-2.5 text-left font-bold">CRA name</th>
                    <th class="px-5 py-2.5 text-right font-bold">Gross</th>
                    <th class="px-5 py-2.5 text-right font-bold">Net Income</th>
                    <th class="bg-brand-50 px-5 py-2.5 text-right font-bold text-brand-700">Running # of Orders</th>
                    <th class="bg-brand-50 px-5 py-2.5 text-right font-bold text-brand-700">Running AOV</th>
                    <th class="px-5 py-2.5 text-right font-bold" title="(positive + negative Net Income so far) × 1.4%; January–May 2026 only months below ₱250,000">ACB</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-b border-line">
                        <td class="px-5 py-2.5 font-semibold whitespace-nowrap">{{ $row['name'] }}</td>
                        <td class="px-5 py-2.5 text-right">{!! $row['typed']['year'] === null ? $dash : e($money($row['typed']['year']['gross'])) !!}</td>
                        <td @class(['px-5 py-2.5 text-right font-semibold', 'text-coral-700' => ($row['typed']['year']['net'] ?? 0) < 0])>{!! $row['typed']['year'] === null ? $dash : e($money($row['typed']['year']['net'])) !!}</td>
                        <td class="bg-brand-50/60 px-5 py-2.5 text-right font-bold text-brand-700">{!! $row['pos']['year'] === null ? $dash : e(number_format($row['pos']['year']['orders'])) !!}</td>
                        <td class="bg-brand-50/60 px-5 py-2.5 text-right font-bold text-brand-700">{!! $aov($row['pos']['year']) === null ? $dash : e($money($aov($row['pos']['year']))) !!}</td>
                        @include('cra-gross-sales._acb', ['value' => $row['acb'], 'class' => 'px-5 font-semibold'])
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-muted">No active CRAs to show.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="font-bold">
                <tr class="border-t-2 border-ink">
                    <td class="px-5 py-2.5">TOTAL</td>
                    <td class="px-5 py-2.5 text-right">{!! $data['year'] === null ? $dash : e($money($data['year']['gross'])) !!}</td>
                    <td @class(['px-5 py-2.5 text-right', 'text-coral-700' => ($data['year']['net'] ?? 0) < 0])>{!! $data['year'] === null ? $dash : e($money($data['year']['net'])) !!}</td>
                    <td class="bg-brand-50/60 px-5 py-2.5 text-right text-brand-700">{!! $data['posYear'] === null ? $dash : e(number_format($data['posYear']['orders'])) !!}</td>
                    <td class="bg-brand-50/60 px-5 py-2.5 text-right text-brand-700">{!! $aov($data['posYear']) === null ? $dash : e($money($aov($data['posYear']))) !!}</td>
                    @include('cra-gross-sales._acb', ['value' => $data['acbTotal'], 'class' => 'px-5'])
                </tr>
            </tfoot>
        </table>
    </div>
</section>

<section aria-labelledby="cra-gross-months" class="overflow-hidden rounded-2xl bg-white shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
        <h2 id="cra-gross-months" class="text-[0.9375rem] font-bold">By month</h2>
        <nav aria-label="Figure shown" class="flex gap-1 rounded-[0.625rem] bg-[#f3eff6] p-1">
            @foreach ($shows as $key => $tab)
                <a href="{{ $link($year, null, $key) }}" @if ($show === $key) aria-current="page" @endif
                   @class(['inline-flex min-h-8 items-center rounded-md px-3.5 text-[0.8125rem] font-semibold', 'bg-white text-brand-700 shadow-sm' => $show === $key, 'text-muted hover:text-brand-700' => $show !== $key])>{{ $tab }}</a>
            @endforeach
        </nav>
        <span class="text-xs text-muted">
            @switch($show)
                @case('orders') From POS · canceled included @break
                @case('aov') From POS · sales ÷ orders @break
                @default Gross above, Net Income (NI) below · typed in
            @endswitch
        </span>
    </div>
    <div class="x-scrollbar overflow-x-auto">
        <div class="min-w-max text-[0.8125rem] tabular-nums" role="table" aria-label="{{ $shows[$show] }} by month">
            <div role="row" class="{{ $grid }} border-b border-line text-[0.6875rem] font-bold tracking-wide text-muted uppercase">
                <span role="columnheader" class="sticky left-0 bg-white px-4 py-2.5">CRA name</span>
                @foreach ($data['months'] as $month)
                    <span role="columnheader" class="px-2 py-2.5 text-right">{{ $month->format('M') }}</span>
                @endforeach
                <span role="columnheader" class="bg-brand-50 px-4 py-2.5 text-right text-brand-700">{{ $year }}</span>
            </div>

            @foreach ([...$rows->all(), ['name' => 'TOTAL', 'id' => null, 'typed' => ['months' => $data['totals'], 'year' => $data['year']], 'pos' => ['months' => $data['posTotals'], 'year' => $data['posYear']]]] as $row)
                @php
                    $isTotal = $row['id'] === null;
                @endphp
                <div role="row" @class([$grid, 'border-b border-line' => ! $isTotal, 'border-t-2 border-ink font-bold' => $isTotal])>
                    <span role="rowheader" class="sticky left-0 flex items-center justify-between gap-2 bg-white py-1.5 pr-2.5 pl-4">
                        <span class="font-semibold whitespace-nowrap">{{ $row['name'] }}</span>
                        <span class="flex flex-col items-end gap-0.5">
                            @if ($show === 'sales')
                                <span class="{{ $label }} bg-sky-50 text-sky-700">Gross</span>
                                <span class="{{ $label }} bg-[#f3eff6] text-muted">NI</span>
                            @else
                                <span class="{{ $label }} bg-brand-50 text-brand-700">{{ $shows[$show] }}</span>
                            @endif
                        </span>
                    </span>
                    @foreach ($data['months'] as $index => $month)
                        @php
                            $cell = $row['typed']['months'][$index];
                            $pos = $row['pos']['months'][$index];
                            $isTop = ! $isTotal && $cell !== null && $data['top'][$index] === $row['id'];
                        @endphp
                        <span role="cell" class="flex flex-col justify-center gap-0.5 px-2 py-1.5 text-right whitespace-nowrap">
                            @if ($show === 'sales' && $canEdit && ! $isTotal)
                                @foreach (['gross' => 'Gross', 'net' => 'Net Income'] as $field => $name)
                                    @php
                                        $typedValue = $data['typed'][$row['id']][$index][$field];
                                    @endphp
                                    <input type="text" inputmode="decimal" data-cra-gross-sales-input data-cra="{{ $row['id'] }}" data-month="{{ $month->format('Y-m') }}" data-field="{{ $field }}"
                                           value="{{ $typedValue === null ? '' : number_format($typedValue, 2) }}" placeholder="—"
                                           aria-label="{{ $name }} for {{ $row['name'] }}, {{ $month->format('F Y') }}"
                                           class="h-6 w-24 rounded-md border border-line bg-white px-1.5 text-right text-xs font-semibold tabular-nums placeholder:font-normal placeholder:text-muted/60 focus:border-brand-400 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                @endforeach
                            @elseif ($show === 'sales')
                                <span @class(['leading-tight', 'font-bold text-sky-700' => $isTop])>{!! $cell === null ? $dash : e($whole($cell['gross'])) !!}</span>
                                <span @class(['text-xs leading-tight font-semibold', 'text-muted' => $cell !== null && $cell['net'] >= ($isTotal ? 0 : $goal), 'text-coral-700' => $cell !== null && $cell['net'] < ($isTotal ? 0 : $goal)])>{!! $cell === null ? $dash : e($whole($cell['net'])) !!}</span>
                            @elseif ($show === 'orders')
                                <span class="leading-tight">{!! $pos === null ? $dash : e(number_format($pos['orders'])) !!}</span>
                            @else
                                <span class="leading-tight">{!! $aov($pos) === null ? $dash : e($money($aov($pos))) !!}</span>
                            @endif
                        </span>
                    @endforeach
                    <span role="cell" class="flex flex-col justify-center gap-0.5 bg-brand-50 px-4 py-1.5 text-right font-bold whitespace-nowrap">
                        @if ($show === 'sales')
                            <span class="leading-tight">{!! $row['typed']['year'] === null ? $dash : e($whole($row['typed']['year']['gross'])) !!}</span>
                            <span @class(['text-xs leading-tight', 'text-coral-700' => ($row['typed']['year']['net'] ?? 0) < 0])>{!! $row['typed']['year'] === null ? $dash : e($whole($row['typed']['year']['net'])) !!}</span>
                        @elseif ($show === 'orders')
                            <span class="leading-tight">{!! $row['pos']['year'] === null ? $dash : e(number_format($row['pos']['year']['orders'])) !!}</span>
                        @else
                            <span class="leading-tight">{!! $aov($row['pos']['year']) === null ? $dash : e($money($aov($row['pos']['year']))) !!}</span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </div>
    @if ($show === 'sales')
        <ul class="flex flex-wrap items-center gap-x-5 gap-y-1 border-t border-line px-5 py-2.5 text-xs text-muted" aria-label="Colour key">
            <li class="flex items-center gap-1.5"><span aria-hidden="true" class="size-2.5 rounded-full bg-sky-700"></span><span><span class="font-bold text-sky-700">Blue</span>: the month's top seller (most Gross Sales)</span></li>
            <li class="flex items-center gap-1.5"><span aria-hidden="true" class="size-2.5 rounded-full bg-coral-700"></span><span><span class="font-bold text-coral-700">Red</span>: Net Income below the ₱{{ number_format($goal) }} CRA Scorecard (Settings → Sales Goals)</span></li>
        </ul>
    @endif
</section>
