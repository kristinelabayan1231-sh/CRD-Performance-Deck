@php
    use App\Models\CallRecording;

    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $submit = 'this.form.submit()';
    $tz = config('segmentation.timezone');
    $today = \App\Support\WorkingDate::realToday();
    $label = $from->isSameDay($to) ? $from->format('D, M j') : $from->format('M j').' – '.$to->format('M j, Y');
    $canViewAll = auth()->user()->can('call_recordings.view_all');
    $longestAht = max(1, collect($rows)->max('aht') ?? 0);
@endphp

<x-layouts.app title="Call Recordings">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Call Recordings <span class="text-sm font-normal text-muted">· {{ $label }} · calls uploaded from the CRAs' phones; AHT is the average call length</span></h1>
        </header>

        @unless ($driveConnected)
            <div role="status" class="rounded-lg border border-line bg-white px-4 py-3 text-sm">
                Google Drive isn't connected yet, so the phones can't upload recordings.
                @can('call_recordings.manage') <a href="{{ route('settings.call-recordings.index') }}" class="font-semibold text-brand-600 hover:underline">Connect it in Settings → Call Recordings</a>. @endcan
            </div>
        @endunless

        <section aria-label="Totals" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['Calls', number_format($team['calls']), 'from-brand-600 to-brand-700'],
                ['AHT', CallRecording::formatDuration($team['aht']), 'from-[#0e8f7c] to-[#0b6b5d]'],
                ['Talk time', CallRecording::formatDuration($team['talk']), 'from-[#1f8fb8] to-[#156c8c]'],
                ['No matching lead', number_format($team['unmatched']), 'from-[#d99a0b] to-[#a8740a]'],
            ] as [$title, $value, $colors])
                <div class="relative overflow-hidden rounded-xl bg-gradient-to-br p-4 text-white shadow-sm {{ $colors }}">
                    <span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/15"></span>
                    <p class="relative text-[0.6875rem] font-semibold tracking-wide text-white/95 uppercase">{{ $title }}</p>
                    <p class="relative text-2xl font-bold tabular-nums">{{ $value }}</p>
                </div>
            @endforeach
        </section>

        <form method="GET" action="{{ route('call-recordings.index') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-brand-200 bg-gradient-to-r from-brand-100 to-brand-50 p-4 shadow-sm" aria-label="Dates and filters">
            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold tracking-wide text-muted uppercase">From – to</span>
                <div class="flex items-center gap-1.5">
                    <input type="date" name="from" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="From" onchange="{{ $submit }}" class="{{ $control }}">
                    <span class="text-sm text-muted">to</span>
                    <input type="date" name="to" value="{{ $to->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="To" onchange="{{ $submit }}" class="{{ $control }}">
                </div>
            </div>

            @if ($canViewAll)
                <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    CRA
                    <select name="cra" onchange="{{ $submit }}" class="{{ $control }} text-ink normal-case">
                        <option value="">All CRAs</option>
                        @foreach ($cras as $cra)
                            <option value="{{ $cra->id }}" @selected($craId === $cra->id)>{{ $cra->displayName() }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold tracking-wide text-muted uppercase">Lead</span>
                <div class="flex gap-1.5">
                    @foreach (['' => 'All', 'matched' => 'Matched', 'unmatched' => 'No match'] as $value => $name)
                        <a href="{{ request()->fullUrlWithQuery(['match' => $value ?: null, 'page' => null]) }}"
                           @class(['grid h-9 place-items-center rounded-lg border px-3 text-xs font-semibold', 'border-brand-500 bg-brand-50 text-brand-700' => ($filters['match'] ?? '') === $value, 'border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600' => ($filters['match'] ?? '') !== $value])>{{ $name }}</a>
                    @endforeach
                </div>
            </div>
            @isset($filters['match'])
                <input type="hidden" name="match" value="{{ $filters['match'] }}">
            @endisset
            <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white">Apply</button></noscript>
        </form>

        @if ($canViewAll)
            <x-panel title="AHT per CRA" icon="clock" accent="teal" bodyClass="p-0" :tinted="false">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead class="bg-canvas/60 text-xs whitespace-nowrap text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-2 text-left font-semibold">CRA</th>
                                <th scope="col" class="px-2 py-2 text-right font-semibold">Calls</th>
                                <th scope="col" class="px-2 py-2 text-right font-semibold">Incoming</th>
                                <th scope="col" class="px-2 py-2 text-right font-semibold">Outgoing</th>
                                <th scope="col" class="px-2 py-2 text-right font-semibold">No match</th>
                                <th scope="col" class="px-2 py-2 text-right font-semibold">Talk time</th>
                                <th scope="col" class="w-1/3 px-4 py-2 text-left font-semibold">AHT</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($rows as $row)
                                <tr>
                                    <th scope="row" class="px-4 py-2 text-left font-medium whitespace-nowrap">
                                        <a href="{{ request()->fullUrlWithQuery(['cra' => $row['cra']->id, 'page' => null]) }}" class="flex items-center gap-2 hover:text-brand-600">
                                            <x-avatar :user="$row['cra']" class="size-7" />{{ $row['cra']->displayName() }}
                                        </a>
                                    </th>
                                    <td @class(['px-2 py-2 text-right tabular-nums', 'font-semibold' => $row['calls'], 'text-muted/60' => ! $row['calls']])>{{ number_format($row['calls']) }}</td>
                                    <td class="px-2 py-2 text-right text-muted tabular-nums">{{ number_format($row['incoming']) }}</td>
                                    <td class="px-2 py-2 text-right text-muted tabular-nums">{{ number_format($row['outgoing']) }}</td>
                                    <td @class(['px-2 py-2 text-right tabular-nums', 'font-semibold text-[#8a6008]' => $row['unmatched'], 'text-muted/60' => ! $row['unmatched']])>{{ number_format($row['unmatched']) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ CallRecording::formatDuration($row['talk']) }}</td>
                                    <td class="px-4 py-2">
                                        <div class="flex items-center gap-2">
                                            <span class="w-14 font-semibold tabular-nums">{{ CallRecording::formatDuration($row['aht']) }}</span>
                                            @if ($row['aht'])
                                                <span aria-hidden="true" class="h-2 rounded-full bg-[#0e8f7c]" style="width: {{ round($row['aht'] / $longestAht * 100) }}%"></span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endif

        <x-panel title="Calls" icon="table" bodyClass="p-0" :tinted="false">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm tabular-nums">{{ number_format($calls->total()) }}</span>
            </x-slot:badges>

            @if ($calls->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">No calls uploaded for these dates.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[56.25rem] text-sm">
                        <thead class="bg-canvas/60 text-xs whitespace-nowrap text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-2 text-left font-semibold">Time</th>
                                @if ($canViewAll && ! $craId)
                                    <th scope="col" class="px-2 py-2 text-left font-semibold">CRA</th>
                                @endif
                                <th scope="col" class="px-2 py-2 text-left font-semibold">Customer</th>
                                <th scope="col" class="px-2 py-2 text-left font-semibold">Number</th>
                                <th scope="col" class="px-2 py-2 text-left font-semibold">Call</th>
                                <th scope="col" class="px-2 py-2 text-right font-semibold">Duration</th>
                                <th scope="col" class="px-4 py-2 text-left font-semibold">Recording</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($calls as $call)
                                <tr>
                                    <td class="px-4 py-2 whitespace-nowrap tabular-nums">{{ $call->called_at->timezone($tz)->format('M j, g:i A') }}</td>
                                    @if ($canViewAll && ! $craId)
                                        <td class="px-2 py-2 whitespace-nowrap">{{ $call->user->displayName() }}</td>
                                    @endif
                                    <td class="px-2 py-2">
                                        @if ($call->lead)
                                            <span class="font-medium">{{ $call->lead->customer_name }}</span>
                                            <span class="block text-xs text-muted">{{ $call->lead->product_name }} · {{ $call->lead->order_id }}</span>
                                        @else
                                            <span class="rounded-full bg-[#fdecc0] px-2 py-0.5 text-xs font-semibold text-[#8a6008]">No matching lead</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2 whitespace-nowrap tabular-nums">{{ $call->phone_number }}</td>
                                    <td class="px-2 py-2 whitespace-nowrap text-muted">{{ CallRecording::DIRECTIONS[$call->direction] ?? '—' }}</td>
                                    <td class="px-2 py-2 text-right font-semibold tabular-nums">{{ CallRecording::formatDuration($call->duration_seconds) }}</td>
                                    <td class="px-4 py-2">
                                        {{-- Loads only when played: the audio comes from Google Drive through the app --}}
                                        <audio controls preload="none" class="h-9 w-64" src="{{ route('call-recordings.audio', $call) }}" aria-label="Recording of the call with {{ $call->lead?->customer_name ?? $call->phone_number }}"></audio>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-line px-4 py-3">{{ $calls->links() }}</div>
            @endif
        </x-panel>
    </div>
</x-layouts.app>
