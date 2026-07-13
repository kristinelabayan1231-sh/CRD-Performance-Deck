@extends('layouts.app')

@section('title', 'CRA Performance — ' . config('app.name'))

@section('content')
    <x-page-header title="CRA Performance" subtitle="Engagement and conversion data, sourced from Pancake per Facebook page and CRA.">
        @if ($pages->isNotEmpty())
            <x-slot:actions>
                @if ($cras->isNotEmpty())
                    <div class="inline-flex self-center rounded-lg bg-white p-1 shadow-sm">
                        <a href="{{ route('pancake.index', ['view' => 'page']) }}"
                            class="rounded-md px-3 py-1 text-xs font-medium transition {{ $view === 'page' ? 'bg-slate-900 text-white' : 'text-slate-500' }}">
                            Facebook Page
                        </a>
                        <a href="{{ route('pancake.index', ['view' => 'cra']) }}"
                            class="rounded-md px-3 py-1 text-xs font-medium transition {{ $view === 'cra' ? 'bg-slate-900 text-white' : 'text-slate-500' }}">
                            CRA
                        </a>
                    </div>
                @endif

                <form method="GET" action="{{ route('pancake.index') }}" class="flex flex-wrap items-end gap-4">
                    @if ($view === 'cra')
                        <input type="hidden" name="view" value="cra">
                        @if ($activeCra)
                            <input type="hidden" name="cra" value="{{ $activeCra->id }}">
                        @endif
                    @else
                        <input type="hidden" name="page" value="{{ $activePage->page_id }}">
                    @endif
                    <div>
                        <label for="start_date" class="block text-xs font-medium text-slate-500">Start date</label>
                        <input type="date" id="start_date" name="start_date" value="{{ $startDate }}"
                            class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                    </div>
                    <div>
                        <label for="end_date" class="block text-xs font-medium text-slate-500">End date</label>
                        <input type="date" id="end_date" name="end_date" value="{{ $endDate }}"
                            class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                    </div>
                    <button type="submit"
                        class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-slate-700">
                        Apply
                    </button>
                </form>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($pages->isEmpty())
        <div class="rounded-xl bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
            No Facebook pages configured yet. Add one under
            <a href="{{ route('admin.facebook-pages.index') }}" class="font-medium text-teal-600 hover:underline">Admin &rarr; Facebook Pages</a>.
        </div>
    @elseif ($view === 'page')
        <div class="mb-10 rounded-xl bg-slate-50/70 p-5">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold tracking-tight text-slate-900">Overview</h2>

                <form method="GET" action="{{ route('pancake.index') }}" class="flex items-center gap-2">
                    <input type="hidden" name="start_date" value="{{ $startDate }}">
                    <input type="hidden" name="end_date" value="{{ $endDate }}">
                    <label for="page-select" class="text-xs font-medium text-slate-500">Facebook Page</label>
                    <select id="page-select" name="page" onchange="this.form.submit()"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                        @foreach ($pages as $page)
                            <option value="{{ $page->page_id }}" @selected($activePage && $activePage->id === $page->id)>
                                {{ $page->page_name ?? $page->page_id }}
                            </option>
                        @endforeach
                    </select>
                </form>
            </div>

            @if ($error)
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {{ $error }}
                </div>
            @elseif ($engagement)
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <x-engagement-card
                        title="Customer Engagement"
                        subtitle="Customer engagement via messages and comments"
                        :total="$engagement['total']"
                        new-label="New customers"
                        :new-value="$engagement['new_inbox_customers']"
                        old-label="Old customers"
                        :old-value="$engagement['total'] - $engagement['new_inbox_customers']"
                        :change-percent="$change['total'] ?? null"
                        :compare-range-label="$compareRangeLabel ?? null"
                        :messages="[
                            'label' => 'Messages',
                            'value' => $engagement['inbox'],
                            'change' => $change['inbox'] ?? null,
                            'icon' => 'mail',
                            'iconBg' => 'bg-sky-50 text-sky-600',
                        ]"
                        :comments="[
                            'label' => 'Comments',
                            'value' => $engagement['comment'],
                            'change' => $change['comment'] ?? null,
                            'icon' => 'chat',
                            'iconBg' => 'bg-violet-50 text-violet-600',
                        ]"
                    />
                    <x-gauge-card
                        title="Orders"
                        subtitle="Number of confirmed orders"
                        :total="$engagement['order_count']"
                        new-label="New customers"
                        :new-value="$engagement['order_count'] - $engagement['old_order_count']"
                        old-label="Returning customers"
                        :old-value="$engagement['old_order_count']"
                    />
                </div>
            @endif
        </div>
    @else
        {{-- CRA view: day-by-day conversion breakdown per PC --}}
        <div class="mb-10 rounded-xl bg-slate-50/70 p-5">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold tracking-tight text-slate-900">Conversion Breakdown</h2>

                <form method="GET" action="{{ route('pancake.index') }}" class="flex items-center gap-2">
                    <input type="hidden" name="view" value="cra">
                    <input type="hidden" name="start_date" value="{{ $startDate }}">
                    <input type="hidden" name="end_date" value="{{ $endDate }}">
                    <label for="cra-select" class="text-xs font-medium text-slate-500">CRA</label>
                    <select id="cra-select" name="cra" onchange="this.form.submit()"
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                        @foreach ($cras as $cra)
                            <option value="{{ $cra->id }}" @selected($activeCra && $activeCra->id === $cra->id)>
                                {{ $cra->name }}
                            </option>
                        @endforeach
                    </select>
                </form>
            </div>

            @if (session('status'))
                <div class="mb-4 rounded-lg border border-teal-200 bg-teal-50 p-3 text-sm text-teal-700">
                    {{ session('status') }}
                </div>
            @endif

            @if ($error)
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {{ $error }}
                </div>
            @elseif (empty($dayTables))
                <div class="rounded-xl bg-white p-10 text-center text-sm text-slate-400">
                    No synced data for this range yet. Run <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">php artisan pancake:sync-cra-stats</code> to pull it from Pancake.
                </div>
            @else
                <div class="space-y-6">
                    @foreach ($dayTables as $day)
                        @php
                            $finalization = $day['finalization'] ?? null;
                            $daySyncing = ! $finalization && collect($day['rows'])->contains(fn ($r) => $r['inquiries'] === null);
                        @endphp
                        <div class="overflow-hidden rounded-xl bg-white shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-5 py-2.5">
                                <span class="text-sm font-semibold text-slate-900">{{ $day['date']->format('D, M j, Y') }}</span>
                                <span class="flex items-center gap-3">
                                    @if ($finalization)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-medium text-emerald-700"
                                            title="Finalized {{ $finalization->created_at->format('M j, Y g:i A') }} — hourly syncing skips this day">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            Finalized
                                        </span>
                                        @if ($canFinalize ?? false)
                                            <form method="POST" action="{{ route('cra-day-finalizations.destroy', $finalization) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-[11px] font-medium text-slate-400 hover:text-slate-600">Reopen</button>
                                            </form>
                                        @endif
                                    @else
                                        @if ($daySyncing)
                                            <span class="inline-flex items-center gap-1.5 text-[11px] text-slate-400" title="Inquiries refresh hourly from Pancake">
                                                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-400"></span>
                                                Still syncing from Pancake — refreshes hourly
                                            </span>
                                        @endif
                                        @if ($canFinalize ?? false)
                                            <form method="POST" action="{{ route('cra-day-finalizations.store') }}"
                                                onsubmit="return confirm('Finalize {{ $day['date']->format('M j, Y') }}? Syncing will stop updating this day and its entries will be locked.')">
                                                @csrf
                                                <input type="hidden" name="cra_id" value="{{ $activeCra->id }}">
                                                <input type="hidden" name="date" value="{{ $day['date']->toDateString() }}">
                                                <button type="submit"
                                                    class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-600 transition hover:bg-slate-100">
                                                    Finalize day
                                                </button>
                                            </form>
                                        @endif
                                    @endif
                                </span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                            <th class="px-5 py-2 font-medium">PC</th>
                                            <th class="px-5 py-2 font-medium">Cohort</th>
                                            <th class="px-5 py-2 font-medium text-right">Total Inquiries</th>
                                            <th class="px-5 py-2 font-medium text-right">Total Engagement</th>
                                            <th class="px-5 py-2 font-medium text-right"># of Orders</th>
                                            <th class="px-5 py-2 font-medium text-right">Conversion Rate</th>
                                            <th class="px-5 py-2 font-medium text-right">Amount</th>
                                            <th class="px-5 py-2 font-medium">Tagging / Template</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($day['rows'] as $row)
                                            @php
                                                $assignment = $row['assignment'];
                                                $conversion = ($row['engagement'] ?? 0) > 0 && $row['orders'] !== null
                                                    ? number_format(($row['orders'] / $row['engagement']) * 100, 2) . '%'
                                                    : '—';
                                            @endphp
                                            <tr class="border-b border-slate-100 last:border-0">
                                                <td class="px-5 py-2.5 text-slate-700">
                                                    {{ $assignment->pc->label }}
                                                    <span class="block text-[11px] text-slate-400">{{ $assignment->pc->facebookPage?->page_name ?? '—' }}</span>
                                                </td>
                                                <td class="px-5 py-2.5 text-xs text-slate-500">{{ $assignment->cohortLabel() }}</td>
                                                <td class="px-5 py-2.5 text-right tabular-nums text-slate-700">
                                                    @if ($row['inquiries'] !== null)
                                                        {{ number_format($row['inquiries']) }}
                                                    @elseif ($finalization)
                                                        —
                                                    @else
                                                        <span class="inline-flex items-center gap-1.5 text-xs text-slate-400" title="Inquiries refresh hourly from Pancake">
                                                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-400"></span>
                                                            syncing…
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="px-5 py-2.5 text-right">
                                                    <input form="entry-{{ $assignment->pc_id }}-{{ $day['date']->toDateString() }}"
                                                        type="number" step="1" min="0" name="engagement" value="{{ $row['engagement'] }}" placeholder="—" @disabled($finalization)
                                                        class="w-24 rounded-md border border-slate-200 px-2 py-1 text-right text-xs tabular-nums text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400">
                                                </td>
                                                <td class="px-5 py-2.5 text-right tabular-nums text-slate-700">
                                                    {{ $row['orders'] !== null ? number_format($row['orders']) : '—' }}
                                                </td>
                                                <td class="px-5 py-2.5 text-right tabular-nums text-slate-700">{{ $conversion }}</td>
                                                <td class="px-5 py-2.5 text-right">
                                                    <input form="entry-{{ $assignment->pc_id }}-{{ $day['date']->toDateString() }}"
                                                        type="number" step="0.01" min="0" name="amount" value="{{ $row['amount'] }}" placeholder="0.00" @disabled($finalization)
                                                        class="w-24 rounded-md border border-slate-200 px-2 py-1 text-right text-xs tabular-nums text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400">
                                                </td>
                                                <td class="px-5 py-2.5">
                                                    <form method="POST" action="{{ route('pancake.entry') }}" class="flex items-center gap-1.5"
                                                        id="entry-{{ $assignment->pc_id }}-{{ $day['date']->toDateString() }}">
                                                        @csrf
                                                        <input type="hidden" name="cra_id" value="{{ $activeCra->id }}">
                                                        <input type="hidden" name="pc_id" value="{{ $assignment->pc_id }}">
                                                        <input type="hidden" name="date" value="{{ $day['date']->toDateString() }}">
                                                        <input type="text" name="tagging" value="{{ $row['tagging'] }}" placeholder="e.g. SP, FPY - Sweldo Sale" @disabled($finalization)
                                                            class="w-44 rounded-md border border-slate-200 px-2 py-1 text-xs text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400">
                                                        @unless ($finalization)
                                                            <button type="submit" class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Save engagement, amount & tagging">
                                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                                            </button>
                                                        @endunless
                                                    </form>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-slate-50 font-semibold text-slate-900">
                                            <td class="px-5 py-2" colspan="2">Total</td>
                                            <td class="px-5 py-2 text-right tabular-nums">{{ number_format($day['totals']['inquiries']) }}</td>
                                            <td class="px-5 py-2 text-right tabular-nums">{{ number_format($day['totals']['engagement']) }}</td>
                                            <td class="px-5 py-2 text-right tabular-nums">{{ number_format($day['totals']['orders']) }}</td>
                                            <td class="px-5 py-2 text-right tabular-nums">
                                                {{ $day['totals']['engagement'] > 0 ? number_format(($day['totals']['orders'] / $day['totals']['engagement']) * 100, 2) . '%' : '—' }}
                                            </td>
                                            <td class="px-5 py-2 text-right tabular-nums">₱{{ number_format($day['totals']['amount'], 2) }}</td>
                                            <td class="px-5 py-2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
@endsection
