@props(['title' => 'Dashboard', 'fit' => false])

@php
    $user = auth()->user();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · CRD Performance Deck</title>
    <x-favicons />
    {{-- Light / Dark (top bar switch): set before the page paints, remembered on this browser --}}
    <script>try { document.documentElement.dataset.theme = localStorage.getItem('theme') === 'dark' ? 'dark' : 'light'; } catch (e) { document.documentElement.dataset.theme = 'light'; }</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if (is_file(public_path('build/assets/theme-dark.css')))
        <link rel="stylesheet" href="{{ asset('build/assets/theme-dark.css') }}?v={{ filemtime(public_path('build/assets/theme-dark.css')) }}">
    @endif
</head>
<body class="min-h-screen bg-canvas font-sans text-ink antialiased">
    {{-- fit: on large screens the page fills the window and only its own sections scroll. --}}
    <div @class(['flex min-h-screen', 'lg:h-dvh lg:overflow-hidden' => $fit])>
        {{-- Sidebar --}}
        {{-- Sidebar: Dashboard and Report Summary, then the modules in four groups that open one at a time (the group
             holding this page starts open), then User Access and Settings. --}}
        @php
            $navGroups = collect([
                ['name' => 'Segmentation', 'icon' => 'chart', 'items' => [
                    ['Segmentation Tracker', route('segmentation.index'), request()->routeIs('segmentation.*'), $user->can('segmentation.view')],
                    ['Segmentation Productivity', route('productivity.index'), request()->routeIs('productivity.*'), $user->can('productivity.view')],
                    ['Conversion Breakdown', route('conversion.index'), request()->routeIs('conversion.*'), $user->can('conversion.view')],
                    ['Call Recordings', route('call-recordings.index'), request()->routeIs('call-recordings.*'), $user->can('call_recordings.view')],
                    ['CRA Schedule', route('cra-schedule.index'), request()->routeIs('cra-schedule.*'), $user->can('on_duty.view')],
                ]],
                ['name' => 'Orders & Tracking', 'icon' => 'orders', 'items' => [
                    ['CVR Tracker', route('cvr-tracker.index'), request()->routeIs('cvr-tracker.*'), $user->can('cvr_tracker.view')],
                    ['Running Sales', route('running-sales.index'), request()->routeIs('running-sales.*'), $user->can('running_sales.view')],
                    ['Order Database', route('order-database.cra-performance'), request()->routeIs('order-database.*'), $user->can('order_database.view')],
                    ['PC Report', route('pc-report.index'), request()->routeIs('pc-report.*'), $user->can('pc_report.view')],
                ]],
                ['name' => 'Income & Products', 'icon' => 'statement', 'items' => [
                    ['Monthly MNI', route('mni.index'), request()->routeIs('mni.*'), $user->can('mni.view')],
                    // Pending: shown but not clickable for now, except for super admins.
                    ['Daily I.S.', route('daily-is.index'), request()->routeIs('daily-is.*'), $user->can('daily_is.view'), ! $user->isSuperAdmin()],
                    ['Product Performance', route('product-performance.index'), request()->routeIs('product-performance.*'), $user->can('product_performance.view')],
                    ['Product Target Market', route('product-target-market.index'), request()->routeIs('product-target-market.*'), $user->can('product_target_market.view')],
                ]],
                ['name' => 'Customers & Costs', 'icon' => 'database', 'items' => [
                    ['Customer Database', route($user->can('customers.view') ? 'customers.index' : 'customers.high-value'), request()->routeIs('customers.*'), $user->canAny(['customers.view', 'customers.high_value.view'])],
                    ['CRD - MC', route('monthly-cost.index'), request()->routeIs('monthly-cost.*'), $user->can('monthly_cost.manage')],
                ]],
            ])->map(fn (array $group) => [...$group, 'items' => array_values(array_filter($group['items'], fn (array $item) => $item[3]))])
              ->filter(fn (array $group) => $group['items'] !== [])
              ->map(fn (array $group) => [...$group, 'holdsPage' => collect($group['items'])->contains(fn (array $item) => $item[2])]);
        @endphp
        <aside id="sidebar" class="fixed inset-y-0 left-0 z-30 flex w-64 -translate-x-full flex-col bg-[#1b1530] text-[#e9e4f5] transition-transform lg:sticky lg:top-0 lg:h-dvh lg:shrink-0 lg:translate-x-0">
            <a href="{{ route('dashboard') }}" class="flex h-16 shrink-0 items-center gap-3 px-5">
                <x-crd-logo class="size-9" />
                <span class="text-lg font-bold text-white">CRD Deck</span>
            </a>

            <nav class="flex flex-1 flex-col gap-1 overflow-y-auto px-3 pb-4 text-sm" aria-label="Main">
                <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="home">Dashboard</x-nav-link>
                @can('report_summary.view')
                    <x-nav-link :href="route('report-summary.index')" :active="request()->routeIs('report-summary.*')" icon="report">Report Summary</x-nav-link>
                @endcan

                @if ($navGroups->isNotEmpty())
                    <div class="mx-1 my-2 h-px bg-white/10" aria-hidden="true"></div>
                @endif

                @foreach ($navGroups as $group)
                    {{-- name="nav-groups": opening one closes the others --}}
                    <details name="nav-groups" @if ($group['holdsPage']) open @endif @class(['group rounded-xl open:bg-white/[0.06]', 'bg-white/[0.06]' => $group['holdsPage']])>
                        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-3 rounded-xl px-3 font-semibold text-white hover:bg-white/5 focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                            <x-nav-icon :name="$group['icon']" class="size-5 shrink-0 text-[#b9a8e8]" />
                            <span class="flex-1">{{ $group['name'] }}</span>
                            <span class="text-xs font-semibold text-[#a89cc8] tabular-nums">{{ count($group['items']) }}</span>
                            <svg class="size-4 shrink-0 text-[#a89cc8] transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        <div class="flex flex-col gap-0.5 pt-0.5 pr-1.5 pb-2 pl-5">
                            @foreach ($group['items'] as $item)
                                @if ($item[4] ?? false)
                                    <span class="flex min-h-9 cursor-not-allowed items-center gap-3 rounded-lg px-2.5 text-[13.5px] font-medium text-[#8f84ad]" aria-disabled="true" title="Pending — not available yet">
                                        <span class="size-1.5 shrink-0 rounded-full bg-[#6d628f]" aria-hidden="true"></span>
                                        <span class="flex-1">{{ $item[0] }}</span>
                                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-[#c9bfe6] uppercase">Pending</span>
                                    </span>
                                @else
                                    <x-nav-link :href="$item[1]" :active="$item[2]" sub>{{ $item[0] }}</x-nav-link>
                                @endif
                            @endforeach
                        </div>
                    </details>
                @endforeach

                @canany(['user_access.view', 'product_consumption.view', 'pancake_pages.manage', 'pancake_accounts.manage', 'sales_goals.manage', 'segmentation_options.manage', 'niches.manage', 'connections.check', 'segmentation.view'])
                    <div class="mt-auto flex flex-col gap-1 border-t border-white/10 pt-3">
                        @can('user_access.view')
                            <x-nav-link :href="route('user-access.index')" :active="request()->routeIs('user-access.*', 'roles.*')" icon="users">User Access</x-nav-link>
                        @endcan
                        @canany(['product_consumption.view', 'pancake_pages.manage', 'pancake_accounts.manage', 'sales_goals.manage', 'segmentation_options.manage', 'niches.manage', 'connections.check', 'segmentation.view'])
                            <x-nav-link :href="route('settings.index')" :active="request()->routeIs('settings.*')" icon="settings">Settings</x-nav-link>
                        @endcanany
                    </div>
                @endcanany
            </nav>

            <div class="shrink-0 px-3 pb-1">
                <div class="flex items-center gap-3 rounded-xl bg-white/[0.06] px-3 py-2.5">
                    <x-avatar :user="$user" class="size-9" />
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-white">{{ $user->displayName() }}</p>
                        <p class="text-xs text-[#b3a8d1]">{{ $user->roleLabel() }}</p>
                    </div>
                </div>
            </div>

            {{-- CRAs on duty today; the list opens for supervisors --}}
            <x-on-duty class="shrink-0" />
        </aside>

        <div @class(['flex min-w-0 flex-1 flex-col', 'lg:min-h-0' => $fit])>
            {{-- Top bar --}}
            <header class="flex h-16 items-center justify-between gap-4 border-b border-line bg-white px-4 sm:px-6">
                <button type="button" class="rounded-md p-2 text-muted hover:bg-brand-50 lg:hidden" aria-controls="sidebar" aria-label="Toggle menu"
                        onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full')">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                {{-- Problems in the CRAs' orders (supervisors: every CRA; a CRA: their own) --}}
                <div class="flex min-w-0 flex-1 items-center">
                    @isset($craIssues)
                        <x-cra-issues :issues="$craIssues" />
                    @endisset
                </div>

                <div class="flex items-center gap-3">
                    <div class="flex rounded-lg border border-line bg-white p-0.5" role="group" aria-label="Theme" data-theme-switch>
                        @foreach (['light' => ['Light', 'M12 3v1.5M12 19.5V21M4.6 4.6l1.1 1.1M18.3 18.3l1.1 1.1M3 12h1.5M19.5 12H21M4.6 19.4l1.1-1.1M18.3 5.7l1.1-1.1M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z'], 'dark' => ['Dark', 'M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z']] as $theme => [$themeLabel, $path])
                            <button type="button" data-theme-set="{{ $theme }}" aria-pressed="false" title="{{ $themeLabel }} theme" aria-label="{{ $themeLabel }} theme"
                                    class="grid size-8 place-items-center rounded-md text-muted transition hover:text-brand-600 aria-pressed:bg-brand-600 aria-pressed:text-white">
                                <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/></svg>
                            </button>
                        @endforeach
                    </div>
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

            <main @class(['flex-1 p-4 sm:p-8', 'lg:flex lg:min-h-0 lg:flex-col lg:overflow-hidden lg:py-5' => $fit])>
                {{-- Only the Segmentation Tracker runs on the working date; every other page is the real date. --}}
                @if (request()->routeIs('segmentation.*') && ($workingDate = \App\Support\WorkingDate::get()))
                    <div role="note" class="mb-4 flex flex-wrap items-center gap-x-2 rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-2 text-sm text-[#473821]">
                        <span class="font-semibold">Working date: {{ $workingDate->format('M j, Y') }}</span>
                        <span>· The Segmentation Tracker shows this lead day as today (real date {{ \App\Support\WorkingDate::realToday()->format('M j') }}). Sales and conversion follow the real date.</span>
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

    {{-- A CRA's on-duty pop-up (log in for today + My schedule): opens by itself until they log in, and after saving the schedule --}}
    @if (request()->routeIs('segmentation.*') && $user->role?->slug === \App\Models\Role::CRA)
        <x-on-duty-prompt :open="\App\Support\OnDuty::needsLogin($user) || session('schedule_open') || $errors->has('dates') || $errors->has('dates.*')" />
    @endif
</body>
</html>
