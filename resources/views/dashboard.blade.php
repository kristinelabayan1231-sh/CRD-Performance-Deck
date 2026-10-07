<x-layouts.app title="Performance Deck">
    {{-- One screen: sales goals + conversion on top, Segmentation Tracker below. --}}
    <div class="space-y-4">
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
            <div class="grid gap-4 lg:grid-cols-12">
                @if ($salesGoals)
                    <x-dashboard.sales-goals :goals="$salesGoals" :class="$conversion ? 'lg:col-span-8' : 'lg:col-span-12'" />
                @endif
                @if ($conversion)
                    <x-dashboard.conversion :periods="$conversion" class="lg:col-span-4" />
                @endif
            </div>
        @endif

        @if ($logistics)
            <x-dashboard.logistics :periods="$logisticsPeriods" :fetched-at="$logisticsFetchedAt" />
        @endif

        @if ($segmentation)
            <x-dashboard.segmentation :periods="$segmentation" />
        @endif

        @if (! $segmentation && ! $salesGoals)
            <div class="rounded-xl bg-white p-6 text-muted shadow-sm">No modules are available to you yet.</div>
        @endif
    </div>
</x-layouts.app>
