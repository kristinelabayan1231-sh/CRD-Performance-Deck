{{--
    One Report Summary table: a row per CRA and the TOTAL row; the daily one also has the three per-CRA lines,
    as in the CRD sheet.
    Both sections use the same fixed column widths, so each daily figure sits right under its weekly one.
    $report: ReportSummary::for(); $perCra: show the per-CRA lines (daily only).
--}}
@php
    $money = fn ($value) => $value === null ? '—' : ($value < 0 ? '−' : '').'₱'.number_format(abs($value), 2);
    // key, label, sub-label, kind, column width
    $columns = [
        ['bc_orders', 'Orders', 'Broadcast', 'count', 'w-[6rem]'],
        ['sc_orders', 'Orders', 'Segmentation', 'count', 'w-[7rem]'],
        ['bc_gross', 'Gross sales', 'Broadcast', 'money', 'w-[8.25rem]'],
        ['sc_gross', 'Gross sales', 'Segmentation', 'money', 'w-[8.75rem]'],
        ['gross', 'Total gross', 'BC & SC', 'money', 'w-[9.25rem]'],
        ['is_leads', 'Leads', null, 'count', 'w-[6.75rem]'],
        ['is_engagements', 'Engagement', null, 'count', 'w-[7.25rem]'],
        ['conv', 'Conv %', null, 'percent', 'w-[5.625rem]'],
        ['aov', 'AOV', null, 'money', 'w-[7.25rem]'],
        ['net_income', 'Net income', 'Daily I.S.', 'money', 'w-[8.25rem]'],
        ['ibsc', 'I.BSC %', null, 'percent', 'w-[5.625rem]'],
    ];
    // Group labels are sticky, so they stay centered in view instead of sliding under the pinned Team column.
    // The first column of each group (Orders & sales, Reach & conversion, Profit) gets a divider on its left.
    $groupStarts = ['bc_orders', 'is_leads', 'net_income'];
    $cell = fn (array $m, array $col) => match ($col[3]) {
        'count' => number_format($m[$col[0]]),
        'money' => $money($m[$col[0]]),
        'percent' => $m[$col[0]] === null ? '—' : number_format($m[$col[0]] * 100, 2).'%',
        default => '—',
    };
    $onDuty = $report['on_duty'];
    $teamCell = 'sticky left-0 z-10 border-r border-line';
@endphp

<div class="overflow-x-auto">
    <table class="w-full min-w-[93.5rem] table-fixed text-sm">
        <colgroup>
            <col class="w-[12.5rem]">
            @foreach ($columns as $col)
                <col class="{{ $col[4] }}">
            @endforeach
        </colgroup>
        <thead class="text-[0.6875rem] tracking-wide text-muted uppercase">
            <tr>
                <th rowspan="2" class="{{ $teamCell }} border-b border-b-ink bg-ink px-4 py-2 text-left align-bottom font-semibold text-white">Team</th>
                <th colspan="5" class="border-l border-line bg-[#e3f6f2] px-3 py-1 text-center text-[0.625rem] font-bold tracking-widest text-teal-700"><span class="sticky right-3 left-[13.25rem] inline-block">Orders &amp; sales</span></th>
                <th colspan="4" class="border-l border-line bg-brand-50 px-3 py-1 text-center text-[0.625rem] font-bold tracking-widest text-brand-700"><span class="sticky right-3 left-[13.25rem] inline-block">Reach &amp; conversion</span></th>
                <th colspan="2" class="border-l border-line bg-[#fdf3dc] px-3 py-1 text-center text-[0.625rem] font-bold tracking-widest text-[#8a5f06]"><span class="sticky right-3 left-[13.25rem] inline-block">Profit</span></th>
            </tr>
            <tr class="border-b border-line align-bottom">
                @foreach ($columns as $col)
                    <th @class(['px-3 py-2 text-right leading-tight font-semibold', 'border-l border-line' => in_array($col[0], $groupStarts), 'text-ink' => $col[0] === 'gross'])>
                        {{ $col[1] }}
                        @if ($col[2])
                            <span class="block font-medium tracking-normal normal-case">{{ $col[2] }}</span>
                        @endif
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-line whitespace-nowrap tabular-nums">
            @forelse ($report['rows'] as $row)
                <tr class="hover:bg-canvas/40">
                    <td class="{{ $teamCell }} truncate bg-white px-4 py-2.5 font-medium" title="{{ $row['cra']->displayName() }}">{{ $row['cra']->displayName() }}</td>
                    @foreach ($columns as $col)
                        @php($value = $row['metrics'][$col[0]] ?? null)
                        <td @class([
                            'px-3 py-2.5 text-right',
                            'border-l border-line' => in_array($col[0], $groupStarts),
                            'font-semibold' => $col[0] === 'gross',
                            'text-muted' => ! $value,
                            'text-coral-700' => $value < 0,
                        ])>{{ $cell($row['metrics'], $col) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="12" class="px-4 py-8 text-center text-muted">No active CRAs yet. Give users the CRA role in User Access.</td></tr>
            @endforelse
            @if ($report['rows']->isNotEmpty())
                <tr class="bg-ink font-semibold text-white">
                    <td class="sticky left-0 z-10 border-r border-white/15 bg-ink px-4 py-2.5">TOTAL</td>
                    @foreach ($columns as $col)
                        <td @class(['px-3 py-2.5 text-right', 'border-l border-white/15' => in_array($col[0], $groupStarts), 'text-[#ffb4b6]' => ($report['total'][$col[0]] ?? 0) < 0])>{{ $cell($report['total'], $col) }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>

        {{-- The sheet's three per-CRA lines, in the same columns as the table --}}
        @if ($perCra)
        <tfoot class="divide-y divide-line border-t border-line tabular-nums">
            <tr>
                <th rowspan="3" scope="rowgroup" class="{{ $teamCell }} bg-brand-50 px-4 py-2 text-left align-top text-[0.6875rem] font-semibold tracking-wide text-brand-700 uppercase">Per CRA</th>
                <td colspan="8" class="px-3 py-2 text-right text-muted">Average IBSC per CRA <span class="text-xs">(Net income ÷ CRAs on duty)</span></td>
                <td colspan="3" @class(['border-l border-line px-3 py-2 text-right font-bold', 'text-coral-700' => ($report['average_ibsc'] ?? 0) < 0])>{{ $money($report['average_ibsc']) }}</td>
            </tr>
            <tr>
                <td colspan="8" class="px-3 py-2 text-right text-muted">No. of Customer Retention Associates (On Duty)</td>
                <td colspan="3" class="border-l border-line px-3 py-2 text-right font-semibold">
                    @if ($onDuty === null)
                        <span class="font-normal text-muted">No log-ins yet</span>
                    @else
                        {{ number_format($onDuty) }}
                    @endif
                </td>
            </tr>
            <tr>
                <td colspan="8" class="px-3 py-2 text-right text-muted">Average Sales per Customer Retention Associate <span class="text-xs">(Gross sales BC ÷ CRAs on duty)</span></td>
                <td colspan="3" class="border-l border-line px-3 py-2 text-right font-bold">{{ $money($report['average_sales']) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>
