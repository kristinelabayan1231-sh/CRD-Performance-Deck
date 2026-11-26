{{--
    Products tab: main vs upsell, from the Presentation Board's Top products figures (UpsellBreakdown). Main = the
    products' own sales; Upsell = what was upsold on their orders plus the TSD products' own sales. The bars, the
    popup and the list's sort are in resources/js/product-performance.js.
--}}
@php
    $split = $upsell['split'];
    $share = fn (float $part, float $of) => $of > 0 ? number_format($part / $of * 100, 1).'%' : '—';
    $rateText = fn (float $rate) => number_format($rate * 100, 1).'%';
    $short = fn (float $amount) => abs($amount) >= 1000000 ? '₱'.number_format($amount / 1000000, 2).'M' : (abs($amount) >= 10000 ? '₱'.number_format($amount / 1000, 1).'K' : '₱'.number_format($amount));
    $average = $split['total'] > 0 ? $split['upsell'] / $split['total'] : 0.0;
    // What the popup shows for a slot (or the dates shown), worked out here so the script only fills it in.
    $popup = fn (array $figures, string $title) => [
        'title' => $title,
        'total' => $peso($figures['total']),
        'main' => $peso($figures['main']), 'mainShare' => $share($figures['main'], $figures['total']),
        'upsell' => $peso($figures['upsell']), 'upsellShare' => $share($figures['upsell'], $figures['total']),
        'upsold' => $peso($figures['upsold']), 'upsoldShare' => $share($figures['upsold'], $figures['total']),
        'tsd' => $peso($figures['tsd']), 'tsdShare' => $share($figures['tsd'], $figures['total']),
        'widths' => $figures['total'] > 0 ? array_map(fn (float $part) => round($part / $figures['total'] * 100, 2), [$figures['main'], $figures['upsold'], $figures['tsd']]) : [0, 0, 0],
    ];
    $slots = collect($upsell['slots']);
    $current = $slots->firstWhere('current', true);
    $before = $current ? $slots->get($slots->search(fn (array $slot) => $slot['current']) - 1) : null;
    $rateOf = fn (?array $slot) => $slot && $slot['total'] > 0 ? $slot['upsell'] / $slot['total'] : null;
    $delta = $rateOf($current) !== null && $rateOf($before) !== null ? ($rateOf($current) - $rateOf($before)) * 100 : null;
    $tallest = max(1, $slots->max('total') ?? 1);
    $unitName = $upsell['unit'];
    $products = collect($upsell['products']);
    $peak = max(1, $products->max(fn (array $product) => $product['sales'] + $product['upsold_value']) ?? 1);
    $badge = 'grid size-6 place-items-center rounded-md text-xs font-bold';
    $columns = 'grid grid-cols-[2rem_minmax(9rem,1.1fr)_minmax(11rem,2fr)_6rem_7.5rem_6rem_4.5rem] items-center gap-3';
@endphp

{{-- Upsell's amber in Light and Dark: as variables, so the generated dark theme (which turns yellows into slate) leaves it. --}}
<style>
    :root { --pp-upsell: #f5a524; --pp-upsell-soft: #fbd38a; --pp-upsell-bg: #fff7e6; --pp-upsell-text: #8a5200; }
    html[data-theme="dark"] { --pp-upsell: #f2b75a; --pp-upsell-soft: #8f6a2c; --pp-upsell-bg: rgb(242 183 90 / .12); --pp-upsell-text: #f6c97e; }
</style>

<section class="grid gap-4 rounded-xl bg-white p-5 shadow-sm" aria-labelledby="pp-upsell-title">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="pp-upsell-title" class="text-lg font-semibold">Main product vs Upsell</h2>
            <p class="text-sm text-muted">How much of sales came from upsells · by {{ $unitName }}, follows the dates above · from the Presentation Board's Top products figures</p>
        </div>
        <div class="flex flex-wrap gap-3.5 text-xs text-muted">
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-brand-700" aria-hidden="true"></span>Main product</span>
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-(--pp-upsell)" aria-hidden="true"></span>Upsell (incl. TSD sold alone)</span>
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        {{-- The dates shown: click for the popup --}}
        <button type="button" data-pp-upsell-open data-pp-upsell='@json($popup($split, $label))' aria-haspopup="dialog"
                class="flex flex-col gap-3 rounded-xl border border-line bg-(--pp-upsell-bg) p-4 text-left hover:border-(--pp-upsell)">
            <span class="text-sm font-semibold text-muted">{{ $label }}</span>
            <span class="flex flex-wrap items-baseline gap-2">
                <span class="text-4xl leading-none font-bold text-ink">{{ $share($split['upsell'], $split['total']) }}</span>
                <span class="text-sm font-semibold">of sales came from Upsell</span>
            </span>
            <span class="flex h-4 overflow-hidden rounded-md bg-brand-50" aria-hidden="true">
                <span class="bg-brand-700" style="width: {{ $split['total'] > 0 ? round($split['main'] / $split['total'] * 100, 2) : 0 }}%"></span><span class="bg-(--pp-upsell)" style="width: {{ $split['total'] > 0 ? round($split['upsell'] / $split['total'] * 100, 2) : 0 }}%"></span>
            </span>
            <span class="flex flex-wrap justify-between gap-2 text-sm tabular-nums">
                <span><strong>{{ $peso($split['main']) }}</strong> main · {{ $share($split['main'], $split['total']) }}</span>
                <span><strong>{{ $peso($split['upsell']) }}</strong> upsell</span>
            </span>
            <span class="flex flex-wrap justify-between gap-2 text-xs font-semibold">
                @if ($delta !== null)
                    <span @class(['text-teal-700' => $delta >= 0, 'text-coral-700' => $delta < 0])>{{ $delta >= 0 ? '▲' : '▼' }} {{ number_format(abs($delta), 1) }} pts vs {{ $before['title'] }}</span>
                @else
                    <span></span>
                @endif
                <span class="text-brand-700">See breakdown ›</span>
            </span>
        </button>

        {{-- Each day, week or month: main with upsell on top, its upsell share above --}}
        <div class="flex min-w-0 flex-col gap-2">
            <p class="text-sm font-semibold">{{ $slots->count() }} {{ Str::plural($unitName, $slots->count()) }} <span class="font-normal text-muted">· upsell share of each {{ $unitName }}, click a bar</span></p>
            <div class="overflow-x-auto">
                <div class="flex h-48 min-w-[26rem] items-end gap-1.5 border-b border-line pt-1">
                    @foreach ($slots as $slot)
                        @php
                            $height = $slot['total'] > 0 ? max(6, $slot['total'] / $tallest * 150) : 0;
                            $upHeight = $slot['total'] > 0 ? $height * $slot['upsell'] / $slot['total'] : 0;
                        @endphp
                        <button type="button" data-pp-upsell-open data-pp-upsell='@json($popup($slot, $slot['title'].($slot['partial'] ? ' (so far)' : '')))' aria-haspopup="dialog"
                                aria-label="{{ $slot['title'] }}: {{ $share($slot['upsell'], $slot['total']) }} from upsell, open the breakdown"
                                class="flex h-full min-w-6 flex-1 flex-col items-center justify-end gap-1 rounded-t">
                            <span @class(['text-[0.6875rem] font-bold tabular-nums', 'text-ink' => $slot['current'], 'text-muted' => ! $slot['current']])>{{ $slot['total'] > 0 ? $share($slot['upsell'], $slot['total']) : '' }}</span>
                            <span @class(['flex w-full max-w-11 flex-col overflow-hidden rounded-t', 'outline-2 outline-offset-1 outline-brand-600' => $slot['current'], 'outline-1 outline-dashed outline-brand-400' => $slot['partial'] && ! $slot['current']])>
                                <span @class(['bg-(--pp-upsell)' => ! $slot['partial'], 'bg-(--pp-upsell-soft)' => $slot['partial']]) style="height: {{ round($upHeight, 1) }}px"></span>
                                <span @class(['bg-brand-700' => ! $slot['partial'], 'bg-brand-400' => $slot['partial']]) style="height: {{ round($height - $upHeight, 1) }}px"></span>
                            </span>
                        </button>
                    @endforeach
                </div>
                <div class="flex min-w-[26rem] gap-1.5 pt-1">
                    @foreach ($slots as $slot)
                        <span @class(['min-w-6 flex-1 text-center text-[0.6875rem] whitespace-nowrap', 'font-bold text-ink' => $slot['current'], 'text-muted' => ! $slot['current']])>{{ $slot['label'] }}</span>
                    @endforeach
                </div>
            </div>
            @if ($slots->contains('partial', true))
                <p class="text-xs text-muted">A lighter, dashed bar isn't over yet.</p>
            @endif
        </div>
    </div>
</section>

{{-- The breakdown popup, filled from the button clicked --}}
<dialog data-pp-upsell-dialog aria-labelledby="pp-upsell-dialog-title" class="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-2xl bg-white p-5 text-ink shadow-2xl backdrop:bg-black/45">
    <div class="flex flex-col gap-4 tabular-nums">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 id="pp-upsell-dialog-title" class="text-base font-bold">Sales breakdown</h2>
                <p class="text-sm text-muted" data-f="title"></p>
            </div>
            <form method="dialog"><button type="submit" aria-label="Close" class="grid size-9 place-items-center rounded-lg border border-line hover:bg-canvas">✕</button></form>
        </div>
        <div>
            <p class="text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">Total</p>
            <p class="text-3xl font-bold" data-f="total"></p>
        </div>
        <span class="flex h-4 overflow-hidden rounded-md bg-brand-50" aria-hidden="true">
            <span class="bg-brand-700" data-w="0"></span><span class="bg-(--pp-upsell)" data-w="1"></span><span class="bg-(--pp-upsell-soft)" data-w="2"></span>
        </span>
        <table class="w-full text-sm">
            <thead class="text-[0.6875rem] tracking-wide text-muted uppercase">
                <tr><th scope="col" class="pb-1.5 text-left font-semibold">Category</th><th scope="col" class="pb-1.5 text-right font-semibold">Sales</th><th scope="col" class="pb-1.5 text-right font-semibold">% of total</th></tr>
            </thead>
            <tbody>
                <tr class="border-t border-line"><th scope="row" class="py-2.5 text-left font-bold"><span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-sm bg-brand-700" aria-hidden="true"></span>Main product</span></th><td class="py-2.5 text-right font-bold" data-f="main"></td><td class="py-2.5 text-right font-bold" data-f="mainShare"></td></tr>
                <tr class="border-t border-line"><th scope="row" class="pt-2.5 pb-1 text-left font-bold"><span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-sm bg-(--pp-upsell)" aria-hidden="true"></span>Upsell</span></th><td class="pt-2.5 pb-1 text-right font-bold" data-f="upsell"></td><td class="pt-2.5 pb-1 text-right"><span class="rounded-full border border-(--pp-upsell) bg-(--pp-upsell-bg) px-2.5 py-0.5 font-bold" data-f="upsellShare"></span></td></tr>
                <tr><th scope="row" class="py-1 pl-5 text-left font-medium text-muted">Added to a main product</th><td class="py-1 text-right text-muted" data-f="upsold"></td><td class="py-1 text-right text-muted" data-f="upsoldShare"></td></tr>
                <tr><th scope="row" class="pt-1 pb-2.5 pl-5 text-left font-medium text-muted"><span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-(--pp-upsell-soft)" aria-hidden="true"></span>TSD sold alone</span></th><td class="pt-1 pb-2.5 text-right text-muted" data-f="tsd"></td><td class="pt-1 pb-2.5 text-right text-muted" data-f="tsdShare"></td></tr>
                <tr class="border-t-2 border-ink"><th scope="row" class="pt-2.5 text-left font-bold">Total</th><td class="pt-2.5 text-right font-bold" data-f="total"></td><td class="pt-2.5 text-right font-bold">100%</td></tr>
            </tbody>
        </table>
        <p class="text-xs leading-relaxed text-muted">Main + Upsell = Total. A TSD product is always Upsell, also bought with no main product. Upsold add-ons are valued at their Pancake price, and Shecom leaves a TSD add-on out of gross, so the total can be more than gross sales.</p>
    </div>
</dialog>

{{-- The products: by sales, or by upsold % (the bar becomes main vs upsold with the average) --}}
<section class="group grid gap-3 rounded-xl bg-white p-5 shadow-sm" aria-labelledby="pp-upsell-products-title" data-pp-upsell-list data-sort="sales">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="pp-upsell-products-title" class="font-semibold">Products · main vs upsell</h2>
            <p class="text-sm text-muted group-data-[sort=upsell]:hidden">Biggest sellers first · purple = the product's own sales, amber = what was upsold on its orders · click a product for its breakdown</p>
            <p class="hidden text-sm text-muted group-data-[sort=upsell]:block">Highest upsold % first · each bar is the product's main vs upsold, the black line is the average {{ $rateText($average) }} · click a product for its breakdown</p>
        </div>
        <div class="flex rounded-lg border border-line bg-white p-0.5" role="group" aria-label="Sort products">
            @foreach (['sales' => 'By sales', 'upsell' => 'By upsell %'] as $by => $byLabel)
                <button type="button" data-pp-upsell-sort="{{ $by }}" aria-pressed="{{ $by === 'sales' ? 'true' : 'false' }}"
                        class="h-8 rounded-md px-3 text-sm font-semibold text-muted hover:text-brand-600 aria-pressed:bg-brand-600 aria-pressed:text-white">{{ $byLabel }}</button>
            @endforeach
        </div>
    </div>

    @if ($products->isEmpty() && ! $split['tsd_products'])
        <p class="py-8 text-center text-sm text-muted">No product sales in these dates on the Presentation Board yet.</p>
    @else
        <div class="overflow-x-auto">
            <div class="grid min-w-[54rem] gap-1.5 text-sm tabular-nums">
                <div class="{{ $columns }} px-3 text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">
                    <span>#</span><span>Product</span>
                    <span><span class="group-data-[sort=upsell]:hidden">Own sales + upsold on its orders</span><span class="hidden group-data-[sort=upsell]:inline">Main vs upsold · line = average</span></span>
                    <span class="text-right">Main sales</span><span class="text-right">Main · Upsold %</span><span class="text-right">Upsold</span><span class="text-right">Orders</span>
                </div>
                <div class="grid gap-1.5" data-pp-upsell-rows>
                    @foreach ($products as $index => $product)
                        @php
                            $rate = \App\Support\UpsellBreakdown::rates((float) $product['sales'], (float) $product['upsold_value']);
                            $onItsOrders = $product['sales'] + $product['upsold_value'];
                            $itemPeak = max(1, collect($product['upsold'])->max('value') ?? 1);
                            $aboveAverage = $rate['upsold'] > 0 && $rate['upsold'] >= $average;
                        @endphp
                        <details class="rounded-lg border border-line open:border-brand-200" data-pp-upsell-row data-sales="{{ round($product['sales'], 2) }}" data-upsell="{{ round($rate['upsold'], 6) }}">
                            <summary class="cursor-pointer list-none px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                                <span class="{{ $columns }}">
                                    <span data-pp-upsell-rank @class([$badge, 'bg-brand-600 text-white' => $index < 3, 'bg-brand-50 text-brand-700' => $index >= 3])>{{ $index + 1 }}</span>
                                    <span class="min-w-0"><span class="block font-bold">{{ $product['name'] }}</span><span class="block text-xs text-muted">{{ $share($product['sales'], $split['main']) }} of main sales</span></span>
                                    <span class="group-data-[sort=upsell]:hidden">
                                        <span class="flex h-3.5 overflow-hidden rounded bg-brand-50" aria-hidden="true"><span class="bg-brand-700" style="width: {{ round($product['sales'] / $peak * 100, 2) }}%"></span><span class="bg-(--pp-upsell)" style="width: {{ round($product['upsold_value'] / $peak * 100, 2) }}%"></span></span>
                                    </span>
                                    <span class="relative hidden h-5 rounded-md bg-brand-50 group-data-[sort=upsell]:block" aria-hidden="true">
                                        <span class="flex h-5 overflow-hidden rounded-md"><span class="bg-brand-700" style="width: {{ round($rate['main'] * 100, 2) }}%"></span><span class="bg-(--pp-upsell)" style="width: {{ round($rate['upsold'] * 100, 2) }}%"></span></span>
                                        <span class="absolute -inset-y-1 w-0.5 bg-ink" style="right: {{ round($average * 100, 2) }}%"></span>
                                    </span>
                                    <span class="text-right font-bold">{{ $short($product['sales']) }}</span>
                                    <span class="flex flex-col items-end gap-0.5 text-xs font-bold group-data-[sort=upsell]:hidden">
                                        <span class="text-brand-700">{{ $rateText($rate['main']) }} main</span><span class="text-(--pp-upsell-text)">{{ $rateText($rate['upsold']) }} upsold</span>
                                    </span>
                                    <span class="hidden flex-col items-end gap-1 group-data-[sort=upsell]:flex">
                                        <span @class(['rounded-full border px-2.5 py-0.5 text-sm font-bold text-ink', 'border-(--pp-upsell) bg-(--pp-upsell-bg)' => $aboveAverage, 'border-line bg-white' => ! $aboveAverage])>{{ $rateText($rate['upsold']) }}</span>
                                        <span class="text-xs font-bold text-brand-700">{{ $rateText($rate['main']) }} main</span>
                                    </span>
                                    <span class="text-right font-bold text-(--pp-upsell-text)">{{ $product['upsold_value'] > 0 ? '+'.$short($product['upsold_value']) : '—' }}</span>
                                    <span class="text-right">{{ number_format($product['orders']) }}</span>
                                </span>
                            </summary>
                            <div class="grid gap-5 px-3 pt-1 pb-4 pl-14 text-sm sm:grid-cols-2">
                                <div class="grid content-start gap-2">
                                    <h3 class="text-[0.6875rem] font-bold tracking-wide text-muted uppercase">Upsold on {{ $product['name'] }} orders</h3>
                                    @forelse ($product['upsold'] as $item)
                                        <div class="grid grid-cols-[minmax(7rem,10rem)_minmax(0,1fr)_5rem_3.5rem] items-center gap-x-2">
                                            <span class="font-semibold">{{ $item['name'] }}</span>
                                            <span class="h-2 rounded-full bg-brand-50" aria-hidden="true"><span class="block h-2 rounded-full bg-(--pp-upsell)" style="width: {{ round($item['value'] / $itemPeak * 100, 2) }}%"></span></span>
                                            <span class="text-right font-bold">{{ $short($item['value']) }}</span>
                                            <span class="text-right text-muted">{{ $share($item['value'], $onItsOrders) }}</span>
                                            <span class="col-start-2 col-end-5 text-xs text-muted">{{ number_format($item['orders']) }} {{ Str::plural('order', $item['orders']) }} · {{ number_format($item['qty']) }} qty</span>
                                        </div>
                                    @empty
                                        <p class="text-muted">Nothing upsold on its orders in these dates.</p>
                                    @endforelse
                                </div>
                                <div class="grid content-start gap-2">
                                    <h3 class="text-[0.6875rem] font-bold tracking-wide text-muted uppercase">On its orders</h3>
                                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_4rem] gap-2 border-b border-line py-1.5"><span>{{ $product['name'] }} (main)</span><strong class="text-right">{{ $peso($product['sales']) }}</strong><strong class="text-right text-brand-700">{{ $rateText($rate['main']) }}</strong></div>
                                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_4rem] gap-2 border-b border-line py-1.5"><span>Upsold</span><strong class="text-right">{{ $peso($product['upsold_value']) }}</strong><strong class="text-right text-(--pp-upsell-text)">{{ $rateText($rate['upsold']) }}</strong></div>
                                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_4rem] gap-2 py-1.5"><span>Total on its orders</span><strong class="text-right">{{ $peso($onItsOrders) }}</strong><strong class="text-right">100%</strong></div>
                                </div>
                            </div>
                        </details>
                    @endforeach

                    @if ($upsell['others'])
                        {{-- Items not in Product Consumption: counted as main --}}
                        <div class="rounded-lg border border-dashed border-line px-3 py-2.5" data-pp-upsell-others>
                            <span class="{{ $columns }}">
                                <span></span>
                                <span class="min-w-0"><span class="block font-bold">Others</span><span class="block text-xs text-muted">Items not in Product Consumption · main</span></span>
                                <span></span>
                                <span class="text-right font-bold">{{ $short($upsell['others']['sales']) }}</span>
                                <span></span><span></span>
                                <span class="text-right">{{ number_format($upsell['others']['orders']) }}</span>
                            </span>
                        </div>
                    @endif

                    @if ($split['tsd_products'])
                        @php $tsdPeak = max(1, collect($split['tsd_products'])->max('value')); @endphp
                        <details class="rounded-lg border border-dashed border-(--pp-upsell) bg-(--pp-upsell-bg)" data-pp-upsell-tsd>
                            <summary class="cursor-pointer list-none px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                                <span class="{{ $columns }}">
                                    <span class="{{ $badge }} text-muted">—</span>
                                    <span class="min-w-0"><span class="block font-bold">TSD sold alone</span><span class="block text-xs text-muted">No main product · counts as Upsell</span></span>
                                    <span><span class="flex h-3.5 overflow-hidden rounded bg-brand-50" aria-hidden="true"><span class="bg-(--pp-upsell)" style="width: {{ min(100, round($split['tsd'] / $peak * 100, 2)) }}%"></span></span></span>
                                    <span class="text-right font-bold">₱0</span>
                                    <span class="flex flex-col items-end gap-0.5 text-xs font-bold"><span class="text-brand-700">0.0% main</span><span class="text-(--pp-upsell-text)">100% upsold</span></span>
                                    <span class="text-right font-bold text-(--pp-upsell-text)">{{ $short($split['tsd']) }}</span>
                                    <span class="text-right">{{ number_format(array_sum(array_column($split['tsd_products'], 'orders'))) }}</span>
                                </span>
                            </summary>
                            <div class="grid gap-2 px-3 pt-1 pb-4 pl-14 text-sm">
                                <h3 class="text-[0.6875rem] font-bold tracking-wide text-muted uppercase">TSD products</h3>
                                @foreach ($split['tsd_products'] as $tsdProduct)
                                    <div class="grid grid-cols-[minmax(7rem,12rem)_minmax(0,1fr)_6rem_3.5rem] items-center gap-x-2">
                                        <span class="font-semibold">{{ $tsdProduct['name'] }}</span>
                                        <span class="h-2 rounded-full bg-white" aria-hidden="true"><span class="block h-2 rounded-full bg-(--pp-upsell)" style="width: {{ round($tsdProduct['value'] / $tsdPeak * 100, 2) }}%"></span></span>
                                        <span class="text-right font-bold">{{ $peso($tsdProduct['value']) }}</span>
                                        <span class="text-right text-muted">{{ $share($tsdProduct['value'], $split['tsd']) }}</span>
                                        <span class="col-start-2 col-end-5 text-xs text-muted">{{ number_format($tsdProduct['orders']) }} {{ Str::plural('order', $tsdProduct['orders']) }} · {{ number_format($tsdProduct['qty']) }} qty</span>
                                    </div>
                                @endforeach
                                <p class="text-xs text-muted">The TSD products' own sales. Next to a CRD product Shecom leaves a TSD add-on out of gross, so this is mostly TSD bought with no main product; each counts 100% as Upsell.</p>
                            </div>
                        </details>
                    @endif
                </div>
            </div>
        </div>
    @endif
    <p class="text-xs leading-relaxed text-muted">Upsell = a TSD product (with a main product or bought alone) or a product new to the customer added to an order with one they've bought before, valued at its Pancake price. Main % = the product's own sales ÷ everything on its orders; Upsold % = what was upsold on its orders ÷ the same, so the two add up to 100%. Same figures as the Presentation Board's Top products, refreshed every {{ \App\Services\PresentationBoard::EVERY_HOURS }} hours.</p>
</section>
