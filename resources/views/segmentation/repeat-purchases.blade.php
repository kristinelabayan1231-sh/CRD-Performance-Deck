@php([$title, $typeLabel] = \App\Http\Controllers\RepeatPurchaseController::SEGMENTS[$segment])

<x-layouts.app :title="$title">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">{{ $title }} <span class="text-sm font-normal text-muted">· {{ $range->label() }} · {{ $typeLabel }} in the Segmentation Tracker with Repeat Purchase = Yes bought in these dates, one per customer{{ $canViewAll ? ($cra ? ' · '.$cras->firstWhere('id', $cra)?->displayName() : '') : ' · your leads' }}</span></h1>
            <a href="{{ route('dashboard', $dates) }}" class="text-sm font-semibold text-brand-600 hover:underline">&larr; Dashboard</a>
        </header>

        {{-- Retained / Repeat for the same dates and CRA; supervisors pick a CRA --}}
        <div class="flex flex-wrap items-center gap-3">
        <nav class="flex w-full rounded-lg border border-line bg-white p-0.5 sm:w-fit" aria-label="Customers">
            @foreach (\App\Http\Controllers\RepeatPurchaseController::SEGMENTS as $key => [$tabLabel, $tabType])
                <a href="{{ route('segmentation.repeat-purchases', array_filter([...$dates, 'segment' => $key, 'cra' => $cra])) }}" aria-current="{{ $key === $segment ? 'page' : 'false' }}"
                   @class(['flex-1 rounded-md px-3 py-1.5 text-center text-sm font-semibold sm:flex-none', 'bg-brand-600 text-white' => $key === $segment, 'text-muted hover:text-brand-600' => $key !== $segment])>
                    {{ $tabLabel }} <span class="tabular-nums">{{ number_format($counts[$key]) }}</span>
                    <span @class(['block text-[11px] font-medium', 'text-white/80' => $key === $segment, 'text-muted' => $key !== $segment])>{{ $tabType }}</span>
                </a>
            @endforeach
        </nav>

        @if ($canViewAll)
            <form method="GET" action="{{ route('segmentation.repeat-purchases') }}" class="w-full sm:w-auto">
                @foreach ([...$dates, 'segment' => $segment] as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <label class="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted uppercase">
                    CRA
                    <select name="cra" onchange="this.form.submit()" class="h-9 w-full rounded-lg border border-line bg-white px-2.5 text-sm font-semibold text-ink normal-case focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none sm:w-44">
                        <option value="">All CRAs</option>
                        @foreach ($cras as $option)
                            <option value="{{ $option->id }}" @selected($cra === $option->id)>{{ $option->displayName() }}</option>
                        @endforeach
                    </select>
                </label>
            </form>
        @endif
        </div>

        <section class="overflow-hidden rounded-xl border border-line bg-white shadow-sm" aria-label="{{ $title }}">
            @if ($customers->isEmpty())
                <p class="px-4 py-8 text-center text-sm text-muted">No {{ strtolower($title) }} in these dates.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <thead class="bg-[#ecfaf6] text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Bought</th>
                                <th class="px-4 py-3 font-semibold">Customer</th>
                                <th class="px-4 py-3 font-semibold">Product</th>
                                <th class="px-4 py-3 font-semibold">Lead day</th>
                                @if ($canViewAll && ! $cra)
                                    <th class="px-4 py-3 font-semibold">CRA</th>
                                @endif
                                <th class="px-4 py-3 font-semibold">From</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($customers as $lead)
                                @php($fromPos = str_starts_with($lead->order_id, \App\Models\Lead::POS_PREFIX))
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap tabular-nums">{{ $lead->repeat_purchase_on->format('M j, Y') }}</td>
                                    <td class="px-4 py-3">
                                        <span class="font-semibold text-ink">{{ $lead->customer_name ?: '—' }}</span>
                                        <span class="block text-xs text-muted tabular-nums">{{ $lead->phone_number }}</span>
                                    </td>
                                    <td class="px-4 py-3">{{ $lead->product_name }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap tabular-nums">{{ $lead->est_out_of_stock_date->format('M j, Y') }}</td>
                                    @if ($canViewAll && ! $cra)
                                        <td class="px-4 py-3">{{ $lead->assignee?->displayName() ?? '—' }}</td>
                                    @endif
                                    <td class="px-4 py-3">
                                        @if ($fromPos)
                                            <span class="rounded-full bg-[#bfe1f6] px-2 py-0.5 text-xs font-semibold text-[#0a53a8]">POS order #{{ substr($lead->order_id, strlen(\App\Models\Lead::POS_PREFIX)) }}</span>
                                        @else
                                            <span class="rounded-full bg-[#d4edbc] px-2 py-0.5 text-xs font-semibold text-[#11734b]">Tracker lead</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
