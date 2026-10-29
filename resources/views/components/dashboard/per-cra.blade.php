@props(['results'])

@php
    $peso = fn (float $value) => '₱'.number_format($value);
    // Short amounts for tight rows: ₱77k, ₱1.2M.
    $short = fn (float $value) => '₱'.($value >= 1e6 ? rtrim(rtrim(number_format($value / 1e6, 2), '0'), '.').'M' : ($value >= 1e3 ? rtrim(rtrim(number_format($value / 1e3, 1), '0'), '.').'k' : number_format($value)));
    $pct = fn (?float $value, int $decimals = 1) => $value === null ? '—' : number_format($value * 100, $decimals).'%';
    $range = $results['range'];
    $topRate = max(0.0001, $results['cras']->max(fn ($row) => $row['totals']['total_rate'] ?? 0) ?? 0);
@endphp

{{--
    Per CRA for the dashboard's month (to date) or range: sales against their goal (daily goal × days, following who was on duty: DutyGoals)
    and Conv % as Conversion Breakdown: (Broadcast + Segmentation orders) ÷ (engagements + leads). Goal bars brand purple (teal once hit);
    conversion bars sky, scaled to the highest rate.
--}}
<x-panel {{ $attributes }} title="Goal & conversion per CRA" accent="teal" icon="chart" title-id="per-cra-title">
    <x-slot:badges>
        <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-teal-700 shadow-sm">{{ $range->label() }}</span>
        @if ($leadsFrom = \App\Support\WorkingDate::leadDaysLabel($range->from, $range->to))
            <span class="rounded-full bg-[#fff6d6] px-2 py-0.5 text-[0.625rem] font-semibold text-[#473821]" title="Pancake sales on these days, made while working these lead days.">leads from {{ $leadsFrom }}</span>
        @endif
    </x-slot:badges>
    <x-slot:actions>
        @can('sales_goals.manage')
            <a href="{{ route('settings.sales-goals.index') }}" class="font-semibold text-muted hover:text-brand-600">Set goals</a>
        @endcan
        <a href="{{ route('conversion.index') }}" class="font-semibold text-brand-600 hover:underline">Conversion Breakdown &rarr;</a>
    </x-slot:actions>

    <div class="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
        <table class="w-full min-w-[51.25rem] text-sm">
            <thead class="text-[0.6875rem] tracking-wide text-muted uppercase">
                <tr>
                    <th class="pb-2 text-left font-semibold">CRA</th>
                    <th class="pb-2 text-left font-semibold" colspan="2">Goal · {{ $short($results['general_daily']) }}/day{{ $range->days() > 1 ? ' × '.$range->days() : '' }}</th>
                    <th class="pb-2 text-right font-semibold">Sales / goal</th>
                    <th class="pb-2 text-right font-semibold">Left</th>
                    <th class="pb-2 pl-6 text-left font-semibold" colspan="2">Conv %</th>
                    <th class="pb-2 pl-6 text-left font-semibold">Top product sales</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($results['cras'] as $row)
                    @php($hit = $row['progress'] !== null && $row['progress'] >= 1)
                    @php($left = max(0, $row['goal'] - $row['sales']))
                    @php($t = $row['totals'])
                    <tr class="border-t border-line/60">
                        <td class="max-w-40 py-2 pr-3 font-medium" title="{{ $row['cra']->displayName() }}">
                            <span class="block truncate">{{ $row['cra']->displayName() }}</span>
                            {{-- Days off: no goal those days; on duty: a share of the goals of CRAs off (DutyGoals) --}}
                            <x-day-off-label :rest="$row['rest_days']" :leave="$row['leave_days']" :counts="$range->days() > 1" class="mt-0.5" />
                            @if ($row['shared'] > 0)
                                <span class="block text-[0.6875rem] font-normal whitespace-nowrap text-muted">+{{ $short($row['shared']) }} from CRAs off</span>
                            @endif
                        </td>
                        <td class="w-full min-w-24 py-2 pr-2">
                            <span class="relative block h-2 rounded-full bg-canvas" role="progressbar" aria-label="{{ $row['cra']->displayName() }} goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round(min(1, $row['progress'] ?? 0) * 100) }}">
                                <span @class(['absolute inset-y-0 left-0 rounded-full', 'bg-teal-700' => $hit, 'bg-brand-600' => ! $hit]) style="width: {{ min(100, ($row['progress'] ?? 0) * 100) }}%"></span>
                            </span>
                        </td>
                        <td @class(['w-14 py-2 text-right font-semibold tabular-nums', 'text-teal-700' => $hit])>{{ $pct($row['progress']) }}</td>
                        <td class="py-2 pl-3 text-right text-xs whitespace-nowrap text-muted tabular-nums" title="{{ $peso($row['sales']) }} of {{ $peso($row['goal']) }}">
                            {{ $short($row['sales']) }} / {{ $short($row['goal']) }}@if ($row['own_goal'])<span title="This CRA has their own daily goal">*</span>@endif
                        </td>
                        {{-- Sales still needed to reach the goal --}}
                        <td @class(['py-2 pl-3 text-right text-xs font-semibold whitespace-nowrap tabular-nums', 'text-teal-700' => $left <= 0, 'text-coral-700' => $left > 0])>{{ $left > 0 ? $short($left).' left' : 'Goal hit' }}</td>
                        <td class="w-full min-w-20 py-2 pr-2 pl-6" title="{{ number_format($t['orders']) }} orders ÷ {{ number_format($t['reach']) }} engagements + leads">
                            <span class="relative block h-2 rounded-full bg-canvas">
                                <span class="absolute inset-y-0 left-0 rounded-full bg-[#1f8fb8]" style="width: {{ min(100, ($t['total_rate'] ?? 0) / $topRate * 100) }}%"></span>
                            </span>
                        </td>
                        <td class="w-16 py-2 text-right font-semibold tabular-nums">{{ $pct($t['total_rate'], 2) }}</td>
                        {{-- Best-selling product in their gross sales, e.g. CanPro ₱20,000 --}}
                        <td class="py-2 pl-6 text-sm whitespace-nowrap" @if ($row['top_product']) title="{{ $row['top_product']['name'] }}: {{ $peso($row['top_product']['amount']) }} of {{ $peso($row['sales']) }} gross sales" @endif>
                            @if ($row['top_product'])
                                <span class="font-medium">{{ $row['top_product']['name'] }}</span>
                                <span class="ml-1 font-semibold tabular-nums">{{ $peso($row['top_product']['amount']) }}</span>
                            @else
                                <span class="text-xs text-muted">No sales yet</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-4 text-sm text-muted">No active CRAs yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="mt-2 text-[0.6875rem] text-muted">
            Conv % = (Broadcast + Segmentation orders) ÷ (engagements + leads), as in Conversion Breakdown. Top product sales = the product with the most of their gross sales.
            @if ($results['cras']->contains('own_goal', true)) * Own daily goal set in Settings → Sales Goals. @endif
            @if ($results['cras']->contains(fn ($row) => $row['absent_days'] > 0)) Rest Day / Absent / Leave = a day off set in My schedule, or not logged in on duty while others were: no goal that day; their daily goal is split equally among the CRAs on duty. @endif
        </p>
    </div>
</x-panel>
