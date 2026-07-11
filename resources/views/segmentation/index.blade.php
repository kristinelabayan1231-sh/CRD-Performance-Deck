@extends('layouts.app')

@section('title', 'Segmentation Productivity Report — ' . config('app.name'))

@php
    $periodLabels = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'];
    $periodPrefixes = ['day' => '', 'week' => 'Week of ', 'month' => 'Month of '];
@endphp

@section('content')
    <x-page-header title="Segmentation Productivity Report" subtitle="Call volume and pick-up rate per CRA.">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <a href="{{ route('segmentation.index', ['period' => $period, 'date' => $prevDate]) }}"
                    class="rounded-md border border-slate-200 bg-white p-1.5 text-slate-500 hover:bg-slate-50" aria-label="Previous">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </a>
                @if ($period === 'day')
                    <form method="GET" action="{{ route('segmentation.index') }}">
                        <input type="hidden" name="period" value="day">
                        <input type="date" name="date" value="{{ $date->toDateString() }}" max="{{ now()->toDateString() }}" onchange="this.form.submit()"
                            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                    </form>
                @endif
                @unless ($nextIsFuture)
                    <a href="{{ route('segmentation.index', ['period' => $period, 'date' => $nextDate]) }}"
                        class="rounded-md border border-slate-200 bg-white p-1.5 text-slate-500 hover:bg-slate-50" aria-label="Next">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                @endunless
            </div>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="mb-6 rounded-lg border border-teal-200 bg-teal-50 p-4 text-sm text-teal-700">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex rounded-lg bg-white p-1 shadow-sm">
            @foreach ($periodLabels as $key => $label)
                <a href="{{ route('segmentation.index', ['period' => $key, 'date' => $date->toDateString()]) }}"
                    class="rounded-md px-3 py-1 text-xs font-medium transition {{ $period === $key ? 'bg-slate-900 text-white' : 'text-slate-500' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
        <span class="text-sm font-medium text-slate-700">{{ $periodPrefixes[$period] }}{{ $periodLabel }}</span>
    </div>

    {{-- KPI cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <div class="flex items-center gap-2 text-slate-400">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                <span class="text-xs font-medium uppercase tracking-wide">Total Calls</span>
            </div>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($totals['total_calls']) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <div class="flex items-center gap-2 text-slate-400">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <span class="text-xs font-medium uppercase tracking-wide">Answered Calls</span>
            </div>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($totals['answered_calls']) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <div class="flex items-center gap-2 text-slate-400">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                <span class="text-xs font-medium uppercase tracking-wide">Pick Up Rate</span>
            </div>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($totals['pick_up_rate'] * 100, 2) }}%</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <div class="flex items-center gap-2 text-slate-400">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span class="text-xs font-medium uppercase tracking-wide">Reported</span>
            </div>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ $reportedCount }}/{{ $rows->count() }}</p>
        </div>
    </div>

    {{-- Report table --}}
    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-3 font-medium">Team</th>
                    <th class="px-5 py-3 font-medium text-right">Total Calls</th>
                    <th class="px-5 py-3 font-medium text-right">Answered Calls</th>
                    <th class="px-5 py-3 font-medium">Pick Up Rate</th>
                    @if ($period === 'day')
                        <th class="px-5 py-3"></th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-teal-100 text-xs font-semibold text-teal-700">
                                    {{ strtoupper(substr($row['cra']->name, 0, 1)) }}
                                </span>
                                <span class="font-medium text-slate-800">{{ $row['cra']->name }}</span>
                                @if ($period === 'day')
                                    @unless ($row['reported'])
                                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-600">Not reported</span>
                                    @endunless
                                @else
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-medium {{ $row['reported'] ? 'bg-slate-100 text-slate-500' : 'bg-amber-50 text-amber-600' }}">
                                        {{ $row['days_reported'] }} {{ $daysReportedLabel }} reported
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="px-5 py-3 text-right text-slate-700">{{ number_format($row['total_calls']) }}</td>
                        <td class="px-5 py-3 text-right text-slate-700">{{ number_format($row['answered_calls']) }}</td>
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2">
                                <div class="h-2 w-28 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full {{ $row['pick_up_rate'] >= 0.8 ? 'bg-teal-400' : ($row['pick_up_rate'] >= 0.5 ? 'bg-amber-400' : 'bg-red-400') }}"
                                        style="width: {{ round($row['pick_up_rate'] * 100) }}%"></div>
                                </div>
                                <span class="text-xs font-medium text-slate-600">{{ number_format($row['pick_up_rate'] * 100, 2) }}%</span>
                            </div>
                        </td>
                        @if ($period === 'day')
                            @php $canEdit = auth()->user()->is_admin || ($viewerCra && $viewerCra->id === $row['cra']->id); @endphp
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($canEdit)
                                        <button type="button" title="Edit"
                                            class="js-edit-call-stat rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-teal-600"
                                            data-cra-id="{{ $row['cra']->id }}"
                                            data-cra-name="{{ $row['cra']->name }}"
                                            data-date="{{ $date->toDateString() }}"
                                            data-total-calls="{{ $row['total_calls'] }}"
                                            data-answered-calls="{{ $row['answered_calls'] }}">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                        </button>
                                    @endif
                                    @if (auth()->user()->is_admin && $row['stat_id'])
                                        <form method="POST" action="{{ route('admin.cra-call-stats.destroy', $row['stat_id']) }}"
                                            onsubmit="return confirm('Delete {{ $row['cra']->name }}\'s call stats for {{ $date->format('M j, Y') }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" title="Delete" class="rounded-md p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $period === 'day' ? 5 : 4 }}" class="px-5 py-6 text-center text-slate-400">No CRAs added yet.</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot>
                    <tr class="border-t-2 border-slate-900 bg-slate-900 text-white">
                        <td class="px-5 py-3 font-semibold">Total</td>
                        <td class="px-5 py-3 text-right font-semibold">{{ number_format($totals['total_calls']) }}</td>
                        <td class="px-5 py-3 text-right font-semibold">{{ number_format($totals['answered_calls']) }}</td>
                        <td class="px-5 py-3 font-semibold">{{ number_format($totals['pick_up_rate'] * 100, 2) }}%</td>
                        @if ($period === 'day')
                            <td></td>
                        @endif
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($period === 'day' && (auth()->user()->is_admin || $viewerCra))
        <div id="edit-call-stat-backdrop" class="fixed inset-0 z-[60] hidden bg-slate-900/50"></div>
        <div id="edit-call-stat-modal" class="fixed inset-0 z-[61] hidden items-center justify-center p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">Edit call stats</h2>
                        <p id="edit-call-stat-subtitle" class="mt-1 text-sm text-slate-500"></p>
                    </div>
                    <button type="button" id="edit-call-stat-close" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <form id="edit-call-stat-form" method="POST" action="{{ auth()->user()->is_admin ? route('admin.cra-call-stats.store') : route('cra.call-stats.store') }}" class="mt-5 space-y-4">
                    @csrf
                    @if (auth()->user()->is_admin)
                        <input type="hidden" id="edit-call-stat-cra-id" name="cra_id" value="">
                    @endif
                    <input type="hidden" id="edit-call-stat-date" name="date" value="">
                    <div>
                        <label for="edit_total_calls" class="block text-xs font-medium text-slate-500">Total Calls</label>
                        <input type="number" min="0" id="edit_total_calls" name="total_calls" required
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                    </div>
                    <div>
                        <label for="edit_answered_calls" class="block text-xs font-medium text-slate-500">Answered Calls</label>
                        <input type="number" min="0" id="edit_answered_calls" name="answered_calls" required
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                    </div>
                    <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Save changes
                    </button>
                </form>
            </div>
        </div>

        <script>
            (function () {
                const backdrop = document.getElementById('edit-call-stat-backdrop');
                const modal = document.getElementById('edit-call-stat-modal');
                const closeBtn = document.getElementById('edit-call-stat-close');
                const craIdInput = document.getElementById('edit-call-stat-cra-id');
                const dateInput = document.getElementById('edit-call-stat-date');
                const totalInput = document.getElementById('edit_total_calls');
                const answeredInput = document.getElementById('edit_answered_calls');
                const subtitle = document.getElementById('edit-call-stat-subtitle');

                const show = () => { backdrop.classList.remove('hidden'); modal.classList.remove('hidden'); modal.classList.add('flex'); };
                const hide = () => { backdrop.classList.add('hidden'); modal.classList.add('hidden'); modal.classList.remove('flex'); };

                document.querySelectorAll('.js-edit-call-stat').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        if (craIdInput) craIdInput.value = btn.dataset.craId;
                        dateInput.value = btn.dataset.date;
                        totalInput.value = btn.dataset.totalCalls;
                        answeredInput.value = btn.dataset.answeredCalls;
                        subtitle.textContent = btn.dataset.craName + ' — ' + btn.dataset.date;
                        show();
                    });
                });

                closeBtn?.addEventListener('click', hide);
                backdrop?.addEventListener('click', hide);
            })();
        </script>
    @endif

    <x-cra-call-stats-prompt />
@endsection
