@php
    $query = fn (array $extra) => route('customers.high-value', array_filter([...$filters, ...$extra], fn ($v) => $v !== null && $v !== ''));
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $cell = 'h-8 w-full min-w-28 rounded-md border border-line bg-white px-2 text-xs text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $user = auth()->user();
    $canEdit = $user->can('customers.high_value.edit');
    $canAssign = $user->can('customers.high_value.assign');
    $canOpen = $user->can('customers.view');
    $isCra = $cras->contains('id', $user->id);
    $perOrder = $filters['view'] === 'orders';
    $showRemarks = $filters['list'] !== 'high_aov';
    $contacts = config('customers.points_of_contact');
    $buyerTypes = config('customers.buyer_types');
    $money = fn (?float $value) => $value === null ? '—' : '₱'.number_format($value, 2);
@endphp

<x-layouts.app title="High AOV CVR & VIP">
    <div class="space-y-4">
        @include('customers._tabs')

        <header>
            <h1 class="text-xl font-semibold">High AOV CVR & VIP</h1>
            <p class="text-sm text-muted">CRA-handled customers with an AOV of ₱{{ number_format(config('customers.high_aov_min')) }}+, and VIPs who reached a product's CLTV. Merged numbers count as one customer.</p>
        </header>

        {{-- Tiles: click to filter --}}
        <section aria-label="Customer counts" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            @foreach ([
                ['high_aov', 'High AOV', $counts['high_aov'], 'from-brand-500 to-brand-700', ['list' => 'high_aov', 'cra' => null], $filters['list'] === 'high_aov'],
                ['vip', 'VIP', $counts['vip'], 'from-[#2b2b2b] to-black', ['list' => 'vip', 'cra' => null], $filters['list'] === 'vip'],
                ['none', 'No CRA yet', $counts['unassigned'], 'from-[#4f5563] to-[#343a40]', ['list' => 'all', 'cra' => 'none'], ($filters['cra'] ?? null) === 'none' && $filters['list'] === 'all'],
            ] as [$key, $label, $value, $gradient, $link, $active])
                <a href="{{ $query([...$link, 'page' => null]) }}" @if ($active) aria-current="true" @endif
                   @class(['relative flex flex-col gap-0.5 overflow-hidden rounded-xl bg-gradient-to-br p-4 transition', $gradient, $key === 'vip' ? 'text-[#d4af37]' : 'text-white', '-translate-y-1.5 shadow-xl' => $active, 'shadow-sm hover:-translate-y-0.5 hover:shadow-lg' => ! $active])>
                    <span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/10"></span>
                    <span class="relative flex items-center justify-between text-[11px] font-semibold tracking-wide uppercase">
                        {{ $label }}
                        @if ($active)
                            <span class="rounded-full bg-white px-1.5 text-[10px] text-ink normal-case">Showing</span>
                        @endif
                    </span>
                    <span class="relative text-2xl font-bold tabular-nums">{{ number_format($value) }}</span>
                </a>
            @endforeach
        </section>

        {{-- Filters --}}
        <form method="GET" action="{{ route('customers.high-value') }}" class="flex flex-wrap items-end gap-x-5 gap-y-4 rounded-xl bg-white p-4 shadow-sm">
            <div class="flex min-w-64 flex-1 flex-col gap-1.5">
                <label for="hv-search" class="text-xs font-semibold tracking-wide text-muted uppercase">Search</label>
                <div class="flex">
                    <input id="hv-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Customer name or contact number"
                           class="h-9 min-w-0 flex-1 rounded-l-lg border border-r-0 border-line bg-white px-2.5 text-sm text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                    <button type="submit" class="h-9 shrink-0 rounded-r-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
                </div>
            </div>

            <fieldset>
                <legend class="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">View</legend>
                <div class="flex h-9 items-center rounded-lg border border-line p-0.5 text-sm font-semibold">
                    @foreach (\App\Services\HighValueCustomers::VIEWS as $value => $label)
                        <label class="h-full cursor-pointer">
                            <input type="radio" name="view" value="{{ $value }}" class="peer sr-only" onchange="this.form.submit()" @checked($filters['view'] === $value)>
                            <span class="grid h-full place-items-center rounded-md px-3 text-muted peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                Show
                <select name="list" onchange="this.form.submit()" class="{{ $control }} normal-case">
                    @foreach (\App\Services\HighValueCustomers::LISTS as $value => $label)
                        <option value="{{ $value }}" @selected($filters['list'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                Assigning seller
                <select name="cra" onchange="this.form.submit()" class="{{ $control }} normal-case">
                    <option value="">Every CRA</option>
                    @if ($isCra)
                        <option value="mine" @selected(($filters['cra'] ?? null) === 'mine')>Mine</option>
                    @endif
                    <option value="none" @selected(($filters['cra'] ?? null) === 'none')>No CRA yet</option>
                    @foreach ($cras as $cra)
                        <option value="{{ $cra->id }}" @selected(($filters['cra'] ?? null) === (string) $cra->id)>{{ $cra->displayName() }}</option>
                    @endforeach
                </select>
            </label>

            @if (filled($filters['search'] ?? null) || filled($filters['cra'] ?? null) || $filters['list'] !== 'all')
                <a href="{{ route('customers.high-value', ['view' => $filters['view']]) }}" class="h-9 content-center text-sm font-medium text-muted hover:text-brand-600">Clear</a>
            @endif
        </form>

        <x-panel :title="\App\Services\HighValueCustomers::LISTS[$filters['list']].' · '.\App\Services\HighValueCustomers::VIEWS[$filters['view']]" icon="users" :tinted="false" body-class="">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-brand-700 tabular-nums shadow-sm">{{ number_format($perOrder ? $rows->total() : $customerCount) }} {{ $perOrder ? 'orders' : 'customers' }}</span>
            </x-slot:badges>

            @if ($rows->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">No customers match.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1100px] text-left text-sm">
                        <thead class="bg-[#f7f4f8] text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-3 py-3 font-semibold">{{ $perOrder ? 'Order ID' : 'Last order ID' }}</th>
                                <th class="px-3 py-3 font-semibold">Assigning seller</th>
                                <th class="px-3 py-3 font-semibold">Customer</th>
                                <th class="px-3 py-3 font-semibold">Phone number</th>
                                <th class="px-3 py-3 text-right font-semibold">Total # of orders</th>
                                <th class="px-3 py-3 text-right font-semibold">{{ $perOrder ? 'Amount' : 'Amount (total spent)' }}</th>
                                <th class="px-3 py-3 font-semibold">Point of contact</th>
                                <th class="px-3 py-3 text-right font-semibold">Current AOV</th>
                                <th class="px-3 py-3 font-semibold">PU/Reseller</th>
                                @if ($showRemarks)
                                    <th class="px-3 py-3 font-semibold">Remarks</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($rows as $row)
                                @php($account = $row['account'])
                                @php($craId = $account?->assigned_cra_id)
                                @php($action = route('customers.high-value.update', $row['phone_key']))
                                <tr @class(['align-middle', 'bg-black text-[#d4af37]' => $row['vip'], 'hover:bg-brand-50/40' => ! $row['vip']])>
                                    <td class="px-3 py-2.5 tabular-nums">
                                        {{ $row['order_id'] ?? '—' }}
                                        @if ($perOrder)
                                            <span @class(['block text-xs', 'text-[#d4af37]/70' => $row['vip'], 'text-muted' => ! $row['vip']])>delivered {{ $row['delivered_on']?->format('M j, Y') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5">
                                        {{-- Supervisors set anyone; a CRA claims a customer nobody has, or lets go of their own --}}
                                        @if ($canEdit && ($canAssign || ($isCra && in_array($craId, [null, $user->id], true))))
                                            <form method="POST" action="{{ $action }}" data-autosave>
                                                @csrf @method('PATCH')
                                                <select name="assigned_cra_id" aria-label="Assigning seller for {{ $row['customer_name'] }}" class="{{ $cell }}">
                                                    <option value="">— No CRA —</option>
                                                    @foreach ($canAssign ? $cras : $cras->where('id', $user->id) as $cra)
                                                        <option value="{{ $cra->id }}" @selected($craId === $cra->id)>{{ $cra->displayName() }}</option>
                                                    @endforeach
                                                </select>
                                            </form>
                                        @else
                                            <span class="text-xs font-semibold">{{ $account?->cra?->displayName() ?? '—' }}</span>
                                        @endif
                                        @if ($perOrder && $row['order_seller'])
                                            <span @class(['mt-0.5 block text-[11px]', 'text-[#d4af37]/70' => $row['vip'], 'text-muted' => ! $row['vip']]) title="The order's seller in Pancake">sold by {{ $row['order_seller'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5">
                                        @if ($canOpen)
                                            <button type="button" data-customer-url="{{ route('customers.show', $row['phone_key']) }}" class="text-left font-semibold hover:underline">{{ $row['customer_name'] }}</button>
                                        @else
                                            <span class="font-semibold">{{ $row['customer_name'] }}</span>
                                        @endif
                                        @if ($row['vip'])
                                            <span class="ml-1 rounded-full bg-[#d4af37] px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-black" title="Reached the CLTV of {{ implode(', ', $row['vip_products']) }}">VIP</span>
                                        @endif
                                    </td>
                                    <td @class(['px-3 py-2.5 tabular-nums', 'text-muted' => ! $row['vip']])>{{ $row['phone_number'] }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ number_format($row['purchases']) }}</td>
                                    <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ $money($perOrder ? $row['amount'] : $row['total_spent']) }}</td>
                                    <td class="px-3 py-2.5">
                                        @if ($canEdit)
                                            <form method="POST" action="{{ $action }}" data-autosave>
                                                @csrf @method('PATCH')
                                                <select name="point_of_contact" aria-label="Point of contact for {{ $row['customer_name'] }}" class="{{ $cell }}">
                                                    <option value="">—</option>
                                                    @foreach ($contacts as $value => $label)
                                                        <option value="{{ $value }}" @selected($account?->point_of_contact === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </form>
                                        @else
                                            <span class="text-xs">{{ $contacts[$account?->point_of_contact] ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['aov']) }}</td>
                                    <td class="px-3 py-2.5">
                                        @if ($canEdit)
                                            <form method="POST" action="{{ $action }}" data-autosave>
                                                @csrf @method('PATCH')
                                                <select name="buyer_type" aria-label="PU/Reseller for {{ $row['customer_name'] }}" class="{{ $cell }}">
                                                    <option value="">—</option>
                                                    @foreach ($buyerTypes as $value => $label)
                                                        <option value="{{ $value }}" @selected($account?->buyer_type === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </form>
                                        @else
                                            <span class="text-xs">{{ $buyerTypes[$account?->buyer_type] ?? '—' }}</span>
                                        @endif
                                    </td>
                                    @if ($showRemarks)
                                        <td class="px-3 py-2.5">
                                            @if (! $row['vip'])
                                                <span class="text-xs text-muted">—</span>
                                            @elseif ($canEdit)
                                                <form method="POST" action="{{ $action }}" data-autosave>
                                                    @csrf @method('PATCH')
                                                    <input type="text" name="remarks" value="{{ $account?->remarks }}" maxlength="2000" placeholder="Add remarks…"
                                                           aria-label="Remarks for {{ $row['customer_name'] }}" class="{{ $cell }} min-w-44">
                                                </form>
                                            @else
                                                <span class="text-xs">{{ $account?->remarks ?? '—' }}</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($rows->hasPages())
                    <div class="border-t border-line px-4 py-3">{{ $rows->links() }}</div>
                @endif
            @endif
        </x-panel>

        <details class="rounded-xl bg-white p-4 text-sm shadow-sm">
            <summary class="cursor-pointer font-semibold">How the lists work</summary>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-muted sm:grid-cols-2">
                <div><dt class="font-semibold text-ink">Who is listed</dt><dd>Customers with at least one CRA-handled delivered order. Numbers merged in the Customer Database count as one customer.</dd></div>
                <div><dt class="font-semibold text-ink">High AOV</dt><dd>Current AOV = total spent ÷ delivered orders. ₱{{ number_format(config('customers.high_aov_min')) }} and up is High AOV.</dd></div>
                <div><dt class="font-semibold text-ink">VIP</dt><dd>Their delivered units of one product × its SRP reached that product's CLTV (SRP × {{ config('customers.cltv_units') }}), e.g. ₱499 × {{ config('customers.cltv_units') }} = ₱{{ number_format(499 * config('customers.cltv_units')) }}. VIP rows are black with gold text.</dd></div>
                <div><dt class="font-semibold text-ink">Per customer / per order</dt><dd>Per customer shows their totals as they are now, with their latest order. Per order lists each delivered order (amount = that order) beside the customer's current totals.</dd></div>
                <div class="sm:col-span-2"><dt class="font-semibold text-ink">Assigning seller</dt><dd>CRAs claim the customers they handle (supervisors can set anyone). Once set, the customer's Segmentation Tracker leads go to that CRA. A new High AOV / VIP customer with no CRA yet gets the CRA their lead is handed to.</dd></div>
            </dl>
        </details>
    </div>

    @if ($canOpen)
        @include('customers._dialog')
    @endif
</x-layouts.app>
