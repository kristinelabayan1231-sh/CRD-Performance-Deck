<x-layouts.app title="Connections">
    @include('settings._header')

    <section class="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Connection check</h2>
                <p class="text-sm text-muted">Sends one small request from this server to the database, Shecom and Pancake. Takes 10–20 seconds.</p>
                <p class="mt-1 text-sm text-muted">Pancake POS syncs use the {{ $usesAccessToken ? 'access token (PANCAKE_ACCESS_TOKEN)' : 'API key (PANCAKE_API_KEY)' }}.</p>
            </div>
            <form method="POST" action="{{ route('settings.connections.run') }}" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Checking…'">
                @csrf
                <button type="submit" class="h-11 rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700 disabled:opacity-60">Run check</button>
            </form>
        </div>
    </section>

    @if ($results)
        <section class="overflow-hidden rounded-xl bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-line px-4 py-4">
                <h2 class="text-base font-semibold">Results</h2>
                <span class="text-sm text-muted">{{ $checkedAt?->timezone(config('segmentation.timezone'))->format('M j, g:i:s A') }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Connection</th>
                            <th class="px-4 py-3 font-semibold">Result</th>
                            <th class="px-4 py-3 font-semibold">Details</th>
                            <th class="px-4 py-3 text-right font-semibold">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($results as $row)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $row['name'] }}</td>
                                <td class="px-4 py-3">
                                    @if ($row['ok'])
                                        <span class="rounded-full bg-teal/15 px-2.5 py-1 text-xs font-semibold text-teal-700">✓ OK</span>
                                    @else
                                        <span class="rounded-full bg-coral/15 px-2.5 py-1 text-xs font-semibold text-coral-700">✕ Failed</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 break-all text-muted">{{ $row['details'] }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-muted">{{ $row['seconds'] }}s</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @else
        <p class="rounded-xl bg-white px-4 py-10 text-center text-sm text-muted shadow-sm">Click Run check to test the connections from this server.</p>
    @endif
</x-layouts.app>
