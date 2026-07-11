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
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="px-5 py-3 font-medium text-slate-700">{{ $pc->label }}</td>
                        <td class="px-5 py-3 text-slate-500">
                            {{ $pc->facebookPage?->page_name ?? $pc->facebookPage?->page_id ?? '—' }}
                        </td>
                        <td class="px-5 py-3 text-slate-500">{{ $pc->pancake_user_name ?? $pc->pancake_user_id ?? '—' }}</td>
                        <td class="px-5 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <button type="button"
                                    class="js-assign-page text-xs font-medium text-teal-600 hover:text-teal-800"
                                    data-assign-url="{{ route('admin.pcs.assign-page', $pc) }}"
                                    data-pc-label="{{ $pc->label }}"
                                    data-current-page-id="{{ $pc->facebook_page_id }}"
                                    data-current-account-id="{{ $pc->pancake_user_id }}">
                                    {{ $pc->facebook_page_id ? 'Change page' : 'Assign page' }}
                                </button>
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

    {{-- Assign/change page modal — shared across rows, populated via JS so
         nothing requires a full page reload or scrolling to a freshly
         revealed section. --}}
    <div id="assign-page-backdrop" class="fixed inset-0 z-[60] hidden bg-slate-900/50"></div>
    <div id="assign-page-modal" class="fixed inset-0 z-[61] hidden items-center justify-center p-4">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-base font-semibold text-slate-900">Assign page — <span id="assign-page-pc-label"></span></h2>
                <button type="button" id="assign-page-close" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <form id="assign-page-form" method="POST" action="" class="mt-5 space-y-4">
                @csrf
                <div>
                    <label for="assign_page_id" class="block text-xs font-medium text-slate-500">Facebook Page</label>
                    <select id="assign_page_id" name="facebook_page_id" required
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                        <option value="">Select page&hellip;</option>
                        @foreach ($pages as $page)
                            <option value="{{ $page->id }}">{{ $page->page_name ?? $page->page_id }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="assign_account" class="block text-xs font-medium text-slate-500">Pancake Account</label>
                    <select id="assign_account" name="account" required disabled
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                        <option value="">Select a page first&hellip;</option>
                    </select>
                    <p id="assign-account-hint" class="mt-1 text-[11px] text-slate-400"></p>
                </div>
                <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Save
                </button>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const backdrop = document.getElementById('assign-page-backdrop');
            const modal = document.getElementById('assign-page-modal');
            const closeBtn = document.getElementById('assign-page-close');
            const form = document.getElementById('assign-page-form');
            const pcLabel = document.getElementById('assign-page-pc-label');
            const pageSelect = document.getElementById('assign_page_id');
            const accountSelect = document.getElementById('assign_account');
            const accountHint = document.getElementById('assign-account-hint');
            const accountsUrlBase = @json(route('admin.pcs.page-accounts', ['facebookPage' => '__PAGE__']));

            const show = () => { backdrop.classList.remove('hidden'); modal.classList.remove('hidden'); modal.classList.add('flex'); };
            const hide = () => { backdrop.classList.add('hidden'); modal.classList.add('hidden'); modal.classList.remove('flex'); };

            async function loadAccounts(pageId, preselectAccountId) {
                if (!pageId) {
                    accountSelect.disabled = true;
                    accountSelect.innerHTML = '<option value="">Select a page first&hellip;</option>';
                    accountHint.textContent = '';
                    return;
                }

                accountSelect.disabled = true;
                accountSelect.innerHTML = '<option value="">Loading accounts&hellip;</option>';
                accountHint.textContent = '';

                try {
                    const res = await fetch(accountsUrlBase.replace('__PAGE__', pageId));
                    const data = await res.json();

                    if (!res.ok) {
                        accountSelect.innerHTML = '<option value="">' + (data.error || 'Failed to load accounts') + '</option>';
                        return;
                    }

                    accountSelect.innerHTML = '<option value="">Select account&hellip;</option>' +
                        data.accounts.map((a) => `<option value="${a.id}::${a.name}">${a.name}</option>`).join('');

                    if (preselectAccountId) {
                        const match = data.accounts.find((a) => String(a.id) === String(preselectAccountId));
                        if (match) accountSelect.value = `${match.id}::${match.name}`;
                    }

                    accountSelect.disabled = false;
                    accountHint.textContent = 'Accounts active in the last 30 days.';
                } catch (e) {
                    accountSelect.innerHTML = '<option value="">Failed to load accounts</option>';
                }
            }

            document.querySelectorAll('.js-assign-page').forEach((btn) => {
                btn.addEventListener('click', () => {
                    pcLabel.textContent = btn.dataset.pcLabel;
                    form.action = btn.dataset.assignUrl;
                    pageSelect.value = btn.dataset.currentPageId || '';
                    loadAccounts(btn.dataset.currentPageId, btn.dataset.currentAccountId);
                    show();
                });
            });

            pageSelect.addEventListener('change', () => loadAccounts(pageSelect.value, null));
            closeBtn.addEventListener('click', hide);
            backdrop.addEventListener('click', hide);
        })();
    </script>
@endsection
