@php
    $peso = fn (float $value) => '₱'.number_format($value);
    $tz = config('segmentation.timezone');
    $fetchedAt = \Carbon\CarbonImmutable::parse($snapshot['fetched_at'])->timezone($tz);
    $team = $snapshot['team'];
    $products = $snapshot['products'];
    $topProduct = collect($products)->except(\App\Services\RunningSales::OTHERS)->keys()->first();
    $days = $snapshot['days'];
    // Matrix shading: the bigger a cell against the biggest one, the darker its purple.
    $cellMax = max(1, collect($snapshot['cras'])->flatMap(fn (array $row) => collect($row['products'])->pluck($show))->max() ?? 0);
    $tile = 'flex flex-col gap-1 rounded-2xl border border-line bg-white p-4 shadow-sm';
    $tileLabel = 'text-[0.6875rem] font-semibold tracking-wide text-muted uppercase';
    // Off (Rest Day or Absent / Leave) on every day so far; their goal is split among the CRAs on duty (DutyGoals).
    $absent = collect($snapshot['cras'])->filter(fn (array $row) => $row['absent_days'] === $days);
    $onDutyCount = count($snapshot['cras']) - $absent->count();
@endphp

<x-layouts.app title="Running Sales">
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-semibold">Running Sales</h1>
                    <span class="rounded-full bg-brand-100 px-2.5 py-0.5 text-xs font-semibold text-brand-700">{{ $snapshot['label'] }}</span>
                </div>
                <p class="text-sm text-muted">Every CRA's sales so far from Pancake POS, overall and per product, against their daily goal.</p>
            </div>
            <nav class="flex rounded-lg border border-line bg-white p-0.5 text-sm font-semibold" aria-label="Period">
                @foreach (\App\Services\RunningSales::PERIODS as $value => $label)
                    <a href="{{ route('running-sales.index', ['period' => $value, 'show' => $show]) }}" @if ($period === $value) aria-current="page" @endif
                       @class(['rounded-md px-3 py-1.5', 'bg-brand-600 text-white' => $period === $value, 'text-muted hover:text-brand-600' => $period !== $value])>{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <section aria-label="Data freshness" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-white px-4 py-3 text-sm shadow-sm">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span aria-hidden="true" class="size-2 rounded-full bg-teal"></span>
                <span class="font-semibold">Updated {{ $fetchedAt->format('g:i A') }}</span>
                <span class="text-muted">· fetches every {{ \App\Services\RunningSales::EVERY_HOURS }} hours · next at {{ $nextFetch->format('g:i A') }}</span>
            </div>
            <form method="POST" action="{{ route('running-sales.refresh') }}">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}">
                <input type="hidden" name="show" value="{{ $show }}">
                <button type="submit" class="flex h-10 items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-4 font-semibold text-brand-700 hover:border-brand-400">
                    <svg aria-hidden="true" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 4v5h-5"/></svg>
                    Refresh now
                </button>
            </form>
        </section>

        @if ($missingAccounts->isNotEmpty())
            <div role="status" class="rounded-lg border border-line bg-white px-4 py-3 text-sm">
                No Pancake account set for {{ $missingAccounts->map->displayName()->join(', ', ' and ') }}, so their sales show as 0.
                @can('user_access.manage') <a href="{{ route('user-access.index') }}" class="font-semibold text-brand-600 hover:underline">Set it in User Access</a>. @endcan
            </div>
        @endif

        <section aria-label="Team totals" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="{{ $tile }}">
                <span class="{{ $tileLabel }}">Running sales</span>
                <span class="text-2xl font-bold text-ink">{{ $peso($team['sales']) }}</span>
                <span class="text-xs font-semibold text-teal-700">
                    {{ $team['goal'] > 0 ? round($team['sales'] / $team['goal'] * 100).'% of '.$peso($team['goal']).' team goal' : 'No goal set' }}
                </span>
            </div>
            <div class="{{ $tile }}">
                <span class="{{ $tileLabel }}">Orders</span>
                <span class="text-2xl font-bold text-ink">{{ number_format($team['orders']) }}</span>
                <span class="text-xs text-muted">canceled and deleted left out</span>
            </div>
            <div class="{{ $tile }}">
                <span class="{{ $tileLabel }}">Avg order value</span>
                <span class="text-2xl font-bold text-ink">{{ $team['orders'] ? $peso($team['sales'] / $team['orders']) : '—' }}</span>
                <span class="text-xs text-muted">across {{ count($snapshot['cras']) }} CRAs</span>
            </div>
            <div class="{{ $tile }}">
                <span class="{{ $tileLabel }}">Top product</span>
                <span class="truncate text-2xl font-bold text-ink">{{ $topProduct ?? '—' }}</span>
                <span class="text-xs text-muted">
                    @if ($topProduct)
                        {{ $peso($products[$topProduct]['sales']) }} · {{ $team['sales'] > 0 ? round($products[$topProduct]['sales'] / $team['sales'] * 100) : 0 }}% of sales
                    @else
                        no sales yet
                    @endif
                </span>
            </div>
        </section>

        <x-panel title="CRA running sales" icon="users" title-id="cras-title" :tinted="false" body-class="">
            <x-slot:actions>
                <span class="text-muted">
                    @if ($absent->isNotEmpty())
                        <span class="mr-1 rounded-full bg-canvas px-2 py-0.5 text-[0.6875rem] font-semibold text-ink">{{ $absent->count() }} off</span>
                    @endif
                    Daily goal per CRA{{ $days > 1 ? ' × '.$days.' days' : '' }}{{ collect($snapshot['cras'])->contains(fn (array $row) => $row['absent_days'] > 0) ? ' · goals of CRAs off split with those on duty' : '' }}
                    @can('sales_goals.manage')
                        · <a href="{{ route('settings.sales-goals.index') }}" class="font-semibold text-brand-600 hover:underline">Set goals</a>
                    @endcan
                </span>
            </x-slot:actions>

            <ol class="grid divide-line sm:grid-cols-2 sm:divide-x lg:grid-cols-5">
                @forelse ($snapshot['cras'] as $rank => $row)
                    @php
                        $progress = $row['goal'] > 0 ? $row['sales'] / $row['goal'] : null;
                        $left = $row['goal'] - $row['sales'];
                        $isAbsent = $absent->has($rank);
                    @endphp
                    <li class="flex flex-col gap-2.5 border-b border-line p-4 lg:border-b-0">
                        <div class="flex items-center gap-2.5">
                            @if ($users->has($row['id']))
                                <x-avatar :user="$users[$row['id']]" class="size-9" />
                            @endif
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-ink">{{ $row['name'] }}</p>
                                @if (! $isAbsent)
                                    <p class="text-xs text-muted">#{{ $rank + 1 }} · {{ $row['orders'] }} {{ Str::plural('order', $row['orders']) }}</p>
                                @endif
                                <x-day-off-label :rest="$row['rest_days']" :leave="$row['leave_days']" :counts="! $isAbsent" class="mt-0.5" />
                            </div>
                        </div>
                        <p class="text-xl font-bold text-ink tabular-nums">{{ $peso($row['sales']) }}</p>
                        <div class="h-2 overflow-hidden rounded-full bg-canvas" role="progressbar" aria-label="{{ $row['name'] }}'s goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress === null ? 0 : min(100, round($progress * 100)) }}">
                            <div @class(['h-full rounded-full', 'bg-teal' => $progress >= 1, 'bg-brand-600' => $progress < 1]) style="width: {{ $progress === null ? 0 : min(100, round($progress * 100, 1)) }}%"></div>
                        </div>
                        <div class="flex justify-between gap-2 text-xs text-muted">
                            <span>{{ $days > 1 ? 'Goal' : 'Daily goal' }} <strong class="text-ink">{{ $peso($row['goal']) }}</strong></span>
                            <span @class(['font-semibold', 'text-teal-700' => $progress >= 0.75, 'text-coral-700' => $progress !== null && $progress < 0.6])>{{ $progress === null ? '—' : round($progress * 100).'%' }}</span>
                        </div>
                        @if ($row['shared'] > 0)
                            <p class="-mt-1 text-[0.6875rem] text-muted">
                                {{ $peso($row['goal'] - $row['shared']) }} + <span class="font-semibold text-ink">{{ $peso($row['shared']) }}</span>
                                from {{ $days === 1 ? $absent->map(fn (array $r) => $r['name'].' ('.($r['rest_days'] ? 'Rest Day' : 'Absent').')')->join(', ', ' and ') : 'CRAs off' }}
                            </p>
                        @endif
                        @if ($isAbsent)
                            <div class="rounded-lg bg-canvas px-3 py-2">
                                <p class="text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">Goal shared</p>
                                <p class="text-sm font-semibold text-ink">Split with the {{ $onDutyCount }} {{ Str::plural('CRA', $onDutyCount) }} on duty</p>
                            </div>
                        @elseif ($left > 0)
                            <div class="rounded-lg bg-brand-50 px-3 py-2">
                                <p class="text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">Left to reach goal</p>
                                <p class="text-base font-bold text-brand-700 tabular-nums">{{ $peso($left) }}</p>
                            </div>
                        @elseif ($row['goal'] > 0)
                            <div class="rounded-lg bg-teal/10 px-3 py-2">
                                <p class="text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">Goal reached</p>
                                <p class="text-base font-bold text-teal-700 tabular-nums">+{{ $peso(-$left) }} over</p>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="p-4 text-sm text-muted">No active CRAs.</li>
                @endforelse
            </ol>
        </x-panel>

        <x-panel title="Per product breakdown" icon="table" title-id="products-title" :tinted="false" body-class="">
            <x-slot:actions>
                <nav class="flex gap-1" aria-label="Cells show">
                    @foreach (['sales' => 'Sales ₱', 'qty' => 'Qty'] as $value => $label)
                        <a href="{{ route('running-sales.index', ['period' => $period, 'show' => $value]) }}" @if ($show === $value) aria-current="page" @endif
                           @class(['rounded-lg border px-3 py-1 font-semibold', 'border-brand-200 bg-white text-brand-700' => $show === $value, 'border-transparent text-muted hover:text-brand-600' => $show !== $value])>{{ $label }}</a>
                    @endforeach
                </nav>
            </x-slot:actions>

            @if ($products === [])
                <p class="p-4 text-sm text-muted">No sales yet for {{ Str::lower(\App\Services\RunningSales::PERIODS[$period]) }}.</p>
            @else
                @php $cell = fn (?array $sold) => $show === 'qty' ? number_format($sold['qty'] ?? 0) : $peso($sold['sales'] ?? 0); @endphp
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[51.25rem] text-sm tabular-nums">
                        <thead class="text-[0.6875rem] tracking-wide text-muted uppercase">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left font-semibold">CRA</th>
                                @foreach (array_keys($products) as $name)
                                    <th scope="col" class="px-3 py-3 text-right font-semibold">{{ $name }}</th>
                                @endforeach
                                <th scope="col" class="px-4 py-3 text-right font-bold text-ink">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($snapshot['cras'] as $row)
                                <tr class="border-t border-line">
                                    <th scope="row" class="px-4 py-3 text-left font-semibold text-ink">{{ $row['name'] }}</th>
                                    @foreach (array_keys($products) as $name)
                                        @php $value = $row['products'][$name][$show] ?? 0; @endphp
                                        <td class="px-3 py-3 text-right" @if ($value > 0) style="background-color: rgb(160 90 255 / {{ round(0.05 + 0.3 * $value / $cellMax, 3) }})" @endif>
                                            {{ $value > 0 ? $cell($row['products'][$name]) : '—' }}
                                        </td>
                                    @endforeach
                                    <td class="px-4 py-3 text-right font-bold text-ink">
                                        {{ $show === 'qty' ? number_format(collect($row['products'])->sum('qty')) : $peso($row['sales']) }}
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="border-t-2 border-brand-200 bg-brand-50/60 font-bold text-ink">
                                <th scope="row" class="px-4 py-3 text-left">Team</th>
                                @foreach ($products as $sold)
                                    <td class="px-3 py-3 text-right">{{ $sold[$show] > 0 ? $cell($sold) : '—' }}</td>
                                @endforeach
                                <td class="px-4 py-3 text-right">{{ $show === 'qty' ? number_format(collect($products)->sum('qty')) : $peso($team['sales']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="px-4 py-3 text-xs text-muted">Darker cells = more of that CRA's {{ $show === 'qty' ? 'pieces' : 'sales' }}. An order with several products is split by qty × SRP. Items not in Settings → Product Consumption are under Others.</p>
            @endif
        </x-panel>
    </div>
</x-layouts.app>
