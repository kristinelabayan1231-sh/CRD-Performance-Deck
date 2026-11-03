<x-layouts.settings title="Daily I.S.">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('settings.daily-is.update') }}">
        @csrf
        @method('PUT')

        <section class="rounded-xl bg-white p-4 shadow-sm">
            <h2 class="text-base font-semibold">Deactivated products</h2>
            <p class="text-sm text-muted">A deactivated product doesn't show in the Daily I.S. on a day it has no sales. On a day it has sales, it shows with a "Deactivated" label.</p>

            <ul class="mt-4 divide-y divide-line rounded-lg border border-line">
                @forelse ($products as $product)
                    <li>
                        <label class="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-sm">
                            <input type="checkbox" name="deactivated[]" value="{{ $product['group'] }}" @checked($product['deactivated'])
                                   class="size-4 rounded border-line text-brand-600 focus:ring-brand-200">
                            <span class="font-semibold">{{ $product['name'] }}</span>
                            @if ($product['name'] !== $product['group'])
                                <span class="text-xs text-muted">{{ $product['group'] }}</span>
                            @endif
                            @if ($product['deactivated'])
                                <span class="ml-auto rounded-full bg-coral/15 px-2 py-0.5 text-xs font-semibold text-coral-700">Deactivated</span>
                            @endif
                        </label>
                    </li>
                @empty
                    <li class="px-3 py-4 text-sm text-muted">No product groups yet. Add them in Pancake Pages.</li>
                @endforelse
            </ul>

            <div class="mt-4 flex items-center gap-3">
                <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">Save</button>
                @if ($lastChange?->editor)
                    <p class="text-xs text-muted">Last changed by {{ $lastChange->editor->displayName() }} on {{ $lastChange->updated_at->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.</p>
                @endif
            </div>
        </section>
    </form>

    {{-- Shecom's product costs: what the Daily I.S. Product Cost uses (cost per piece × pieces, by the item's Pancake name). --}}
    <section class="mt-6 rounded-xl bg-white p-4 shadow-sm" aria-labelledby="shecom-costs-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="shecom-costs-title" class="text-base font-semibold">Product costs from Shecom</h2>
                <p class="text-sm text-muted">The Daily I.S. Product Cost: each item's cost per piece × its pieces, matched by the item's name in Pancake. Synced every hour{{ $costsUpdatedAt ? ', last on '.\Carbon\CarbonImmutable::parse($costsUpdatedAt)->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') : '' }}. To change a cost, change it in Shecom.</p>
            </div>
            @can('daily_is.view_all')
                <form method="POST" action="{{ route('daily-is.product-costs.refresh') }}">
                    @csrf
                    <button type="submit" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-line bg-white px-3 text-xs font-semibold text-ink hover:border-brand-400 hover:text-brand-700">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>
                        Refresh product costs
                    </button>
                </form>
            @endcan
        </div>

        @if ($missingCosts !== [])
            <div role="note" class="mt-4 rounded-lg border border-coral/40 bg-coral/10 px-4 py-3 text-sm">
                <p class="font-semibold text-coral-700">{{ count($missingCosts) }} {{ Str::plural('item', count($missingCosts)) }} sold in the last {{ $missingDays }} days {{ count($missingCosts) === 1 ? 'has' : 'have' }} no cost in Shecom, so {{ count($missingCosts) === 1 ? 'it counts' : 'they count' }} ₱0</p>
                <ul class="mt-1 space-y-0.5 text-ink">
                    @foreach ($missingCosts as $missing)
                        <li><span class="font-semibold">{{ $missing['item'] }}</span> <span class="text-muted">· {{ $missing['product'] }} · {{ number_format($missing['pieces']) }} {{ Str::plural('piece', $missing['pieces']) }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-4 max-h-[32rem] overflow-auto rounded-lg border border-line">
            <table class="w-full text-sm tabular-nums">
                <thead class="sticky top-0 bg-canvas text-left text-xs font-semibold text-muted">
                    <tr>
                        <th scope="col" class="px-3 py-2">Item in Pancake</th>
                        <th scope="col" class="px-3 py-2">Counts as</th>
                        <th scope="col" class="px-3 py-2 text-right">Cost per piece</th>
                        <th scope="col" class="px-3 py-2">From</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($costs as $row)
                        <tr @class(['text-muted' => $row['product'] === null])>
                            <td class="px-3 py-1.5 font-medium">{{ $row['item'] }}</td>
                            <td class="px-3 py-1.5">{{ $row['product'] ?? 'Not on Product Consumption' }}</td>
                            <td class="px-3 py-1.5 text-right">₱{{ number_format($row['cost'], 2) }}</td>
                            <td class="px-3 py-1.5">{{ $row['from'] ? \Carbon\CarbonImmutable::parse($row['from'])->format('M j, Y') : 'Start' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-4 text-muted">No product costs from Shecom yet. Use Refresh product costs.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.settings>
