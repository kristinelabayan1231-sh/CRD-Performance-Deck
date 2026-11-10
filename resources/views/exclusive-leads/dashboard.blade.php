<x-layouts.app title="Exclusive Leads Dashboard">

    @unless ($cra)
        <h1 class="mb-4 text-xl font-semibold">Exclusive Leads Dashboard</h1>
        <div class="rounded-2xl border border-line bg-white px-6 py-12 text-center shadow-sm">
            <p class="text-base font-semibold">No CRA is on the Exclusive Lead Tracker yet.</p>
            <p class="mt-1 text-sm text-muted">Once a CRA is moved there, their customers show here.</p>
        </div>
    @else
        @php($keep = ['cra' => $cra->id, 'period' => $period])
        <div class="flex flex-col gap-3">
            {{-- CRA, period and the period's totals --}}
            <div class="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-2xl border border-line bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-3">
                    <x-avatar :user="$cra" class="size-12 text-sm" />
                    <div>
                        <h1 class="text-lg leading-tight font-semibold">Exclusive Leads Dashboard · {{ $cra->displayName() }}</h1>
                        <p class="text-xs text-muted">From the leads assigned to {{ $cra->displayName() }} on the Exclusive Lead Tracker, by bottles in the delivered order that made each one a lead.</p>
                    </div>
                </div>

                <nav aria-label="Period" class="flex gap-0.5 rounded-xl bg-canvas p-1 text-sm">
                    @foreach (\App\Http\Controllers\ExclusiveLeadDashboardController::PERIODS as $key => $label)
                        <a href="{{ route('exclusive-leads.dashboard', ['cra' => $cra->id, 'period' => $key]) }}" @if ($key === $period) aria-current="page" @endif
                           @class(['flex min-h-9 items-center rounded-lg px-3.5 font-medium', 'bg-brand-600 text-white shadow-sm' => $key === $period, 'text-ink hover:bg-white' => $key !== $period])>{{ $label }}</a>
                    @endforeach
                </nav>
                <span class="text-sm text-muted">{{ $periodRange }}{{ $period !== 'today' ? ' · with the leads held for the days ahead' : '' }}</span>

                <div class="ml-auto flex flex-wrap items-center gap-5 text-sm">
                    <span><strong class="text-lg tabular-nums">{{ number_format($grid['leads']) }}</strong> customers</span>
                    <span><strong class="text-lg tabular-nums">{{ number_format($grid['bottles']) }}</strong> bottles</span>
                    @if ($cras->count() > 1)
                        <form method="GET" action="{{ route('exclusive-leads.dashboard') }}">
                            <input type="hidden" name="period" value="{{ $period }}">
                            <label class="flex h-9 items-center gap-1.5 rounded-lg border border-line px-3">CRA
                                <select name="cra" onchange="this.form.submit()" class="border-0 bg-transparent p-0 pr-6 text-sm focus:ring-0">
                                    @foreach ($cras as $option)
                                        <option value="{{ $option->id }}" @selected($option->is($cra))>{{ $option->displayName() }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Repeat, Retained and orders: only customers on the CRA's Exclusive lists for the period --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="From the Exclusive Lead Tracker lists">
                @foreach ([
                    ['Repeat', number_format($repeats['repeat']), 'CRD leads on the list who bought again'],
                    ['Retained', number_format($repeats['retained']), 'FSD leads on the list who bought again'],
                    ['Confirmed orders', number_format($listOrders['orders']), 'from customers on the list'],
                    ['Gross sales', '₱'.number_format($listOrders['gross']), $listOrders['orders'] ? 'AOV ₱'.number_format($listOrders['gross'] / $listOrders['orders']) : 'from customers on the list'],
                ] as [$label, $value, $note])
                    <div class="rounded-2xl border border-line bg-white px-5 py-3 shadow-sm">
                        <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ $label }}</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ $value }}</p>
                        <p class="text-xs text-muted">{{ $note }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.35fr)]">
                {{-- Product × bottles --}}
                <x-panel :title="'Customers by product × bottles ordered · '.\App\Http\Controllers\ExclusiveLeadDashboardController::PERIODS[$period]" icon="table" title-id="grid-title" :tinted="false" body-class="">
                    <x-slot:actions><span class="text-xs text-muted">Click a number to list those customers. Darker = more customers.</span></x-slot:actions>
                    @if ($grid['products']->isEmpty())
                        <p class="px-4 py-10 text-center text-sm text-muted">No leads on {{ $cra->displayName() }}'s lists for {{ strtolower(\App\Http\Controllers\ExclusiveLeadDashboardController::PERIODS[$period]) }}.</p>
                    @else
                        <div class="max-h-[70dvh] overflow-auto">
                            <table class="w-full text-sm tabular-nums">
                                <thead class="sticky top-0 z-10 bg-white text-xs text-muted">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-semibold">Product</th>
                                        @foreach ($grid['buckets'] as $bucket)
                                            <th class="px-1.5 py-2 font-semibold">{{ \App\Http\Controllers\ExclusiveLeadDashboardController::BUCKETS[$bucket] }}</th>
                                        @endforeach
                                        <th class="px-4 py-2 font-semibold">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($grid['products'] as $row)
                                        <tr class="border-t border-line">
                                            <th scope="row" class="px-4 py-1 text-left text-[0.8125rem] font-semibold">{{ $row['product'] }}</th>
                                            @foreach ($grid['buckets'] as $bucket)
                                                @php($count = $row['cells'][$bucket] ?? 0)
                                                @php($share = $grid['max'] > 0 ? $count / $grid['max'] : 0)
                                                @php($isPicked = $picked && $picked['product'] === $row['product'] && $picked['qty'] === $bucket)
                                                <td class="px-1 py-1 text-center">
                                                    @if ($count > 0)
                                                        <a href="{{ route('exclusive-leads.dashboard', [...$keep, 'product' => $row['product'], 'qty' => $bucket]) }}#customers-title"
                                                           @if ($isPicked) aria-current="true" @endif
                                                           title="{{ $row['product'] }} · {{ \App\Http\Controllers\ExclusiveLeadDashboardController::BUCKETS[$bucket] }}"
                                                           @class(['flex min-h-8 items-center justify-center rounded-md font-semibold transition hover:ring-2 hover:ring-brand-400',
                                                               'ring-2 ring-ink' => $isPicked,
                                                               'bg-brand-700 text-white' => $share > 0.66,
                                                               'bg-brand-400 text-[#1b1530]' => $share > 0.33 && $share <= 0.66,
                                                               'bg-brand-200 text-[#1b1530]' => $share > 0.12 && $share <= 0.33,
                                                               'bg-brand-50 text-[#1b1530]' => $share <= 0.12])>{{ number_format($count) }}</a>
                                                    @else
                                                        <span class="text-muted">–</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td class="px-4 py-1 text-center font-semibold">{{ number_format($row['total']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-panel>

                {{-- The picked cell's customers, or the search's --}}
                @php($customersTitle = $search !== null ? 'Search “'.$search.'” · '.number_format($leads?->total() ?? 0).' '.\Illuminate\Support\Str::plural('customer', $leads?->total() ?? 0) : ($picked ? $picked['product'].' · '.\App\Http\Controllers\ExclusiveLeadDashboardController::BUCKETS[$picked['qty']].' · '.number_format($picked['count']).' '.\Illuminate\Support\Str::plural('customer', $picked['count']) : 'Customers'))
                <x-panel :title="$customersTitle" icon="users" title-id="customers-title" :tinted="false" body-class="">
                    <x-slot:actions>
                        <span class="flex flex-wrap items-center gap-3 text-xs text-muted">
                            <span>Soonest to run out first</span>
                            <button type="button" data-expand-src="{{ route('exclusive-leads.dashboard', [...$listFilters, 'full' => 1]) }}" data-expand-title="{{ $customersTitle }}"
                                    title="Open the list full size" aria-label="Open the customer list full size"
                                    class="flex h-8 items-center gap-1.5 rounded-lg border border-line bg-white px-2.5 text-xs font-semibold text-ink hover:border-brand-400 hover:text-brand-700">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>
                                Expand
                            </button>
                        </span>
                    </x-slot:actions>
                    @include('exclusive-leads._customers')
                </x-panel>
            </div>

            {{-- Follow-ups --}}
            <div class="grid gap-3 md:grid-cols-2">
                <button type="button" data-expand-src="{{ $callbacksUrl }}" data-expand-title="Call back for today · {{ $cra->displayName() }}"
                        class="flex min-h-14 items-center justify-between gap-3 rounded-2xl bg-brand-600 px-5 py-2.5 text-left text-white shadow-sm hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                    <span class="flex items-center gap-3 text-sm">
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
                        <span><strong class="text-xl tabular-nums">{{ number_format($callbacks) }}</strong> {{ \Illuminate\Support\Str::plural('customer', $callbacks) }} {{ $cra->displayName() }} needs to call back today</span>
                    </span>
                    <span class="text-sm font-semibold whitespace-nowrap">Open call backs ›</span>
                </button>
                <p class="flex min-h-14 items-center gap-3 rounded-2xl border-2 border-[#f2c94c] bg-[#fff8dc] px-5 py-2.5 text-sm">
                    <strong class="text-xl text-[#7a4f00] tabular-nums">{{ number_format($reactivation) }}</strong>
                    <span>{{ \Illuminate\Support\Str::plural('customer', $reactivation) }} for <strong>Reactivation</strong>. Remind {{ $cra->displayName() }} to reach them.</span>
                </p>
            </div>
        </div>

        <dialog id="expand-dialog" aria-labelledby="expand-title" class="m-auto h-[90dvh] w-[min(96rem,calc(100%-2rem))] overflow-hidden rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
            <div class="flex h-full flex-col">
                <div class="flex shrink-0 items-center justify-between gap-4 border-b border-line px-5 py-3">
                    <h2 id="expand-title" class="text-base font-semibold" data-expand-title></h2>
                    <button type="button" data-expand-close aria-label="Close" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <iframe title="Call back for today" class="min-h-0 w-full flex-1 border-0" src="about:blank"></iframe>
            </div>
        </dialog>
    @endunless
</x-layouts.app>
