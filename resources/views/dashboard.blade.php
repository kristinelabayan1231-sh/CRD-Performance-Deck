<x-layouts.app title="Performance Deck">
    {{--
        Three groups, each module in its own frame with its own period tabs:
        results (real date) · logistics (company-wide) · leads (the tracker's working date).
    --}}
    @php($realToday = \App\Support\WorkingDate::realToday())
    @php($workingDate = \App\Support\WorkingDate::get())
    @php($eyebrow = 'mb-2 flex flex-wrap items-baseline gap-x-2 text-[11px] font-semibold tracking-wider text-muted uppercase')
    <div class="space-y-6">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold">Performance Deck</h1>
            <x-dashboard.poster />
        </div>

        @if ($qtyUnknown->isNotEmpty())
            <details role="alert" class="rounded-xl border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-3 text-sm text-[#473821]">
                <summary class="cursor-pointer font-semibold">
                    {{ $qtyUnknown->count() }} FSD {{ Str::plural('lead', $qtyUnknown->count()) }} with no quantity in Pancake: qty 1 assumed, so the out-of-stock date may be early.
                </summary>
                <ul class="mt-2 space-y-1">
                    @foreach ($qtyUnknown->take(50) as $lead)
                        <li>{{ $lead->est_out_of_stock_date->format('M j') }} · {{ $lead->customer_name }} · {{ $lead->product_name }} · order {{ $lead->order_id }} · {{ $lead->assignee?->displayName() ?? 'Unassigned' }}</li>
                    @endforeach
                    @if ($qtyUnknown->count() > 50)
                        <li class="text-muted">…and {{ $qtyUnknown->count() - 50 }} more.</li>
                    @endif
                </ul>
            </details>
        @endif

        @if ($salesGoals || $conversion)
            <div>
                <p class="{{ $eyebrow }}">
                    <span>Results</span>
                    <span class="font-medium normal-case tracking-normal">· Pancake sales and conversion by the real date, {{ $realToday->format('D, M j') }}</span>
                </p>
                <div class="grid gap-4 lg:grid-cols-12">
                    @if ($salesGoals)
                        <x-dashboard.sales-goals :goals="$salesGoals" :class="$conversion ? 'lg:col-span-8' : 'lg:col-span-12'" />
                    @endif
                    @if ($conversion)
                        <x-dashboard.conversion :periods="$conversion" class="lg:col-span-4" />
                    @endif
                </div>
            </div>
        @endif

        @if ($logistics)
            <div>
                <p class="{{ $eyebrow }}">
                    <span>Logistics</span>
                    <span class="font-medium normal-case tracking-normal">· Company-wide, by delivery date</span>
                </p>
                <x-dashboard.logistics :periods="$logisticsPeriods" :fetched-at="$logisticsFetchedAt" />
            </div>
        @endif

        @if ($segmentation)
            <div>
                <p class="{{ $eyebrow }}">
                    <span>Leads</span>
                    <span class="font-medium normal-case tracking-normal">· Segmentation Tracker by lead day{{ $workingDate ? ', working date '.$workingDate->format('D, M j') : '' }}</span>
                </p>
                <x-dashboard.segmentation :periods="$segmentation" />
            </div>
        @endif

        @if (! $segmentation && ! $salesGoals)
            <div class="rounded-xl bg-white p-6 text-muted shadow-sm">No modules are available to you yet.</div>
        @endif
    </div>
</x-layouts.app>
