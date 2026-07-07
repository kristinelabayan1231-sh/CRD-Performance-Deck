@extends('layouts.app')

@section('title', 'Admin — ' . config('app.name'))

@section('content')
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Access Control</h1>
        <p class="mt-1 text-sm text-slate-500">Manage who can sign in to the Performance Deck with Google.</p>
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

    <form method="POST" action="{{ route('admin.emails.store') }}"
        class="mb-8 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        <div class="flex-1 min-w-[220px]">
            <label for="email" class="block text-xs font-medium text-slate-500">Email address</label>
            <input type="email" id="email" name="email" required placeholder="name@example.com"
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-slate-600">
            <input type="checkbox" name="is_admin" value="1" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
            Grant admin access
        </label>
        <button type="submit"
            class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Add
        </button>
    </form>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-2 font-medium">Email</th>
                    <th class="px-5 py-2 font-medium">Role</th>
                    <th class="px-5 py-2 font-medium">Added</th>
                    <th class="px-5 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($emails as $allowedEmail)
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="px-5 py-3 text-slate-700">{{ $allowedEmail->email }}</td>
                        <td class="px-5 py-3">
                            @if ($allowedEmail->is_admin)
                                <span class="inline-flex items-center rounded-full bg-teal-100 px-2.5 py-0.5 text-xs font-medium text-teal-800">Admin</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Member</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-slate-500">{{ $allowedEmail->created_at->format('M j, Y') }}</td>
                        <td class="px-5 py-3 text-right">
                            <form method="POST" action="{{ route('admin.emails.destroy', $allowedEmail) }}"
                                onsubmit="return confirm('Remove access for {{ $allowedEmail->email }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-800">
                                    Remove
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
