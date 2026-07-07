@extends('layouts.app')

@section('title', 'Sign in — ' . config('app.name'))

@section('content')
    <div class="flex flex-1 items-center justify-center">
        <div class="w-full max-w-sm">
            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-teal-400 text-base font-bold text-slate-900">
                    CRD
                </span>
                <h1 class="mt-4 text-lg font-semibold text-slate-900">Performance Deck</h1>
                <p class="mt-1 text-sm text-slate-500">Customer Retention Department portal.<br>Sign in with your Google account to continue.</p>

                @if ($errors->any())
                    <div class="mt-5 rounded-lg border border-red-200 bg-red-50 p-3 text-left text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('auth.google.redirect') }}">
                    @csrf
                    <button type="submit"
                        class="mt-6 flex w-full items-center justify-center gap-3 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 hover:shadow">
                        <svg width="18" height="18" viewBox="0 0 24 24" class="shrink-0">
                            <path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47a5.53 5.53 0 0 1-2.4 3.63v3.02h3.88c2.27-2.09 3.54-5.17 3.54-8.89Z"/>
                            <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.02c-1.08.72-2.45 1.15-4.05 1.15-3.11 0-5.75-2.1-6.69-4.93H1.3v3.11A12 12 0 0 0 12 24Z"/>
                            <path fill="#FBBC05" d="M5.31 14.29a7.2 7.2 0 0 1 0-4.58V6.6H1.3a12 12 0 0 0 0 10.8l4.01-3.11Z"/>
                            <path fill="#EA4335" d="M12 4.75c1.76 0 3.35.6 4.6 1.8l3.44-3.44C17.94 1.19 15.24 0 12 0A12 12 0 0 0 1.3 6.6l4.01 3.11C6.25 6.88 8.89 4.75 12 4.75Z"/>
                        </svg>
                        Sign in with Google
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-xs text-slate-400">
                Access is limited to approved CRD email addresses.
            </p>
        </div>
    </div>
@endsection
