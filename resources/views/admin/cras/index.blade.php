@extends('layouts.app')

@section('title', 'Admin — ' . config('app.name'))

@section('content')
    @include('admin.partials.nav')
    <div class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight text-slate-900">CRAs</h1>
        <p class="mt-1 text-sm text-slate-500">Manage Customer Retention Associates and the Facebook pages + 7-day periods they own.</p>
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

    <form method="POST" action="{{ route('admin.cras.store') }}"
        class="mb-8 flex flex-wrap items-end gap-4 rounded-xl bg-white p-5 shadow-sm">
        @csrf
        <div class="flex-1 min-w-[200px]">
            <label for="name" class="block text-xs font-medium text-slate-500">CRA Name</label>
            <input type="text" id="name" name="name" required placeholder="Roy" value="{{ old('name') }}"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
        </div>
        <button type="submit"
            class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Add CRA
        </button>
    </form>

    <div class="space-y-4">
        @forelse ($cras as $cra)
            <div class="rounded-xl bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-slate-900">{{ $cra->name }}</h2>
                    <form method="POST" action="{{ route('admin.cras.destroy', $cra) }}"
                        onsubmit="return confirm('Remove {{ $cra->name }}? This also removes their page/month assignments.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-800">
                            Remove CRA
                        </button>
                    </form>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse ($cra->assignments as $assignment)
                        <div class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">
                            <span>{{ $assignment->facebookPage->page_name ?? $assignment->facebookPage->page_id }}</span>
                            <span class="text-slate-400">&middot;</span>
                            <span>{{ $assignment->label() }}</span>
                            <form method="POST" action="{{ route('admin.cra-assignments.destroy', $assignment) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-slate-400 hover:text-red-600" aria-label="Remove assignment">&times;</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-xs text-slate-400">No pages assigned yet.</p>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('admin.cra-assignments.store') }}"
                    class="mt-4 border-t border-slate-100 pt-4">
                    @csrf
                    <input type="hidden" name="cra_id" value="{{ $cra->id }}">

                    <div class="mb-3">
                        <label class="block text-xs font-medium text-slate-500">Facebook Page</label>
                        <select name="facebook_page_id" required
                            class="mt-1 w-64 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                            <option value="">Select page&hellip;</option>
                            @foreach ($pages as $page)
                                <option value="{{ $page->id }}">{{ $page->page_name ?? $page->page_id }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex flex-wrap items-end gap-3 rounded-lg bg-slate-50 p-3">
                            <span class="pb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">From</span>
                            <div>
                                <label class="block text-xs font-medium text-slate-500">Month</label>
                                <select name="from_month" required
                                    class="mt-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                                    @foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $i => $name)
                                        <option value="{{ $i + 1 }}" @selected(now()->month === $i + 1)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500">Year</label>
                                <select name="from_year" required
                                    class="mt-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                                    @foreach (range(now()->year - 1, now()->year + 1) as $year)
                                        <option value="{{ $year }}" @selected(now()->year === $year)>{{ $year }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <span class="pb-2 text-slate-400">&rarr;</span>

                        <div class="flex flex-wrap items-end gap-3 rounded-lg bg-slate-50 p-3">
                            <span class="pb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">To</span>
                            <div>
                                <label class="block text-xs font-medium text-slate-500">Month</label>
                                <select name="to_month" required
                                    class="mt-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                                    @foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $i => $name)
                                        <option value="{{ $i + 1 }}" @selected(now()->month === $i + 1)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500">Year</label>
                                <select name="to_year" required
                                    class="mt-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                                    @foreach (range(now()->year - 1, now()->year + 1) as $year)
                                        <option value="{{ $year }}" @selected(now()->year === $year)>{{ $year }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500">Week</label>
                            <select name="week" required
                                class="mt-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                                <option value="1">Week 1 (days 1&ndash;7)</option>
                                <option value="2">Week 2 (days 8&ndash;14)</option>
                                <option value="3">Week 3 (days 15&ndash;21)</option>
                                <option value="4">Week 4 (days 22&ndash;28)</option>
                                <option value="5">Week 5 (days 29+)</option>
                            </select>
                        </div>

                        <button type="submit"
                            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
                            Assign
                        </button>
                    </div>

                    <p class="mt-2 text-xs text-slate-400">The chosen week is applied to every month from "From" through "To" (e.g. Week 1, Jan&ndash;Mar = the 1st week of January, February, and March). Leave "To" the same as "From" for a single month, like July&nbsp;1&ndash;7.</p>
                </form>
            </div>
        @empty
            <div class="rounded-xl bg-white p-10 text-center text-sm text-slate-400 shadow-sm">
                No CRAs added yet.
            </div>
        @endforelse
    </div>
@endsection
