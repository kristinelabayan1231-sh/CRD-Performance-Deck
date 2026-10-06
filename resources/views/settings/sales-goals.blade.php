@php($control = 'h-10 w-full rounded-lg border border-line bg-white pr-3 pl-7 text-sm tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')

<x-layouts.app title="Sales Goals">
    @include('settings._header')

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('settings.sales-goals.update') }}" class="space-y-4">
        @csrf
        @method('PUT')

        <section class="rounded-xl bg-white p-4 shadow-sm">
            <h2 class="text-base font-semibold">Sales goals</h2>
            <p class="text-sm text-muted">Sales are gross sales from Conversion Breakdown: orders tagged CRD - BROADCAST plus CRD - SEGMENTATION. The dashboard shows progress against these goals.</p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:max-w-2xl">
                <label class="block">
                    <span class="mb-1 block text-sm font-semibold">CRA daily goal</span>
                    <span class="relative block">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted">₱</span>
                        <input type="number" name="cra_daily" min="0" step="0.01" required value="{{ old('cra_daily', $craDaily + 0) }}" class="{{ $control }}">
                    </span>
                    <span class="mt-1 block text-xs text-muted">Each CRA's sales base per day, unless a CRA has their own goal below.</span>
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-semibold">CRD monthly goal</span>
                    <span class="relative block">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted">₱</span>
                        <input type="number" name="crd_monthly" min="0" step="0.01" required value="{{ old('crd_monthly', $crdMonthly + 0) }}" class="{{ $control }}">
                    </span>
                    <span class="mt-1 block text-xs text-muted">The whole CRD team's sales target for the month.</span>
                </label>
            </div>
            @if ($lastChange?->editor)
                <p class="mt-4 text-xs text-muted">Last changed by {{ $lastChange->editor->displayName() }} on {{ $lastChange->updated_at->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.</p>
            @endif
        </section>

        <section class="overflow-hidden rounded-xl bg-white shadow-sm">
            <header class="px-4 pt-4 pb-3">
                <h2 class="text-base font-semibold">Daily goal per CRA <span class="text-sm font-normal text-muted">(optional)</span></h2>
                <p class="text-sm text-muted">Leave blank to use the CRA daily goal (₱{{ number_format($craDaily) }}). Set an amount only when management gives a CRA a different goal.</p>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="px-4 py-3 font-semibold">CRA</th>
                            <th class="px-4 py-3 font-semibold">Own daily goal</th>
                            <th class="px-4 py-3 font-semibold">Goal used</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($cras as $cra)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $cra->displayName() }}</td>
                                <td class="px-4 py-3">
                                    <label class="relative block w-48">
                                        <span class="sr-only">Own daily goal for {{ $cra->displayName() }}</span>
                                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted">₱</span>
                                        <input type="number" name="cra_goals[{{ $cra->id }}]" min="0" step="0.01" placeholder="{{ number_format($craDaily) }}"
                                               value="{{ old('cra_goals.'.$cra->id, $cra->daily_sales_goal !== null ? $cra->daily_sales_goal + 0 : '') }}" class="{{ $control }}">
                                    </label>
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    ₱{{ number_format(\App\Support\SalesGoals::dailyFor($cra, $craDaily)) }}
                                    <span class="ml-1 text-xs text-muted">{{ $cra->daily_sales_goal !== null ? 'own goal' : 'general' }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-center text-muted">No active CRAs yet. Give users the CRA role in User Access.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit" class="h-11 rounded-lg bg-brand-600 px-4 font-semibold text-white shadow-sm transition hover:bg-brand-700">Save goals</button>
        </div>
    </form>
</x-layouts.app>
