@props(['title' => 'Dashboard'])

@php($user = auth()->user())

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · CRD Performance Deck</title>
    <x-favicons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas font-sans text-ink antialiased">
    <div class="flex min-h-screen">
        {{-- Sidebar --}}
        <aside id="sidebar" class="fixed inset-y-0 left-0 z-30 w-64 -translate-x-full border-r border-line bg-white transition-transform lg:static lg:translate-x-0">
            <a href="{{ route('dashboard') }}" class="flex h-16 items-center gap-3 px-6">
                <x-crd-logo class="size-9" />
                <span class="bg-gradient-to-r from-brand-500 to-sky bg-clip-text text-lg font-bold text-transparent">CRD Deck</span>
            </a>

            <div class="flex items-center gap-3 px-6 py-5">
                <x-avatar :user="$user" class="size-11" />
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $user->displayName() }}</p>
                    <p class="text-xs text-muted">{{ $user->roleLabel() }}</p>
                </div>
            </div>

            <nav class="px-3 pb-6 text-sm">
                <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="home">Dashboard</x-nav-link>
                @can('segmentation.view')
                    <x-nav-link :href="route('segmentation.index')" :active="request()->routeIs('segmentation.*')" icon="chart">Segmentation Tracker</x-nav-link>
                @endcan
                @can('productivity.view')
                    <x-nav-link :href="route('productivity.index')" :active="request()->routeIs('productivity.*')" icon="trend">Segmentation Productivity</x-nav-link>
                @endcan
                @can('conversion.view')
                    <x-nav-link :href="route('conversion.index')" :active="request()->routeIs('conversion.*')" icon="funnel">Conversion Breakdown</x-nav-link>
                @endcan
                @can('user_access.view')
                    <x-nav-link :href="route('user-access.index')" :active="request()->routeIs('user-access.*', 'roles.*')" icon="users">User Access</x-nav-link>
                @endcan
                @canany(['product_consumption.view', 'pancake_pages.manage', 'sales_goals.manage', 'connections.check'])
                    <x-nav-link :href="route('settings.index')" :active="request()->routeIs('settings.*')" icon="settings">Settings</x-nav-link>
                @endcanany
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- Top bar --}}
            <header class="flex h-16 items-center justify-between gap-4 border-b border-line bg-white px-4 sm:px-6">
                <button type="button" class="rounded-md p-2 text-muted hover:bg-brand-50 lg:hidden" aria-controls="sidebar" aria-label="Toggle menu"
                        onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full')">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="hidden lg:block"></div>

                <div class="flex items-center gap-3">
                    <x-avatar :user="$user" class="size-8" />
                    <span class="hidden text-sm font-medium sm:inline">{{ $user->displayName() }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-md p-2 text-muted hover:bg-brand-50 hover:text-brand-600" title="Sign out" aria-label="Sign out">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v9m6.4-5.4a9 9 0 1 1-12.8 0"/></svg>
                        </button>
                    </form>
                </div>
            </header>

            <main class="flex-1 p-4 sm:p-8">
                @if ($workingDate = \App\Support\WorkingDate::get())
                    <div role="note" class="mb-4 flex flex-wrap items-center gap-x-2 rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-2 text-sm text-[#473821]">
                        <span class="font-semibold">Working date: {{ $workingDate->format('M j, Y') }}</span>
                        <span>· The app shows this day as today (real date {{ \App\Support\WorkingDate::realToday()->format('M j') }}).</span>
                        @can('sales_goals.manage')
                            <a href="{{ route('settings.working-date.index') }}" class="font-semibold underline">Change</a>
                        @endcan
                    </div>
                @endif

                @if (session('status'))
                    <div role="status" class="mb-6 rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">
                        {{ session('status') }}
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
