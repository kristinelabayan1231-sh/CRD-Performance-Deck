<x-layouts.app title="Performance Deck">
    {{-- One screen: sales goals + conversion on top, Segmentation Tracker below. --}}
    <div class="space-y-4">
        <h1 class="text-xl font-semibold">Performance Deck <span class="text-sm font-normal text-muted">· Welcome, {{ auth()->user()->displayName() }}.</span></h1>

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
