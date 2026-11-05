@use('App\Models\CvrOrderEntry')
@use('App\Services\CraIssues')
@php
    $dayLink = fn (\Carbon\CarbonImmutable $d) => route('cvr-tracker.index', array_filter(['date' => $d->toDateString(), 'cra' => $cra?->id]));
    $peso = fn (?float $n) => $n === null ? '—' : '₱'.number_format($n);
    $dash = '<span class="text-muted">—</span>';
    $tile = 'grid content-start gap-0.5 rounded-xl border border-line bg-white px-3 py-2.5';
    $label = 'text-[0.6875rem] font-bold tracking-wide text-muted uppercase';
    $pending = 'w-max rounded-full bg-[#fff6d6] px-2 py-px text-[0.6875rem] font-semibold text-[#8a5f00]';
    $classes = fn (array $options) => collect($options)->map(fn ($o) => $o[1]);
    $s = $data['summary'] ?? null;
@endphp

<x-layouts.app title="CVR Tracker">
    <div class="space-y-4" data-cvr-tracker data-canceled-bc="{{ $s['canceled']['bc_amount'] ?? 0 }}" data-canceled-sc="{{ $s['canceled']['sc_amount'] ?? 0 }}" data-day-url="{{ route('cvr-tracker.days.update') }}" data-date="{{ $day->toDateString() }}" data-cra="{{ $cra?->id }}"
         data-empty-classes="{{ CvrOrderEntry::UNSET_CLASSES }}">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">CVR Tracker <span class="text-sm font-normal text-muted">· Reorders each CRA converted</span></h1>

            <div class="flex flex-wrap items-center gap-3">
                @if ($day->isSameDay($today))
                    <span class="text-xs text-muted">Orders updated {{ $refreshedAt ? $refreshedAt->timezone(config('segmentation.timezone'))->format('g:i A') : 'not yet today' }}</span>
                    <form method="POST" action="{{ route('cvr-tracker.refresh') }}">
                        @csrf
                        <button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white hover:bg-brand-700">Refresh orders</button>
                    </form>
                @endif

                <form method="GET" action="{{ route('cvr-tracker.index') }}" class="flex h-9 items-center rounded-lg border border-line bg-white text-sm">
                    @if ($cra)
                        <input type="hidden" name="cra" value="{{ $cra->id }}">
                    @endif
                    <a href="{{ $dayLink($day->subDay()) }}" class="grid h-full w-8 place-items-center rounded-l-lg text-muted hover:text-brand-600" aria-label="Previous day">‹</a>
                    <label>
                        <span class="sr-only">Day</span>
                        <input type="date" name="date" value="{{ $day->toDateString() }}" max="{{ $today->toDateString() }}" onchange="this.form.submit()"
                               class="h-8 border-0 bg-transparent px-1 text-sm font-semibold focus:ring-0 focus:outline-none">
                    </label>
                    @if ($day->lessThan($today))
                        <a href="{{ $dayLink($day->addDay()) }}" class="grid h-full w-8 place-items-center rounded-r-lg text-muted hover:text-brand-600" aria-label="Next day">›</a>
                    @else
                        <span class="grid h-full w-8 place-items-center text-muted/40" aria-hidden="true">›</span>
                    @endif
                </form>
            </div>
        </header>

        @if ($data && $data['issues']->isNotEmpty())
            @php($byType = $data['issues']->countBy('type'))
            <details role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-2.5 text-sm text-coral-700">
                <summary class="flex cursor-pointer flex-wrap items-center gap-x-2 gap-y-1">
                    <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                    <span class="font-semibold">Unresolved tagging: {{ $data['issues']->count() }} of {{ $cra->displayName() }}'s {{ $day->format('M j') }} {{ Str::plural('order', $data['issues']->count()) }}</span>
                    <span>· {{ collect(CraIssues::LABELS)->filter(fn ($l, $type) => $byType[$type] ?? 0)->map(fn ($l, $type) => $byType[$type].' '.strtolower($l))->join(', ') }}. Orders with no CRD tag aren't listed below until they're tagged in Pancake.</span>
                    <span class="ml-auto text-xs font-semibold underline">Show orders</span>
                </summary>
                <ul class="mt-2 space-y-1 border-t border-coral/30 pt-2 text-xs">
                    @foreach ($data['issues'] as $issue)
                        <li class="tabular-nums"><span class="font-semibold">#{{ $issue['order_id'] }}</span> · {{ $issue['customer'] }} · {{ $peso($issue['amount']) }} · {{ CraIssues::LABELS[$issue['type']] }}: {{ CraIssues::FIXES[$issue['type']] }}</li>
                    @endforeach
                </ul>
            </details>
        @endif

        @if ($cras->count() > 1)
            <nav class="flex flex-wrap gap-1.5" aria-label="CRA">
                @foreach ($cras as $c)
                    @php($current = $c->id === $cra?->id)
                    <a href="{{ route('cvr-tracker.index', ['date' => $day->toDateString(), 'cra' => $c->id]) }}" @if ($current) aria-current="page" @endif
                       @class(['inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-semibold transition',
                               'border-brand-600 bg-brand-600 text-white' => $current, 'border-line bg-white text-ink hover:border-brand-400 hover:text-brand-600' => ! $current])>
                        {{ $c->displayName() }}
                        <span @class(['rounded-full px-1.5 text-[0.6875rem] font-bold tabular-nums', 'bg-white/25 text-white' => $current, 'bg-brand-50 text-brand-700' => ! $current])>{{ $counts[$c->id] ?? 0 }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        @if (! $cra)
            <p class="rounded-xl bg-white px-4 py-10 text-center text-sm text-muted shadow-sm">No active CRAs to show.</p>
        @else
            <x-panel :title="$cra->displayName()" icon="chart" :tinted="false" body-class="bg-canvas/40 p-3.5 space-y-2.5">
                <x-slot:badges>
                    <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm">{{ $day->format('D, M j') }}</span>
                </x-slot:badges>

                @if ($missing = $data['sheet_missing'])
                    <div role="note" class="rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-3 py-2 text-sm text-[#473821]">
                        <span class="font-semibold">{{ count($missing) }} {{ Str::plural('order', count($missing)) }} missing here from {{ $cra->displayName() }}'s manual sheet</span>
                        · {{ collect($missing)->map(fn ($row) => $row['customer'].($row['amount'] ? ' ('.$peso($row['amount']).')' : ''))->join(', ') }}.
                        They match no Pancake order, so they aren't counted.
                    </div>
                @endif

                @if (! $cra->pancake_name)
                    <p class="rounded-lg border border-coral/60 bg-coral/10 px-3 py-2 text-sm text-coral-700">{{ $cra->displayName() }} has no Pancake account set, so their orders can't be found. Set it in Settings → Pancake Accounts.</p>
                @endif

                <div class="grid grid-cols-2 gap-2.5 md:grid-cols-4 xl:grid-cols-7 tabular-nums">
                    <div class="{{ $tile }} border-brand-200 bg-brand-50"><span class="{{ $label }}">Total gross sales</span><span class="text-2xl font-bold">{{ $peso($s['gross']) }}</span><span class="text-xs text-muted">Broadcast + Segmentation{{ $s['canceled']['orders'] ? ' · incl. '.$peso($s['canceled']['amount']).' canceled ('.$s['canceled']['orders'].')' : '' }}</span></div>
                    <div class="{{ $tile }}"><span class="{{ $label }}">Broadcast sales</span><span class="text-xl font-bold" data-cvr-sum="bc_amount">{{ $peso($s['bc_amount']) }}</span><span class="text-xs text-muted"><span data-cvr-sum="bc_orders">{{ $s['bc_orders'] }}</span> {{ Str::plural('order', $s['bc_orders']) }}</span></div>
                    <div class="{{ $tile }}"><span class="{{ $label }}">Segmentation sales</span><span class="text-xl font-bold" data-cvr-sum="sc_amount">{{ $peso($s['sc_amount']) }}</span><span class="text-xs text-muted"><span data-cvr-sum="sc_orders">{{ $s['sc_orders'] }}</span> {{ Str::plural('order', $s['sc_orders']) }}</span></div>
                    <div class="{{ $tile }}"><span class="{{ $label }}">Total orders</span><span class="text-xl font-bold">{{ $s['orders'] }}</span></div>
                    <div class="{{ $tile }}"><span class="{{ $label }}">AOV</span><span class="text-xl font-bold">{{ $peso($s['aov']) }}</span><span class="text-xs text-muted">Gross ÷ orders{{ $s['canceled']['orders'] ? ' (canceled in gross)' : '' }}</span></div>
                    <div class="{{ $tile }}"><span class="{{ $label }}">CVR</span><span class="text-xl font-bold">{{ $s['cvr'] === null ? '—' : number_format($s['cvr'] * 100, 1).'%' }}</span>
                        @if ($s['cvr'] === null)<span class="{{ $pending }}">Needs engagements</span>@else<span class="text-xs text-muted">Segmentation ÷ engagements</span>@endif
                    </div>
                    <div class="{{ $tile }}"><span class="{{ $label }}"># of engagements</span><span class="text-xl font-bold">{{ $s['engagements'] ?? '—' }}</span><span class="{{ $pending }}">From Daily I.S.</span></div>
                </div>

                <div class="grid gap-2.5 tabular-nums lg:grid-cols-[1fr_2.4fr_1.3fr]">
                    <div class="{{ $tile }}"><span class="{{ $label }}">Contacted CX</span><span class="text-xl font-bold" data-cvr-sum="contacted">{{ $s['contacted'] }}</span><span class="text-xs text-muted">Orders with a point of contact</span></div>
                    <div class="{{ $tile }}">
                        <span class="{{ $label }}">Status</span>
                        <div class="grid grid-cols-2 gap-y-2 sm:grid-cols-5">
                            <div class="grid gap-0.5 pr-3">
                                <label for="cvr-unconfirmed" class="text-xs text-muted">Unconfirmed</label>
                                <input id="cvr-unconfirmed" type="number" min="0" step="1" inputmode="numeric" value="{{ $s['unconfirmed'] }}" placeholder="—" data-cvr-unconfirmed
                                       class="h-8 w-20 rounded-lg border border-brand-200 bg-white px-2 text-right text-base font-bold placeholder:font-normal placeholder:text-muted/60 focus:border-brand-400 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                            </div>
                            @foreach (['engaged' => 'Engaged', 'reserved' => 'Reserved', 'not_answering' => 'Not answering/CBR'] as $key => $text)
                                <div class="grid gap-0.5 border-line px-3 sm:border-l"><span class="text-xs text-muted">{{ $text }}</span><span class="text-xl font-bold" data-cvr-status="{{ $s[$key] }}">{{ $s[$key] }}</span></div>
                            @endforeach
                            <div class="grid gap-0.5 rounded-lg bg-canvas px-3 py-1"><span class="text-xs text-muted">Total</span><span class="text-xl font-bold" data-cvr-status-total>{{ $s['status_total'] }}</span></div>
                        </div>
                    </div>
                    <div class="{{ $tile }}">
                        <span class="{{ $label }}">Leads</span>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="grid gap-0.5"><span class="text-xs text-muted">Pancake leads</span><span class="text-xl font-bold" data-cvr-sum="pancake_leads">{{ $s['pancake_leads'] }}</span></div>
                            <div class="grid gap-0.5"><span class="text-xs text-muted">Assigned leads</span><span class="text-xl font-bold" data-cvr-sum="assigned_leads">{{ $s['assigned_leads'] }}</span></div>
                        </div>
                    </div>
                </div>
            </x-panel>

            <x-panel title="Orders" icon="table" :tinted="false" body-class="">
                <x-slot:actions>
                    <span class="text-muted">Upsell, Type of Order and Point of Contact save as you pick</span>
                </x-slot:actions>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[73.75rem] text-sm tabular-nums">
                        <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-3 py-3 text-left font-semibold">Order ID</th>
                                <th class="px-3 py-3 text-center font-semibold">Total # of orders</th>
                                <th class="px-3 py-3 text-left font-semibold">Phone number</th>
                                <th class="px-3 py-3 text-left font-semibold">Page name</th>
                                <th class="px-3 py-3 text-left font-semibold">Customer name</th>
                                <th class="px-3 py-3 text-right font-semibold">Amount</th>
                                <th class="px-3 py-3 text-left font-semibold">Assigned / Pancake</th>
                                <th class="px-3 py-3 text-left font-semibold">Upsell</th>
                                <th class="px-3 py-3 text-left font-semibold">Type of order</th>
                                <th class="px-3 py-3 text-left font-semibold">Point of contact</th>
                                <th class="px-3 py-3 text-left font-semibold">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($data['orders'] as $order)
                                <tr data-cvr-order data-url="{{ route('cvr-tracker.orders.update', $order['id']) }}" data-amount="{{ $order['amount'] }}" data-matched="{{ $order['matched'] ? 1 : 0 }}">
                                    <td class="px-3 py-2 font-mono text-xs">
                                        {{ $order['id'] }}
                                        @if ($cra->pancake_name && $order['seller'] !== \App\Models\PancakeEngagement::staffKey($cra->pancake_name))
                                            <span class="block font-sans text-[0.6875rem] text-muted" title="Made under another Pancake account; counted for {{ $cra->displayName() }}">via {{ $order['seller'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        @if ($order['total_orders'] === null)
                                            {!! $dash !!}
                                        @else
                                            <span @class(['inline-grid h-6 min-w-7 place-items-center rounded-md px-1.5 text-xs font-bold', 'bg-brand-100 text-brand-700' => $order['total_orders'] >= 5, 'bg-canvas' => $order['total_orders'] < 5])>{{ $order['total_orders'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-muted">{{ $order['phone'] ?? '—' }}</td>
                                    <td class="max-w-56 truncate px-3 py-2" title="{{ $order['page'] }}">{{ $order['page'] ?? '—' }}</td>
                                    <td class="px-3 py-2 font-semibold whitespace-nowrap">{{ $order['customer'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right">{{ $peso($order['amount']) }}</td>
                                    <td class="px-3 py-2">
                                        {{-- Both rendered; the Type of Order pick switches them on screen --}}
                                        <span data-cvr-source="assigned" @class(['rounded-full bg-[#dbe7ff] px-2.5 py-1 text-xs font-semibold text-[#1d4396]', 'hidden' => ! $order['assigned']]) title="Type of Order is Segmentation, or the number matches an assigned lead in the Segmentation Tracker">Assigned</span>
                                        <span data-cvr-source="pancake" @class(['rounded-full bg-[#3b3f46] px-2.5 py-1 text-xs font-semibold text-white', 'hidden' => $order['assigned']]) title="Not a Segmentation order and not in the Segmentation Tracker's assigned leads">Pancake</span>
                                    </td>
                                    @foreach ([['upsell', CvrOrderEntry::UPSELLS, 'Pick upsell', 'Upsell'], ['type', CvrOrderEntry::ORDER_TYPES, null, 'Type of order'], ['point_of_contact', CvrOrderEntry::POINTS_OF_CONTACT, 'Pick', 'Point of contact']] as [$field, $options, $placeholder, $name])
                                        @php($value = $order[$field])
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            <select data-cvr-field="{{ $field === 'type' ? 'order_type' : $field }}" data-colors='@json($classes($options))' aria-label="{{ $name }} for {{ $order['customer'] }}"
                                                    @if ($field === 'type') data-tag="{{ $order['tagged_type'] }}" @endif
                                                    class="h-8 rounded-full border-0 px-3 text-xs font-semibold focus:ring-2 focus:ring-brand-200 focus:outline-none {{ $value !== null && isset($options[$value]) ? $options[$value][1] : CvrOrderEntry::UNSET_CLASSES }}">
                                                @if ($placeholder)
                                                    <option value="" class="bg-white text-ink">{{ $placeholder }}</option>
                                                @endif
                                                @foreach ($options as $key => [$text])
                                                    <option value="{{ $key }}" class="bg-white text-ink" @selected($value === $key)>{{ $text }}</option>
                                                @endforeach
                                            </select>
                                            @if ($field === 'type')
                                                <span data-cvr-edited @class(['ml-1 inline-block size-2 rounded-full bg-brand-500 align-middle', 'hidden' => $value === $order['tagged_type']]) title="Changed from the order's CRD tag ({{ CvrOrderEntry::ORDER_TYPES[$order['tagged_type']][0] ?? '' }})"></span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-2 whitespace-nowrap text-muted">{{ $order['time']?->format('g:i A') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="11" class="px-4 py-10 text-center text-muted">No tagged orders for {{ $cra->displayName() }} on {{ $day->format('M j') }}.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($data['orders'])
                            <tfoot class="border-t-2 border-line bg-brand-50/50 font-bold">
                                <tr>
                                    <td class="px-3 py-2.5" colspan="5">TOTAL · {{ $s['orders'] }} {{ Str::plural('order', $s['orders']) }}</td>
                                    <td class="px-3 py-2.5 text-right">
                                        {{ $peso($s['gross']) }}
                                        @if ($s['canceled']['orders'])
                                            <span class="block text-[0.6875rem] font-normal text-muted">incl. {{ $peso($s['canceled']['amount']) }} canceled</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">{{ $s['pancake_leads'] }} Pancake · {{ $s['assigned_leads'] }} Assigned</td>
                                    <td class="px-3 py-2.5"></td>
                                    <td class="px-3 py-2.5 whitespace-nowrap"><span data-cvr-sum="sc_orders">{{ $s['sc_orders'] }}</span> Seg · <span data-cvr-sum="bc_orders">{{ $s['bc_orders'] }}</span> BC</td>
                                    <td class="px-3 py-2.5 whitespace-nowrap"><span data-cvr-sum="contacted">{{ $s['contacted'] }}</span> contacted</td>
                                    <td class="px-3 py-2.5"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </x-panel>

            <p class="text-xs text-muted">
                Orders are {{ $cra->displayName() }}'s Pancake orders tagged CRD - Broadcast or CRD - Segmentation, not canceled or deleted.
                Assigned means the Type of Order is Segmentation, or the number matches an assigned lead in the Segmentation Tracker. Total # of orders counts the customer's delivered orders before this day, plus this one.
                Engaged, Reserved and Not answering/CBR count the leads on {{ \App\Support\WorkingDate::leadDayFor($day)->format('M j') }}'s list in the Segmentation Tracker.
            </p>
        @endif
    </div>
</x-layouts.app>
