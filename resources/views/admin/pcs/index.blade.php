@extends('layouts.app')

@section('title', 'Admin — ' . config('app.name'))

@section('content')
    @include('admin.partials.nav')
    <div class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight text-slate-900">PCs</h1>
        <p class="mt-1 text-sm text-slate-500">Register your PC workstations once, then assign (or change) the Facebook page each one runs.</p>
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

    {{-- Add a PC: just its label --}}
    <form method="POST" action="{{ route('admin.pcs.store') }}"
        class="mb-8 flex flex-wrap items-end gap-4 rounded-xl bg-white p-5 shadow-sm">
        @csrf
        <div class="flex-1 min-w-[200px]">
            <label for="label" class="block text-xs font-medium text-slate-500">PC Label</label>
            <input type="text" id="label" name="label" required placeholder="PC 9 - ROY" value="{{ old('label') }}"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
        </div>
        <button type="submit"
            class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Add PC
        </button>
    </form>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-2 font-medium">PC</th>
                    <th class="px-5 py-2 font-medium">Facebook Page</th>
                    <th class="px-5 py-2 font-medium">Pancake Account</th>
                    <th class="px-5 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pcs as $pc)
                    <tr class="border-b border-slate-100 last:border-0 {{ $assigningPc && $assigningPc->id === $pc->id ? 'bg-teal-50/50' : '' }}">
                        <td class="px-5 py-3 font-medium text-slate-700">{{ $pc->label }}</td>
                        <td class="px-5 py-3 text-slate-500">
                            {{ $pc->facebookPage?->page_name ?? $pc->facebookPage?->page_id ?? '—' }}
                        </td>
                        <td class="px-5 py-3 text-slate-500">{{ $pc->pancake_user_name ?? $pc->pancake_user_id ?? '—' }}</td>
                        <td class="px-5 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('admin.pcs.index', ['pc_id' => $pc->id]) }}"
                                    class="text-xs font-medium text-teal-600 hover:text-teal-800">
                                    {{ $pc->facebook_page_id ? 'Change page' : 'Assign page' }}
                                </a>
                                <form method="POST" action="{{ route('admin.pcs.destroy', $pc) }}"
                                    onsubmit="return confirm('Remove {{ $pc->label }}? This also removes its assignments and synced stats.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-800">
                                        Remove
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-6 text-center text-slate-400">No PCs registered yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Assign/change the page on the selected PC --}}
    @if ($assigningPc)
        <div class="mt-6 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-900">Assign page to {{ $assigningPc->label }}</h2>

            <form method="GET" action="{{ route('admin.pcs.index') }}" class="mt-3 flex flex-wrap items-end gap-4">
                <input type="hidden" name="pc_id" value="{{ $assigningPc->id }}">
                <div>
                    <label for="page_id" class="block text-xs font-medium text-slate-500">Facebook Page</label>
                    <select id="page_id" name="page_id" onchange="this.form.submit()"
                        class="mt-1 w-64 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                        <option value="">Select page to load accounts&hellip;</option>
                        @foreach ($pages as $page)
                            <option value="{{ $page->id }}" @selected($selectedPage && $selectedPage->id === $page->id)>
                                {{ $page->page_name ?? $page->page_id }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </form>

            @if ($accountsError)
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {{ $accountsError }}
                </div>
            @elseif ($selectedPage)
                <form method="POST" action="{{ route('admin.pcs.assign-page', $assigningPc) }}" class="mt-4 flex flex-wrap items-end gap-4 border-t border-slate-100 pt-4">
                    @csrf
                    <input type="hidden" name="facebook_page_id" value="{{ $selectedPage->id }}">
                    <div>
                        <label for="account" class="block text-xs font-medium text-slate-500">Pancake Account</label>
                        <select id="account" name="account" required
                            class="mt-1 w-64 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                            <option value="">Select account&hellip;</option>
                            @foreach ($accountOptions as $account)
                                <option value="{{ $account['id'] }}::{{ $account['name'] }}">{{ $account['name'] }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-slate-400">Accounts active on {{ $selectedPage->page_name }} in the last 30 days.</p>
                    </div>
                    <button type="submit"
                        class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                        Save
                    </button>
                </form>
            @endif
        </div>
    @endif
@endsection
