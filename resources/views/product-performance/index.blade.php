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

        <div class="flex flex-wrap gap-4">
            <section class="min-w-0 flex-[999_1_560px] rounded-xl bg-white p-5 shadow-sm" aria-labelledby="pp-trend-title">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="pp-trend-title" class="font-semibold">Gross sales per {{ $unitName }}, by product</h2>
                        <p class="text-sm text-muted">
                            <span class="font-semibold text-ink">{{ $peso($total['sales']) }}</span>
                            · {{ number_format($total['orders']) }} {{ Str::plural('order', $total['orders']) }} · {{ number_format($total['qty']) }} pcs
                            @if ($change !== null)
                                · <span @class(['font-semibold', 'text-teal-700' => $change >= 0, 'text-coral-700' => $change < 0])>{{ $change >= 0 ? '▲' : '▼' }} {{ abs($change) }}%</span> vs {{ $previousLabel }}
                            @endif
                        </p>
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

                <svg viewBox="-8 0 656 200" class="mt-3 h-auto w-full sm:h-56" role="img" aria-label="Gross sales per {{ $unitName }} per product, {{ $label }}">
                    @foreach ([20, 95, 170] as $grid)
                        <line x1="0" y1="{{ $grid }}" x2="640" y2="{{ $grid }}" stroke="#ebe6ee"/>
                    @endforeach
                    @foreach ($lines as $i => $line)
                        <g data-pp-line="{{ $i }}" @unless ($line['shown']) display="none" @endunless>
                            @if ($count > 1)
                                <polyline points="{{ $points($line['series']) }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="2.2" stroke-linejoin="round"/>
                            @endif
                            @if ($count <= 40)
                                @foreach ($line['series'] as $j => $value)
                                    <circle cx="{{ $x($j) }}" cy="{{ $y($value) }}" r="2.6" fill="{{ $line['color'] }}"><title>{{ $line['name'] }} · {{ $labels[$j] }}: {{ $peso($value) }}</title></circle>
                                @endforeach
                            @endif
                        </g>
                    @endforeach
                    @foreach ($axis as $i)
                        <text x="{{ $x($i) }}" y="194" font-size="11" fill="#4f5563" text-anchor="{{ $i === 0 ? 'start' : ($i === $count - 1 ? 'end' : 'middle') }}">{{ $labels[$i] }}</text>
                    @endforeach
                    <text x="0" y="14" font-size="11" fill="#4f5563">{{ $peso($max) }}</text>
                </svg>
            </section>

            {{-- Insights fold up to their header and back; the choice is remembered on this browser --}}
            <aside class="flex-[1_1_280px] self-start rounded-xl bg-white shadow-sm" aria-labelledby="pp-insights-title">
                <details open class="group" data-pp-insights>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl p-5 hover:bg-brand-50/60 focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                        <h2 id="pp-insights-title" class="font-semibold">Insights <span class="text-sm font-normal text-muted">· {{ count($insights) }}</span></h2>
                        <svg class="size-4 text-muted transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <div class="px-5 pb-5">
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
                    </div>
                </details>
            </aside>
        </div>

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
                <div class="sm:col-span-2"><dt class="font-semibold text-ink">Compared with</dt><dd>The same number of days just before ({{ $previousLabel }}). The chart shows each product's gross sales per hour for a day, per day up to two months, and per week beyond; click a product above the chart to show or hide its line.</dd></div>
            </dl>
        </details>
    </div>
</x-layouts.app>
