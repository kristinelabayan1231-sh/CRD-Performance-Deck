<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <div class="min-h-screen flex flex-col">
        <header class="border-b border-slate-800 bg-slate-900 text-white">
            <div class="mx-auto max-w-6xl px-6">
                <div class="flex h-16 items-center justify-between">
                    <a href="{{ route('deck.index') }}" class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-400 text-sm font-bold text-slate-900">
                            CRD
                        </span>
                        <span class="flex flex-col leading-tight">
                            <span class="text-sm font-semibold tracking-wide text-white">Performance Deck</span>
                            <span class="hidden text-[11px] text-slate-400 sm:inline">Customer Retention Department</span>
                        </span>
                    </a>

                    @auth
                        <nav class="flex items-center gap-1">
                            <a href="{{ route('deck.index') }}"
                                class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ request()->routeIs('deck.index') ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                                Deck
                            </a>

                            @if (auth()->user()->is_admin)
                                <a href="{{ route('admin.emails.index') }}"
                                    class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ request()->routeIs('admin.*') ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                                    Admin
                                </a>
                            @endif

                            <div class="ml-4 flex items-center gap-3 border-l border-white/10 pl-4">
                                <div class="flex items-center gap-2">
                                    @if (auth()->user()->avatar)
                                        <img src="{{ auth()->user()->avatar }}" alt="" class="h-7 w-7 rounded-full ring-2 ring-white/10">
                                    @else
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-700 text-xs font-semibold text-white">
                                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                                        </span>
                                    @endif
                                    <span class="hidden text-sm text-slate-300 md:inline">{{ auth()->user()->name }}</span>
                                </div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" title="Sign out"
                                        class="rounded-md p-1.5 text-slate-400 transition hover:bg-white/5 hover:text-white">
                                        <svg class="h-4 w-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                                            <polyline points="16 17 21 12 16 7"/>
                                            <line x1="21" y1="12" x2="9" y2="12"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </nav>
                    @endauth
                </div>
            </div>
        </header>

        <main class="flex-1 flex flex-col">
            <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col px-6 py-10">
                @yield('content')
            </div>
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto max-w-6xl px-6 py-4 text-xs text-slate-400">
                Customer Retention Department &middot; {{ config('app.name') }}
            </div>
        </footer>
    </div>
</body>
</html>
