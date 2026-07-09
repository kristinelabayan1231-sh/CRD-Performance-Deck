@extends('layouts.app')

@section('title', 'CRA Performance — ' . config('app.name'))

@section('content')
    <x-page-header title="CRA Performance" subtitle="Engagement and conversion data, sourced from Pancake per Facebook page and CRA.">
        @if ($pages->isNotEmpty())
            <x-slot:actions>
                @if ($cras->isNotEmpty())
                    <div class="inline-flex rounded-lg bg-white p-1 shadow-sm">
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

                @if ($view === 'page')
                    <form method="GET" action="{{ route('pancake.index') }}" class="flex flex-wrap items-end gap-4">
                        <input type="hidden" name="page" value="{{ $activePage->page_id }}">
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
                @endif
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($pages->isEmpty())
        <div class="rounded-xl bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
            No Facebook pages configured yet. Add one under
            <a href="{{ route('admin.facebook-pages.index') }}" class="font-medium text-teal-600 hover:underline">Admin &rarr; Facebook Pages</a>.
        </div>
    @else
        <div class="mb-10 rounded-xl bg-slate-50/70 p-5">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold tracking-tight text-slate-900">Overview</h2>

                @if ($view === 'page')
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
                @else
                    <form method="GET" action="{{ route('pancake.index') }}" class="flex items-center gap-2">
                        <input type="hidden" name="view" value="cra">
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
                @endif
            </div>

            @if ($view === 'cra' && $activeCra && $activeCra->assignments->isNotEmpty())
                @php
                    $uniqueMonths = $activeCra->assignments
                        ->unique(fn ($assignment) => "{$assignment->facebook_page_id}:{$assignment->year}:{$assignment->month}");
                @endphp
                <p class="mb-4 text-xs text-slate-500">
                    Includes full month:
                    @foreach ($uniqueMonths as $assignment)
                        <span class="font-medium text-slate-700">{{ $assignment->facebookPage->page_name ?? $assignment->facebookPage->page_id }} — {{ $assignment->monthLabel() }}</span>{{ !$loop->last ? ', ' : '' }}
                    @endforeach
                </p>
            @endif

            @if ($error)
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {{ $error }}
                </div>
            @elseif ($view === 'page' && $engagement)
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
            @elseif ($view === 'cra' && $dailyRows)
                <div class="overflow-hidden rounded-xl bg-white">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                <th class="px-5 py-2 font-medium">Date</th>
                                <th class="px-5 py-2 font-medium text-right"># of Inquiries</th>
                                <th class="px-5 py-2 font-medium text-right">Total Engagement</th>
                                <th class="px-5 py-2 font-medium text-right"># of Orders</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($dailyRows as $row)
                                <tr class="border-b border-slate-100 last:border-0">
                                    <td class="px-5 py-3 text-slate-700">{{ $row['date']->format('D, M j, Y') }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ number_format($row['inquiries']) }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ number_format($row['total_engagement']) }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ number_format($row['orders']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-6 text-center text-slate-400">No data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
@endsection
