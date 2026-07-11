@extends('layouts.app')

@section('title', 'Admin — ' . config('app.name'))

@section('content')
    @include('admin.partials.nav')
    <div class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight text-slate-900">Facebook Pages</h1>
        <p class="mt-1 text-sm text-slate-500">Manage the Facebook pages and access tokens used to pull data from Pancake.</p>
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
        <h2 class="text-sm font-semibold text-slate-900">POS Order Status Credentials</h2>
        <p class="mt-1 text-xs text-slate-400">Separate from the page access tokens above — one Pancake POS shop covers every page and is used only to check whether a conversation's order reached "Delivered".</p>

        @if ($posCredential)
            <p class="mt-2 text-xs text-slate-500">Current shop: <span class="font-medium text-slate-900">{{ $posCredential->shop_id }}</span> · key ••••{{ substr($posCredential->api_key, -4) }}</p>
        @endif

        <form method="POST" action="{{ route('admin.pos-credentials.store') }}" class="mt-3 flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-[160px]">
                <label for="shop_id" class="block text-xs font-medium text-slate-500">Shop ID</label>
                <input type="text" id="shop_id" name="shop_id" required placeholder="e.g. 12345678" value="{{ old('shop_id') }}"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
            </div>
            <div class="flex-1 min-w-[260px]">
                <label for="api_key" class="block text-xs font-medium text-slate-500">API Key</label>
                <input type="text" id="api_key" name="api_key" required placeholder="e.g. 32-character API key"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
            </div>
            <button type="submit"
                class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                Save
            </button>
        </form>

        @if ($posCredential)
            <form method="POST" action="{{ route('admin.pos-credentials.destroy', $posCredential) }}" class="mt-2">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-xs font-medium text-red-500 hover:text-red-700">Remove POS credentials</button>
            </form>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.facebook-pages.store') }}"
        class="mb-8 flex flex-wrap items-end gap-4 rounded-xl bg-white p-5 shadow-sm">
        @csrf
        <div class="flex-1 min-w-[200px]">
            <label for="page_name" class="block text-xs font-medium text-slate-500">Page Name</label>
            <input type="text" id="page_name" name="page_name" required placeholder="My Business Page" value="{{ old('page_name') }}"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
        </div>
        <div class="flex-1 min-w-[200px]">
            <label for="page_id" class="block text-xs font-medium text-slate-500">Page ID</label>
            <input type="text" id="page_id" name="page_id" required placeholder="106284xxxxxxxxx" value="{{ old('page_id') }}"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
        </div>
        <div class="flex-1 min-w-[260px]">
            <label for="access_token" class="block text-xs font-medium text-slate-500">Access Token</label>
            <input type="text" id="access_token" name="access_token" required placeholder="eyJhbGciOi..."
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
        </div>
        <button type="submit"
            class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Add
        </button>
    </form>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-2 font-medium">Page Name</th>
                    <th class="px-5 py-2 font-medium">Page ID</th>
                    <th class="px-5 py-2 font-medium">Access Token</th>
                    <th class="px-5 py-2 font-medium">Added</th>
                    <th class="px-5 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pages as $page)
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="px-5 py-3 font-medium text-slate-700">{{ $page->page_name ?? '—' }}</td>
                        <td class="px-5 py-3 text-slate-500">{{ $page->page_id }}</td>
                        <td class="px-5 py-3 font-mono text-slate-400">••••{{ substr($page->access_token, -4) }}</td>
                        <td class="px-5 py-3 text-slate-500">{{ $page->created_at->format('M j, Y') }}</td>
                        <td class="px-5 py-3 text-right">
                            <form method="POST" action="{{ route('admin.facebook-pages.destroy', $page) }}"
                                onsubmit="return confirm('Remove {{ $page->page_name ?? $page->page_id }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-800">
                                    Remove
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-6 text-center text-slate-400">No Facebook pages added yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection