<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <div class="min-h-screen flex">
        @auth
            <aside class="hidden md:fixed md:inset-y-0 md:left-0 md:z-30 md:flex md:w-56 md:flex-col md:border-r md:border-slate-800 md:bg-slate-900">
                <a href="{{ route('deck.index') }}" class="flex items-center gap-3 border-b border-white/10 px-5 py-4">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-400 text-sm font-bold text-slate-900">
                        CRD
                    </span>
                    <span class="flex flex-col leading-tight">
                        <span class="text-sm font-semibold tracking-wide text-white">Performance Deck</span>
                        <span class="text-[11px] text-slate-400">Customer Retention</span>
                    </span>
                </a>
                <nav class="flex flex-1 flex-col gap-1 p-3">
                    @if (auth()->user()->is_admin)
                        <a href="{{ route('admin.emails.index') }}"
                            class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('admin.*') ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            Admin
                        </a>
                    @endif
                    <a href="{{ route('deck.index') }}"
                        class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('deck.index') ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                        CRD Dashboard
                    </a>
                    <a href="{{ route('pancake.index') }}"
                        class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('pancake.*') ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        CRA Performance
                    </a>
                    <a href="{{ route('customers.index') }}"
                        class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('customers.*') ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Customers
                    </a>
                    <a href="{{ route('segmentation.index') }}"
                        class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('segmentation.*') ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        Segmentation Report
                    </a>
                </nav>

                <div class="border-t border-white/10 p-3">
                    <div class="flex items-center gap-2 px-1 py-1.5">
                        @if (auth()->user()->avatar)
                            <img src="{{ auth()->user()->avatar }}" alt="" class="h-7 w-7 shrink-0 rounded-full ring-2 ring-white/10">
                        @else
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-700 text-xs font-semibold text-white">
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                            </span>
                        @endif
                        <span class="truncate text-sm text-slate-300">{{ auth()->user()->name }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                            class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-white/5 hover:text-white">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                                <polyline points="16 17 21 12 16 7"/>
                                <line x1="21" y1="12" x2="9" y2="12"/>
                            </svg>
                            Sign out
                        </button>
                    </form>
                </div>
            </aside>
        @endauth

        <div class="flex flex-1 flex-col min-w-0 @auth md:ml-56 @endauth">
            <header class="border-b border-slate-800 bg-slate-900 text-white md:hidden">
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
                            <button type="button" id="drawer-toggle" aria-label="Open menu"
                                class="rounded-md p-2 text-slate-300 transition hover:bg-white/5 hover:text-white">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="4" y1="6" x2="20" y2="6"/>
                                    <line x1="4" y1="12" x2="20" y2="12"/>
                                    <line x1="4" y1="18" x2="20" y2="18"/>
                                </svg>
                            </button>
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
    </div>

    @auth
    <div id="drawer-backdrop" class="fixed inset-0 z-40 hidden bg-slate-900/50"></div>

    <aside id="drawer" class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-white shadow-xl transition-transform duration-200 md:hidden">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <span class="text-sm font-semibold text-slate-900">Menu</span>
            <button type="button" id="drawer-close" aria-label="Close menu" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <nav class="flex flex-1 flex-col gap-1 p-3">
            @if (auth()->user()->is_admin)
                <a href="{{ route('admin.emails.index') }}"
                    class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('admin.*') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Admin
                </a>
            @endif
            <a href="{{ route('deck.index') }}"
                class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('deck.index') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                CRD Dashboard
            </a>
            <a href="{{ route('pancake.index') }}"
                class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('pancake.*') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                CRA Performance
            </a>
            <a href="{{ route('customers.index') }}"
                class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('customers.*') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Customers
            </a>
            <a href="{{ route('segmentation.index') }}"
                class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('segmentation.*') ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                Segmentation Report
            </a>
        </nav>

        <div class="border-t border-slate-200 p-3">
            <div class="flex items-center gap-2 px-1 py-1.5">
                @if (auth()->user()->avatar)
                    <img src="{{ auth()->user()->avatar }}" alt="" class="h-7 w-7 shrink-0 rounded-full ring-2 ring-slate-200">
                @else
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-700 text-xs font-semibold text-white">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </span>
                @endif
                <span class="truncate text-sm text-slate-600">{{ auth()->user()->name }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                    class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    Sign out
                </button>
            </form>
        </div>
    </aside>

    <script>
        (function () {
            const toggle = document.getElementById('drawer-toggle');
            const close = document.getElementById('drawer-close');
            const drawer = document.getElementById('drawer');
            const backdrop = document.getElementById('drawer-backdrop');

            const open = () => {
                drawer.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
            };
            const hide = () => {
                drawer.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
            };

            toggle?.addEventListener('click', open);
            close?.addEventListener('click', hide);
            backdrop?.addEventListener('click', hide);
        })();
    </script>

    <x-cra-cohort-prompt />
@endauth
</body>
</html>
