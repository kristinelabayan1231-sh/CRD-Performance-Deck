@extends('layouts.app')

@section('title', 'Admin — ' . config('app.name'))

@section('content')
    @include('admin.partials.nav')
    <div class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight text-slate-900">CRAs</h1>
        <p class="mt-1 text-sm text-slate-500">Manage Customer Retention Associates and their weekly customer-creation cohorts, per PC.</p>
    </div>

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

    <div class="mb-8 rounded-xl bg-white p-5 shadow-sm">
        <div class="flex items-center gap-2">
            <h2 class="text-sm font-semibold text-slate-900">This Week's Excluded Conversation Tag</h2>
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">{{ \App\Support\WeekBlocks::label(now()) }}</span>
        </div>
        <p class="mt-1 text-xs text-slate-400">Pancake creates a new tag every 7-day block for CRAs to apply — set its name here so inquiry syncing excludes conversations carrying it.</p>

        @if ($currentWeeklyTag)
            <p class="mt-2 text-xs text-slate-500">Current tag: <span class="font-medium text-slate-900">{{ $currentWeeklyTag->tag_name }}</span></p>
        @endif

        <form method="POST" action="{{ route('admin.weekly-conversation-tags.store') }}" class="mt-3 flex flex-wrap items-end gap-3">
            @csrf
            <input type="hidden" name="week_start" value="{{ now()->toDateString() }}">
            <div class="flex-1 min-w-[200px]">
                <label for="tag_name" class="block text-xs font-medium text-slate-500">Tag name</label>
                <input type="text" id="tag_name" name="tag_name" required placeholder="e.g. CRD WK28"
                    value="{{ $currentWeeklyTag->tag_name ?? '' }}"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
            </div>
            <button type="submit"
                class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                Save
            </button>
        </form>

        @if ($weeklyTags->isNotEmpty())
            <details class="group mt-3">
                <summary class="flex w-fit cursor-pointer list-none items-center gap-1 text-xs font-medium text-teal-600 hover:text-teal-800">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="transition-transform group-open:rotate-90"><polyline points="9 18 15 12 9 6"/></svg>
                    History
                </summary>
                <table class="mt-2 w-full text-sm">
                    <tbody>
                        @foreach ($weeklyTags as $wt)
                            <tr class="border-b border-slate-50 last:border-0">
                                <td class="px-3 py-1.5 text-slate-500">{{ \App\Support\WeekBlocks::label(\Illuminate\Support\Carbon::parse($wt->week_start)) }}</td>
                                <td class="px-3 py-1.5 text-slate-700">{{ $wt->tag_name }}</td>
                                <td class="px-3 py-1.5 text-right">
                                    <form method="POST" action="{{ route('admin.weekly-conversation-tags.destroy', $wt) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-medium text-red-500 hover:text-red-700">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.cras.store') }}"
        class="mb-8 flex flex-wrap items-end gap-4 rounded-xl bg-white p-5 shadow-sm">
        @csrf
        <div class="flex-1 min-w-[200px]">
            <label for="name" class="block text-xs font-medium text-slate-500">CRA Name</label>
            <input type="text" id="name" name="name" required placeholder="Regina" value="{{ old('name') }}"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
        </div>
        <div class="flex-1 min-w-[240px]">
            <label for="email" class="block text-xs font-medium text-slate-500">Gmail (for their own login)</label>
            <input type="email" id="email" name="email" placeholder="regina@gmail.com" value="{{ old('email') }}"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
        </div>
        <button type="submit"
            class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Add CRA
        </button>
    </form>

    <div class="space-y-4">
        @forelse ($cras as $cra)
            @php
                $thisWeekAssignments = $cra->assignmentsForWeek(now())->keyBy('pc_id');
                $historyWeeks = $cra->pcAssignments->sortByDesc('week_start')->groupBy('week_start');
            @endphp
            <div class="rounded-xl bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">{{ $cra->name }}</h2>
                        @if ($cra->email)
                            <p class="text-xs text-slate-400">{{ $cra->email }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs {{ $thisWeekAssignments->count() < $pcs->count() ? 'font-medium text-amber-600' : 'text-slate-400' }}">
                            {{ $thisWeekAssignments->count() }}/{{ $pcs->count() }} PCs set this week
                        </span>
                        <form method="POST" action="{{ route('admin.cras.destroy', $cra) }}"
                            onsubmit="return confirm('Remove {{ $cra->name }}? This also removes their cohort history.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-800">
                                Remove CRA
                            </button>
                        </form>
                    </div>
                </div>

                <details class="group mt-3">
                    <summary class="flex w-fit cursor-pointer list-none items-center gap-1 text-xs font-medium text-teal-600 hover:text-teal-800">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="transition-transform group-open:rotate-90"><polyline points="9 18 15 12 9 6"/></svg>
                        Manage weekly cohorts
                    </summary>

                    @if ($historyWeeks->isNotEmpty())
                        <div class="mt-3 space-y-3">
                            @foreach ($historyWeeks as $weekStart => $rows)
                                <div class="overflow-hidden rounded-lg border border-slate-100">
                                    <div class="border-b border-slate-100 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-600">
                                        Week of {{ $rows->first()->weekLabel() }}
                                    </div>
                                    <table class="w-full text-sm">
                                        <tbody>
                                            @foreach ($rows->sortBy(fn ($r) => $r->pc->label) as $assignment)
                                                <tr class="border-b border-slate-50 last:border-0">
                                                    <td class="px-3 py-1.5 text-slate-700">{{ $assignment->pc->label }}</td>
                                                    <td class="px-3 py-1.5 text-slate-500">{{ $assignment->pc->facebookPage?->page_name ?? '—' }}</td>
                                                    <td class="px-3 py-1.5 text-slate-500">{{ $assignment->cohortLabel() }}</td>
                                                    <td class="px-3 py-1.5 text-right">
                                                        <form method="POST" action="{{ route('admin.cra-pc-assignments.destroy', $assignment) }}">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="text-xs font-medium text-red-500 hover:text-red-700">Remove</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-4 rounded-lg bg-slate-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Set cohorts</p>
                        <p class="mt-1 text-xs text-slate-400">Defaults to this week; every PC is already listed — no need to pick which ones.</p>
                        <div class="mt-3">
                            <x-weekly-cohort-form
                                :action="route('admin.cra-pc-assignments.store')"
                                :cra-id="$cra->id"
                                :week-start="now()->toDateString()"
                                :pcs="$pcs"
                                :existing="$thisWeekAssignments"
                                submit-label="Save cohorts"
                            />
                        </div>
                    </div>
                </details>
            </div>
        @empty
            <div class="rounded-xl bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
                No CRAs added yet.
            </div>
        @endforelse
    </div>
@endsection
