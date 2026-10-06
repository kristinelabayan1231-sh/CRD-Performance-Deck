@php
    $fmt = fn ($value, string $type = 'count') => $value === null ? '—' : match ($type) {
        'percent' => number_format($value * 100, 2).'%',
        'money' => '₱'.number_format($value, 2),
        default => number_format($value),
    };
    // Change from the period before: counts and money as amounts, rates in points.
    $delta = function ($now, $before, string $type = 'count') {
        if ($now === null || $before === null) {
            return ['text' => 'no comparison', 'dir' => 'flat'];
        }
        $d = $type === 'percent' ? ($now - $before) * 100 : $now - $before;
        $dir = abs($d) < 0.005 ? 'flat' : ($d > 0 ? 'up' : 'down');
        $amount = match ($type) {
            'percent' => number_format(abs($d), 1).' pts',
            'money' => '₱'.number_format(abs($d), 2),
            default => number_format(abs($d)),
        };

        return ['text' => ($d > 0 ? '+' : ($d < 0 ? '−' : '±')).$amount, 'dir' => $dir];
    };
    $deltaClass = fn (array $d) => match ($d['dir']) { 'up' => 'text-teal-700', 'down' => 'text-coral-700', default => 'text-muted' };
    $arrow = fn (array $d) => match ($d['dir']) { 'up' => '▲', 'down' => '▼', default => '' };
    $query = fn (array $extra) => route('conversion.index', array_filter([...$filters, ...$extra], fn ($v) => $v !== null && $v !== []));
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $prevName = ['day' => 'the day before', 'week' => 'the week before', 'month' => 'the month before'][$view];
    $maxReach = max(1, $rows->max(fn ($r) => max($r['now']['engagements'], $r['now']['leads'])) ?? 1);
@endphp

<x-layouts.app title="Conversion Breakdown">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Conversion Breakdown <span class="text-sm font-normal text-muted">· {{ $period['label'] }} <span class="text-muted/80">· compared with {{ $compare['label'] }}</span></span></h1>
            <div class="flex flex-wrap items-center gap-3 text-xs text-muted">
                <span>
                    @if ($syncing)
                        Pancake syncing {{ $syncDay->format('M j') }}… refresh in a few minutes.
                    @else
                        Pancake synced {{ $lastSync ? $lastSync->timezone(config('segmentation.timezone'))->format('M j, g:i A') : 'never for '.$syncDay->format('M j') }}
                    @endif
                </span>
                @if ($canSync && ! $syncing)
                    <form method="POST" action="{{ route('conversion.sync') }}">
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
                No Pancake account set for {{ $missingAccounts->map->displayName()->join(', ', ' and ') }}, so their engagements and tagged orders show as 0.
                @can('user_access.manage') <a href="{{ route('user-access.index') }}" class="font-semibold text-brand-600 hover:underline">Set it in User Access</a>. @endcan
            </div>
        @endif

        {{-- Filters --}}
        <form method="GET" action="{{ route('conversion.index') }}" class="flex flex-wrap items-end gap-x-6 gap-y-4 rounded-xl bg-white p-4 shadow-sm">
            <input type="hidden" name="sort" value="{{ $sort }}">
            @if ($canViewAll)
                <fieldset class="min-w-0">
                    <legend class="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">CRA</legend>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($allCras as $cra)
                            <label class="cursor-pointer">
                                <input type="checkbox" name="cras[]" value="{{ $cra->id }}" class="peer sr-only" onchange="this.form.submit()" @checked(in_array($cra->id, $selected))>
                                <span class="block rounded-full border border-line px-3 py-1 text-sm text-muted transition peer-checked:border-ink/40 peer-checked:bg-canvas peer-checked:text-ink peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200">{{ $cra->displayName() }}</span>
                            </label>
                        @endforeach
                        <a href="{{ $query(['cras' => null]) }}" class="rounded-full border border-dashed border-line px-3 py-1 text-sm text-muted hover:text-brand-600">All</a>
                    </div>
                </fieldset>
            @endif

            <fieldset>
                <legend class="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">View by</legend>
                <div class="flex rounded-lg border border-line p-0.5 text-sm font-semibold">
                    @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $value => $label)
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
                    <input type="date" name="date" value="{{ $period['from']->toDateString() }}" max="{{ $today->toDateString() }}" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                </label>
            @else
                <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    Month
                    <input type="month" name="month" value="{{ $period['month']->format('Y-m') }}" max="{{ $today->format('Y-m') }}" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                </label>
                @if ($view === 'week')
                    <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                        Week
                        <select name="week" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                            @foreach ($weeks as $w)
                                <option value="{{ $w['number'] }}" @selected($w['number'] === $period['number'])>Week {{ $w['number'] }} · {{ $w['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            @endif
            <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">Apply</button></noscript>
        </form>

        {{-- Team scorecard --}}
        <section aria-label="Team totals" class="grid grid-cols-2 overflow-hidden rounded-xl bg-white shadow-sm sm:grid-cols-4 xl:grid-cols-8">
            @foreach ([
                ['Orders BC', 'bc_orders', 'count'],
                ['Engagements', 'engagements', 'count'],
                ['BC conv %', 'bc_rate', 'percent'],
                ['Orders SC', 'sc_orders', 'count'],
                ['Leads', 'leads', 'count'],
                ['SC conv %', 'sc_rate', 'percent'],
                ['Total conv %', 'total_rate', 'percent'],
                ['Gross sales', 'gross', 'money'],
            ] as [$label, $key, $type])
                @php($d = $delta($total[$key], $totalBefore[$key], $type))
                <div class="flex min-w-0 flex-col gap-0.5 border-r border-b border-line p-4">
                    <span class="text-[11px] font-semibold tracking-wide text-muted uppercase">{{ $label }}</span>
                    <span class="text-xl font-bold tabular-nums" title="{{ $fmt($total[$key], $type) }}">{{ $type === 'money' ? '₱'.number_format($total[$key]) : $fmt($total[$key], $type) }}</span>
                    <span class="text-xs font-semibold tabular-nums {{ $deltaClass($d) }}">{{ $arrow($d) }} {{ $d['text'] }}</span>
                </div>
            @endforeach
        </section>

        {{-- Funnel board: one row per CRA --}}
        <section class="overflow-hidden rounded-xl bg-white shadow-sm" aria-labelledby="board-title">
            <header class="flex flex-wrap items-center justify-between gap-3 px-4 pt-4 pb-3">
                <div>
                    <h2 id="board-title" class="font-semibold">Conversion Breakdown per CRA · {{ $period['label'] }}</h2>
                    <p class="text-xs text-muted">Changes are against {{ $prevName }}. Click a column heading to sort. Leads base {{ $base }} per CRA per day; the actual count is shown.</p>
                </div>
                <span class="flex flex-wrap gap-3 text-xs text-muted">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-teal-700"></span>Broadcast (BC)</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-brand-600"></span>Segmentation (SC)</span>
                </span>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1180px] text-sm">
                    <thead class="bg-canvas/60 text-[11px] tracking-wide text-muted uppercase">
                        <tr class="border-b border-line">
                            <th class="px-4 pt-2.5 text-left font-semibold" rowspan="2">CRA</th>
                            <th class="px-3 pt-2.5 text-left font-semibold" rowspan="2">Funnel</th>
                            <th class="border-l border-line px-3 pt-2.5 text-center font-semibold text-teal-700" colspan="3">Broadcast conversion</th>
                            <th class="border-l border-line px-3 pt-2.5 text-center font-semibold text-brand-600" colspan="3">Segmentation conversion</th>
                            <th class="border-l border-line px-3 pt-2.5 text-center font-semibold" colspan="5">Total</th>
                        </tr>
                        <tr>
                            @foreach (['engagements', 'bc_orders', 'bc_rate', 'leads', 'sc_orders', 'sc_rate', 'total_rate', 'bc_gross', 'sc_gross', 'gross'] as $key)
                                <th @class(['px-3 py-2 text-right font-semibold whitespace-nowrap', 'border-l border-line' => in_array($key, ['engagements', 'leads', 'total_rate'])])>
                                    <a href="{{ $query(['sort' => $key]) }}" @if ($sort === $key) aria-sort="descending" @endif
                                       @class(['hover:text-brand-600', 'text-ink' => $sort === $key])>{{ $sorts[$key][0] }}{{ $sort === $key ? ' ▾' : '' }}</a>
                                </th>
                            @endforeach
                            <th class="px-4 py-2 text-left font-semibold">BC vs SC</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line whitespace-nowrap tabular-nums">
                        @forelse ($rows as $row)
                            @php($n = $row['now'])
                            @php($b = $row['before'])
                            <tr class="hover:bg-canvas/40">
                                <td class="px-4 py-3 font-medium">{{ $row['cra']->displayName() }}</td>
                                <td class="px-3 py-3">
                                    {{-- Each bar's length is the reach (engagements or leads); the dark part is the orders. --}}
                                    <div class="w-36 space-y-1.5 text-[11px] text-muted">
                                        @foreach ([['BC', $n['engagements'], $n['bc_orders'], 'bg-teal-700', 'bg-teal-700/20', 'engagements'], ['SC', $n['leads'], $n['sc_orders'], 'bg-brand-600', 'bg-brand-600/20', 'leads']] as [$tag, $reach, $orders, $dark, $light, $noun])
                                            <div class="grid grid-cols-[18px_1fr] items-center gap-1.5" title="{{ $tag }}: {{ number_format($reach) }} {{ $noun }} → {{ number_format($orders) }} orders">
                                                <span>{{ $tag }}</span>
                                                <span class="relative block h-2 rounded-r bg-canvas">
                                                    <span class="absolute inset-y-0 left-0 rounded-r {{ $light }}" style="width: {{ min(100, $reach / $maxReach * 100) }}%"></span>
                                                    <span class="absolute inset-y-0 left-0 rounded-r {{ $dark }}" style="width: {{ min(100, $orders / $maxReach * 100) }}%; min-width: {{ $orders ? '2px' : '0' }}"></span>
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                @foreach (['engagements', 'bc_orders', 'bc_rate', 'leads', 'sc_orders', 'sc_rate', 'total_rate', 'bc_gross', 'sc_gross', 'gross'] as $key)
                                    @php($type = $sorts[$key][1])
                                    @php($d = $delta($n[$key], $b[$key], $type))
                                    <td @class(['px-3 py-3 text-right', 'border-l border-line' => in_array($key, ['engagements', 'leads', 'total_rate']), 'font-semibold' => $type === 'percent' || $key === 'gross'])>
                                        {{ $fmt($n[$key], $type) }}
                                        @if (($type === 'percent' || $key === 'gross') && $d['text'] !== 'no comparison')
                                            <span class="block text-[11px] font-medium {{ $deltaClass($d) }}">{{ $arrow($d) }} {{ $d['text'] }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-4 py-3">
                                    @php($share = $n['orders'] ? $n['bc_orders'] / $n['orders'] : null)
                                    <div class="flex h-2 w-28 gap-0.5" title="{{ $n['bc_orders'] }} broadcast · {{ $n['sc_orders'] }} segmentation orders">
                                        @if ($share === null)
                                            <span class="flex-1 rounded-sm bg-canvas"></span>
                                        @else
                                            @if ($n['bc_orders'])<span class="rounded-sm bg-teal-700" style="width: {{ $share * 100 }}%"></span>@endif
                                            @if ($n['sc_orders'])<span class="flex-1 rounded-sm bg-brand-600"></span>@endif
                                        @endif
                                    </div>
                                    <span class="mt-1 block text-[11px] text-muted">{{ $n['bc_orders'] }} BC · {{ $n['sc_orders'] }} SC</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="13" class="px-4 py-8 text-center text-muted">No active CRAs yet. Give users the CRA role in User Access.</td></tr>
                        @endforelse
                        @if ($rows->isNotEmpty())
                            <tr class="bg-ink font-semibold text-white">
                                <td class="px-4 py-3">TOTAL</td>
                                <td class="px-3 py-3 text-xs font-medium text-white/80">{{ number_format($total['reach']) }} reached → {{ number_format($total['orders']) }} orders</td>
                                @foreach (['engagements', 'bc_orders', 'bc_rate', 'leads', 'sc_orders', 'sc_rate', 'total_rate', 'bc_gross', 'sc_gross', 'gross'] as $key)
                                    <td @class(['px-3 py-3 text-right', 'border-l border-white/20' => in_array($key, ['engagements', 'leads', 'total_rate'])])>{{ $fmt($total[$key], $sorts[$key][1]) }}</td>
                                @endforeach
                                <td class="px-4 py-3 text-xs">{{ $total['bc_orders'] }} BC · {{ $total['sc_orders'] }} SC</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </section>

        <details class="rounded-xl bg-white p-4 text-sm shadow-sm">
            <summary class="cursor-pointer font-semibold">How the numbers are worked out</summary>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-muted sm:grid-cols-2">
                <div><dt class="font-semibold text-ink">Total # of orders BC (Broadcast Conversion)</dt><dd>The CRA's own Pancake POS orders that day tagged CRD - BROADCAST. Canceled and deleted orders don't count.</dd></div>
                <div><dt class="font-semibold text-ink">Total # of orders SC (Segmentation Conversion)</dt><dd>The CRA's own Pancake POS orders that day tagged CRD - SEGMENTATION. An order with both tags counts here.</dd></div>
                <div><dt class="font-semibold text-ink">Total # of engagements (Broadcast Conversion)</dt><dd>The CRA's Pancake customer engagements (Chat → Analytics → Engagements) across all pages.</dd></div>
                <div><dt class="font-semibold text-ink">Total # of leads (Segmentation Conversion)</dt><dd>Segmentation Tracker leads assigned to the CRA for that lead day. The base is {{ $base }}; the actual count is used.</dd></div>
                <div><dt class="font-semibold text-ink">BC conv % and SC conv %</dt><dd>Orders BC ÷ Engagements, and Orders SC ÷ Leads.</dd></div>
                <div><dt class="font-semibold text-ink">Total conv %</dt><dd>(Orders BC + Orders SC) ÷ (Engagements + Leads).</dd></div>
                <div><dt class="font-semibold text-ink">Gross sales</dt><dd>Gross BC (totals of the CRD - BROADCAST orders) + Gross SC (totals of the CRD - SEGMENTATION orders). Segmentation Productivity's Gross sales use the same numbers.</dd></div>
                <div><dt class="font-semibold text-ink">Weeks and months</dt><dd>Weeks run 1–7, 8–14… from the 1st, as in Weekly Segmentation. Rates for a week or month are worked out from the totals, not averaged.</dd></div>
            </dl>
        </details>
    </div>
</x-layouts.app>
