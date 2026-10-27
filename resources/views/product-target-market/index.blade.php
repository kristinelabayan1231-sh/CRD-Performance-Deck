@php
    use App\Services\ProductTargetMarket;

    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $percent = fn (?float $rate) => $rate === null ? '—' : round($rate * 100).'%';
    $bands = array_keys(ProductTargetMarket::BANDS);
    $statuses = [
        'on' => ['On target', 'bg-teal/15 text-teal-700'],
        'partly' => ['Partly', 'bg-amber-100 text-amber-800'],
        'off' => ['Off target', 'bg-coral/20 text-coral-700'],
        'no_target' => ['No target', 'bg-canvas text-muted'],
        'no_ages' => ['No ages yet', 'bg-canvas text-muted'],
    ];
    $pill = fn (string $status) => '<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold whitespace-nowrap '.$statuses[$status][1].'"><span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>'.$statuses[$status][0].'</span>';
    // The first product off its target opens in the panel, else the one with the most customers.
    $selected = $products->firstWhere('status', 'off') ?? $products->first();
@endphp

<x-layouts.app title="Product Target Market">
    <div class="space-y-5">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Product Target Market</h1>
                <p class="max-w-3xl text-sm text-muted">{{ $label }} · the ages of each product's customers, from "Age: 17" in their Pancake POS internal order notes, against its target in Settings → Age Bracket. Every Pancake order of the product counts, each customer once.</p>
            </div>

            {{-- Month, last 3 months or From–To --}}
            <form method="GET" action="{{ route('product-target-market.index') }}" class="flex flex-wrap items-end gap-3" aria-label="Dates">
                @if ($period !== 'range')
                    <input type="hidden" name="from" value="{{ $from->toDateString() }}">
                    <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                @endif
                <input type="hidden" name="period" value="{{ $period }}" disabled data-period>
                @php $submit = "this.form.querySelector('[data-period]').disabled = false; this.form.submit()"; @endphp

                <div class="flex flex-wrap rounded-lg border border-line bg-white p-0.5" role="group" aria-label="Period">
                    @foreach (['month' => 'Month', 'quarter' => 'Last 3 months', 'range' => 'Range'] as $value => $name)
                        <button type="submit" name="period" value="{{ $value }}" aria-pressed="{{ $period === $value ? 'true' : 'false' }}"
                                @class(['h-8 rounded-md px-3 text-sm font-semibold', 'bg-brand-600 text-white' => $period === $value, 'text-muted hover:text-brand-600' => $period !== $value])>{{ $name }}</button>
                    @endforeach
                </div>

                @if ($period === 'month')
                    <label>
                        <span class="sr-only">Month</span>
                        <select name="month" onchange="{{ $submit }}" class="{{ $control }} font-semibold text-ink">
                            @for ($m = 0; $m < 12; $m++)
                                @php $option = $today->startOfMonth()->subMonths($m); @endphp
                                <option value="{{ $option->format('Y-m') }}" @selected($from->isSameMonth($option))>{{ $option->format('F Y') }}</option>
                            @endfor
                        </select>
                    </label>
                @elseif ($period === 'range')
                    <div class="flex items-center gap-1.5">
                        <input type="date" name="from" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="From" onchange="{{ $submit }}" class="{{ $control }}">
                        <span class="text-sm text-muted">to</span>
                        <input type="date" name="to" value="{{ $to->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="To" onchange="{{ $submit }}" class="{{ $control }}">
                    </div>
                @endif
                <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white">Apply</button></noscript>
            </form>
        </header>

        {{-- How many customers have an age, and fetching the last 3 months' noted ages again --}}
        <section class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl bg-white p-4 shadow-sm" aria-label="Customers with an age">
            <div class="min-w-0 flex-1">
                <p class="text-base font-semibold tabular-nums">{{ number_format($withAge) }} of {{ number_format($customers) }} {{ Str::plural('customer', $customers) }} have an age{{ $customers ? ' ('.$percent($withAge / $customers).')' : '' }}</p>
                <p class="text-sm text-muted">
                    {{ number_format($customers - $withAge) }} have no age in their POS notes, so they are left out of the percentages.
                    CRAs type it in the order's internal note as <span class="font-semibold text-ink">Age: 17</span>; ages are saved with every order sync{{ $agesFetchedAt ? ', and the last 3 months were last fetched '.$agesFetchedAt->diffForHumans() : '' }}.
                </p>
            </div>
            <div class="h-2.5 w-full max-w-60 overflow-hidden rounded-full bg-canvas" aria-hidden="true">
                <div class="h-full bg-teal" style="width: {{ $customers ? round($withAge / $customers * 100, 1) : 0 }}%"></div>
            </div>
            <form method="POST" action="{{ route('product-target-market.ages') }}">
                @csrf
                <button type="submit" @disabled($agesRunning)
                        class="h-9 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-brand-700 transition hover:bg-brand-50 disabled:cursor-wait disabled:text-muted">
                    {{ $agesRunning ? 'Fetching ages…' : 'Fetch ages (last 3 months)' }}
                </button>
            </form>
        </section>

        @if ($products->isEmpty())
            <section class="rounded-xl bg-white p-6 text-sm text-muted shadow-sm">No Pancake orders of a listed product in {{ $label }}.</section>
        @else
            <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]" data-target-market>
                <section class="min-w-0 rounded-xl bg-white p-4 shadow-sm">
                    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-base font-semibold">Share of customers by age</h2>
                        <div class="flex flex-wrap gap-4 text-xs text-muted">
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-sm" style="background: color-mix(in srgb, var(--color-brand-500) 70%, transparent)"></span>More customers</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-sm" style="background: color-mix(in srgb, var(--color-brand-500) 12%, transparent)"></span>Fewer</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-sm outline-2 -outline-offset-2 outline-brand-600 outline-dashed"></span>Target bracket</span>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[46rem] border-separate border-spacing-1 text-sm tabular-nums">
                            <thead>
                                <tr class="text-left text-[11px] font-semibold tracking-wide text-muted uppercase">
                                    <th class="px-2 py-1">Product</th>
                                    @foreach ($bands as $band)
                                        <th class="whitespace-nowrap px-2 py-1 text-center">{{ ProductTargetMarket::bandLabel($band) }}</th>
                                    @endforeach
                                    <th class="whitespace-nowrap px-2 py-1 text-right">With age</th>
                                    <th class="whitespace-nowrap px-2 py-1 text-right">In target</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($products as $row)
                                    @php $product = $row['product']; @endphp
                                    <tr tabindex="0" class="group cursor-pointer" data-tm-row="{{ $product->id }}" aria-selected="{{ $row === $selected ? 'true' : 'false' }}">
                                        <td class="rounded-lg px-2 py-1.5 font-semibold whitespace-nowrap group-hover:text-brand-700 group-aria-selected:bg-brand-50 group-aria-selected:text-brand-700">
                                            {{ $product->name }}
                                            <span class="block text-xs font-medium text-muted">Target: {{ ProductTargetMarket::bracketLabel($product->age_min, $product->age_max) }}</span>
                                        </td>
                                        @foreach ($bands as $band)
                                            @php $share = $row['withAge'] ? $row['bands'][$band] / $row['withAge'] : null; @endphp
                                            <td @class([
                                                    'h-10 min-w-16 rounded-lg px-2 text-center text-xs font-semibold',
                                                    'outline-2 -outline-offset-2 outline-brand-600 outline-dashed' => ProductTargetMarket::bandInTarget($product, $band),
                                                    'text-white' => $share > 0.28,
                                                    'text-muted' => $share === null,
                                                ])
                                                @if ($share !== null) style="background: color-mix(in srgb, var(--color-brand-500) {{ round(8 + $share * 150) }}%, transparent)" @endif
                                                title="{{ ProductTargetMarket::bandLabel($band) }}: {{ $row['bands'][$band] }} {{ Str::plural('customer', $row['bands'][$band]) }}">
                                                {{ $share === null ? '—' : $percent($share) }}
                                            </td>
                                        @endforeach
                                        <td class="whitespace-nowrap px-2 text-right tabular-nums">{{ number_format($row['withAge']) }}<span class="block text-xs text-muted">of {{ number_format($row['customers']) }}</span></td>
                                        <td class="whitespace-nowrap px-2 text-right">{!! $pill($row['status']) !!}<span class="block text-xs text-muted">{{ $percent($row['share']) }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs text-muted">Each row adds up to 100% of that product's customers with an age, aged on the day of their first order of it (the noted age plus the years since the note). On target = {{ ProductTargetMarket::ON_TARGET * 100 }}% or more inside the bracket · Partly = {{ ProductTargetMarket::PARTLY * 100 }}–{{ ProductTargetMarket::ON_TARGET * 100 - 1 }}% · Off target = under {{ ProductTargetMarket::PARTLY * 100 }}%. Click a product for details.</p>
                </section>

                {{-- One panel per product; the selected row's shows --}}
                @foreach ($products as $row)
                    @php
                        $product = $row['product'];
                        $most = max(1, max($row['bands']));
                    @endphp
                    <aside class="flex flex-col gap-4 rounded-xl bg-white p-4 shadow-sm xl:sticky xl:top-4" data-tm-panel="{{ $product->id }}" @if ($row !== $selected) hidden @endif aria-label="{{ $product->name }}">
                        <div>
                            <p class="text-xs text-muted">Selected product</p>
                            <h2 class="text-base font-semibold">{{ $product->name }}</h2>
                        </div>
                        <div class="flex items-end justify-between gap-2">
                            <div>
                                <p class="text-3xl leading-none font-bold tabular-nums">{{ $percent($row['share']) }}</p>
                                <p class="text-xs text-muted">of customers with an age are in the target</p>
                            </div>
                            {!! $pill($row['status']) !!}
                        </div>
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <div class="rounded-lg bg-canvas p-2.5"><dt class="text-xs text-muted">Target (Settings)</dt><dd class="font-semibold">{{ ProductTargetMarket::bracketLabel($product->age_min, $product->age_max) }}</dd></div>
                            <div class="rounded-lg bg-canvas p-2.5"><dt class="text-xs text-muted">{{ ProductTargetMarket::ON_TARGET * 100 }}% of customers are</dt><dd class="font-semibold">{{ $row['suggested'] ? ProductTargetMarket::bandsLabel($row['suggested']) : '—' }}</dd></div>
                            <div class="rounded-lg bg-canvas p-2.5"><dt class="text-xs text-muted">Most customers</dt><dd class="font-semibold">{{ $row['top'] !== null ? ProductTargetMarket::bandLabel($row['top']) : '—' }}</dd></div>
                            <div class="rounded-lg bg-canvas p-2.5"><dt class="text-xs text-muted">Customers with age</dt><dd class="font-semibold tabular-nums">{{ number_format($row['withAge']) }} of {{ number_format($row['customers']) }}</dd></div>
                        </dl>
                        <ul class="flex flex-col gap-1.5 text-xs tabular-nums">
                            @foreach ($bands as $band)
                                @php $inTarget = ProductTargetMarket::bandInTarget($product, $band); @endphp
                                <li class="grid grid-cols-[3.5rem_1fr_2.5rem] items-center gap-2">
                                    <span @class(['font-bold text-brand-700' => $inTarget])>{{ ProductTargetMarket::bandLabel($band) }}</span>
                                    <span class="h-3.5 overflow-hidden rounded bg-canvas"><span @class(['block h-full', 'bg-brand-500' => $inTarget, 'bg-[#d9d3df]' => ! $inTarget]) style="width: {{ round($row['bands'][$band] / $most * 100, 1) }}%"></span></span>
                                    <span class="text-right">{{ $row['bands'][$band] }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($row['inTarget'] !== null && $row['withAge'])
                            <p class="text-xs text-muted">{{ $row['younger'] }} younger and {{ $row['older'] }} older than the target.</p>
                        @endif
                        @if ($canEditTargets)
                            <a href="{{ route('settings.age-bracket.index') }}" class="inline-flex h-9 items-center justify-center rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">Edit target in Settings</a>
                        @endif
                    </aside>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
