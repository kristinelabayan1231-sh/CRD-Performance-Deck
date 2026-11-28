@props(['title' => 'Dashboard', 'fit' => false])

@php
    $user = auth()->user();

    // The modules: each a button on the black rail that opens its pages (a flyout), and the row of its pages above the
    // content when one of them is open. A page is [label, url, active, allowed, icon]; pages the user can't open, and
    // modules left empty, aren't shown.
    // The texting pages belong to the tracker the user works on today.
    $smsOnExclusive = $user->can('exclusive_leads.view') && $user->isExclusiveOn(\App\Models\Lead::today());
    $navGroups = collect([
        'overview' => ['name' => 'Overview', 'icon' => 'overview', 'items' => [
            ['CRA Dashboard', route('cra-dashboard'), request()->routeIs('cra-dashboard'), $user->role?->slug === \App\Models\Role::CRA || $user->can('segmentation.view_all'), 'user'],
            ['Dashboard', route('dashboard'), request()->routeIs('dashboard', 'dashboard.*', 'cancelled-orders.*'), true, 'home'],
            ['Report Summary', route('report-summary.index'), request()->routeIs('report-summary.*'), $user->can('report_summary.view'), 'report'],
            ['Presentation Board', route('presentation-board.index'), request()->routeIs('presentation-board.*'), $user->can('presentation_board.view'), 'board'],
        ]],
        'segmentation' => ['name' => 'Segmentation', 'icon' => 'segmentation', 'items' => [
            ['Segmentation Tracker', route('segmentation.index'), request()->routeIs('segmentation.*') || ($user->can('segmentation.view') && ! $smsOnExclusive && request()->routeIs('sms-reminders.*')), $user->can('segmentation.view'), 'list'],
            ['Segmentation Summary', route('segmentation-summary.index'), request()->routeIs('segmentation-summary.*'), $user->can('product_performance.view'), 'report'],
            ['Segmentation Productivity', route('productivity.index'), request()->routeIs('productivity.*'), $user->can('productivity.view'), 'gauge'],
            ['Conversion Breakdown', route('conversion.index'), request()->routeIs('conversion.*'), $user->can('conversion.view'), 'funnel'],
            ['Call Recordings', route('call-recordings.index'), request()->routeIs('call-recordings.*'), $user->can('call_recordings.view'), 'mic'],
            // Its pages are tabs of the Segmentation Tracker; here only for users without the tracker.
            ['SMS Reminders', route('sms-reminders.index'), request()->routeIs('sms-reminders.*'), $user->can('sms_reminders.view') && ! $user->can('segmentation.view'), 'send'],
            ['CRA Schedule', route('cra-schedule.index'), request()->routeIs('cra-schedule.*'), $user->can('on_duty.view'), 'calendar'],
        ]],
        'exclusive' => ['name' => 'Exclusive Leads', 'icon' => 'exclusive', 'items' => [
            ['Exclusive Lead Tracker', route('exclusive-leads.index'), (request()->routeIs('exclusive-leads.*') && ! request()->routeIs('exclusive-leads.dashboard')) || ($smsOnExclusive && request()->routeIs('sms-reminders.*')), $user->can('exclusive_leads.view'), 'star'],
            ['Exclusive Leads Dashboard', route('exclusive-leads.dashboard'), request()->routeIs('exclusive-leads.dashboard'), $user->can('exclusive_leads.view_all'), 'grid'],
        ]],
        'orders' => ['name' => 'Orders & Tracking', 'icon' => 'orders', 'items' => [
            ['CVR Tracker', route('cvr-tracker.index'), request()->routeIs('cvr-tracker.*'), $user->can('cvr_tracker.view'), 'trend'],
            ['Running Sales', route('running-sales.index'), request()->routeIs('running-sales.*'), $user->can('running_sales.view'), 'bolt'],
            ['Order Database', route('order-database.cra-performance'), request()->routeIs('order-database.*'), $user->can('order_database.view'), 'box'],
            ['PC Report', route('pc-report.index'), request()->routeIs('pc-report.*'), $user->can('pc_report.view'), 'page'],
        ]],
        'income' => ['name' => 'Income & Products', 'icon' => 'income', 'items' => [
            ['Monthly MNI', route('mni.index'), request()->routeIs('mni.*'), $user->can('mni.view'), 'target'],
            ['Daily I.S.', route('daily-is.index'), request()->routeIs('daily-is.*'), $user->can('daily_is.view'), 'receipt'],
            ['Actual Income', route('actual-income.index'), request()->routeIs('actual-income.*'), $user->can('actual_income.view'), 'wallet'],
            ['CRA Gross Sales & IBSC', route('cra-gross-sales.index'), request()->routeIs('cra-gross-sales.*'), $user->can('cra_gross_sales.view'), 'peso'],
            ['Product Performance', route('product-performance.index'), request()->routeIs('product-performance.*'), $user->can('product_performance.view'), 'bars'],
            ['Product Target Market', route('product-target-market.index'), request()->routeIs('product-target-market.*'), $user->can('product_target_market.view'), 'users'],
        ]],
        'customers' => ['name' => 'Customers & Costs', 'icon' => 'customers', 'items' => [
            ['Customer Database', route($user->can('customers.view') ? 'customers.index' : 'customers.high-value'), request()->routeIs('customers.*'), $user->canAny(['customers.view', 'customers.high_value.view']), 'users'],
            ['CRD - MC', route('monthly-cost.index'), request()->routeIs('monthly-cost.*'), $user->can('monthly_cost.manage'), 'coins'],
        ]],
        // Anyone who can open a Settings tab (App\Support\SettingsTabs), API Keys alone included.
        'settings' => ['name' => 'Settings', 'icon' => 'settings', 'items' => [
            ['User Access', route('user-access.index'), request()->routeIs('user-access.*', 'roles.*'), $user->can('user_access.view'), 'key'],
            ['Settings', route('settings.index'), request()->routeIs('settings.*'), $user->canAny(\App\Support\SettingsTabs::permissions()), 'gear'],
        ]],
    ])->map(fn (array $group) => [...$group, 'items' => array_values(array_filter($group['items'], fn (array $item) => $item[3]))])
      ->filter(fn (array $group) => $group['items'] !== [])
      ->map(fn (array $group) => [...$group, 'holdsPage' => collect($group['items'])->contains(fn (array $item) => $item[2])]);
    $currentGroup = $navGroups->first(fn (array $group) => $group['holdsPage']);
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
    {{-- A live reload (page-loader.js) comes back without the float-up --}}
    <script>try { if (sessionStorage.getItem('quietReload')) { sessionStorage.removeItem('quietReload'); document.documentElement.dataset.quietLoad = ''; } } catch (e) {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if (is_file(public_path('build/assets/theme-dark.css')))
        <link rel="stylesheet" href="{{ asset('build/assets/theme-dark.css') }}?v={{ filemtime(public_path('build/assets/theme-dark.css')) }}">
    @endif
</head>
<body class="min-h-screen bg-canvas font-sans text-ink antialiased">
    {{-- The rail: the CRD mark, a button per module (it opens that module's pages), Settings at the foot. On a phone it
         is a bar along the bottom and the pages open as a sheet above it. --}}
    <nav class="rail fixed inset-x-0 bottom-0 z-40 flex h-[4.25rem] items-center gap-1 overflow-x-auto px-2 pb-[env(safe-area-inset-bottom)] lg:inset-x-auto lg:inset-y-0 lg:left-0 lg:h-auto lg:w-[68px] lg:flex-col lg:overflow-y-auto lg:px-0.5 lg:py-3"
         aria-label="Main">
        <a href="{{ route('home') }}" class="mb-2 hidden shrink-0 rounded-xl focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-none lg:block" aria-label="CRD Deck home">
            <x-crd-logo variant="rail" class="size-[2.375rem]" />
        </a>
        @foreach ($navGroups as $key => $group)
            @if ($key === 'settings')
                <span class="hidden flex-1 lg:block" aria-hidden="true"></span>
            @endif
            <button type="button" popovertarget="fly-{{ $key }}" aria-expanded="false" @if ($group['holdsPage']) aria-current="true" @endif
                    class="rail-btn flex min-h-12 min-w-16 shrink-0 flex-col items-center justify-center gap-0.5 rounded-xl px-0.5 text-[0.625rem] leading-tight font-medium tracking-[-0.01em] transition focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-none lg:w-16">
                <x-shell-icon :name="$group['icon']" class="size-[1.15rem]" />
                <span class="max-w-full text-center">{{ $group['name'] }}</span>
            </button>
        @endforeach
    </nav>

    {{-- Each module's pages, opened from its rail button (closes on a click outside or Esc) --}}
    @foreach ($navGroups as $key => $group)
        <div popover id="fly-{{ $key }}" class="rail-fly overflow-y-auto" aria-label="{{ $group['name'] }}">
            <h2 class="mx-2.5 mt-1 mb-2 font-display text-lg font-bold tracking-tight">{{ $group['name'] }}</h2>
            <div class="flex flex-col gap-0.5">
                @foreach ($group['items'] as [$label, $href, $active, $allowed, $icon])
                    <a href="{{ $href }}" @if ($active) aria-current="page" @endif
                       class="flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 text-[0.9375rem] font-medium transition hover:bg-canvas focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-none">
                        <x-shell-icon :name="$icon" class="size-[1.05rem] shrink-0" />
                        <span>{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach

    <div @class(['flex min-h-screen min-w-0 flex-col pb-[calc(4.25rem+env(safe-area-inset-bottom))] lg:pb-0 lg:pl-[68px]', 'lg:h-dvh lg:overflow-hidden' => $fit])>
        {{-- Top bar: find a screen, who's on duty, order issues, then reorder alerts, the theme, the user --}}
        <header class="flex flex-wrap items-center gap-2 px-4 pt-3 sm:px-6">
            <form class="relative min-w-[12rem] flex-1 sm:max-w-sm" role="search" data-screen-search onsubmit="event.preventDefault(); this.querySelector('[data-screen-results] a')?.click()">
                <label for="screen-search" class="sr-only">Search screens</label>
                <x-shell-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                <input id="screen-search" type="search" autocomplete="off" placeholder="Search screens" data-screen-search-input
                       class="h-9 w-full rounded-full border border-line bg-white pr-4 pl-9 text-sm placeholder:text-muted focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                <div data-screen-results hidden class="absolute inset-x-0 top-full z-50 mt-2 max-h-[60vh] overflow-y-auto rounded-2xl border border-line bg-white p-1.5 shadow-lg"></div>
            </form>
            <x-on-duty />
            @isset($craIssues)
                <div class="flex min-w-0 items-center">
                    <x-cra-issues :issues="$craIssues" />
                </div>
            @endisset

            <div class="ml-auto flex items-center gap-2">
                @isset($reorderAlerts)
                    <x-reorder-alert :count="$reorderAlerts" />
                @endisset
                <div class="flex rounded-full border border-line bg-white p-0.5" role="group" aria-label="Theme" data-theme-switch>
                    @foreach (['light' => ['Light', 'M12 3v1.5M12 19.5V21M4.6 4.6l1.1 1.1M18.3 18.3l1.1 1.1M3 12h1.5M19.5 12H21M4.6 19.4l1.1-1.1M18.3 5.7l1.1-1.1M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z'], 'dark' => ['Dark', 'M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z']] as $theme => [$themeLabel, $path])
                        <button type="button" data-theme-set="{{ $theme }}" aria-pressed="false" title="{{ $themeLabel }} theme" aria-label="{{ $themeLabel }} theme"
                                class="grid size-8 place-items-center rounded-full text-muted transition hover:text-ink aria-pressed:bg-brand-600 aria-pressed:text-white">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/></svg>
                        </button>
                    @endforeach
                </div>
                <span class="flex items-center gap-2" title="{{ $user->roleLabel() }}">
                    <span class="hidden text-sm font-semibold sm:inline">{{ $user->displayName() }}</span>
                    <x-avatar :user="$user" class="size-8" />
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="grid size-9 place-items-center rounded-full border border-line bg-white text-muted transition hover:text-ink" title="Sign out" aria-label="Sign out">
                        <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v9m6.4-5.4a9 9 0 1 1-12.8 0"/></svg>
                    </button>
                </form>
            </div>
        </header>

        {{-- The open module's pages --}}
        @if ($currentGroup && count($currentGroup['items']) > 1)
            <nav class="flex gap-1 overflow-x-auto px-4 pt-3 [scrollbar-width:none] sm:px-6" aria-label="{{ $currentGroup['name'] }} pages">
                @foreach ($currentGroup['items'] as [$label, $href, $active, $allowed, $icon])
                    {{-- Icons, the open page with its label; the others' labels slide open on hover or focus --}}
                    <a href="{{ $href }}" @if ($active) aria-current="page" @endif title="{{ $label }}"
                       @class([
                           'group flex min-h-8 shrink-0 items-center rounded-full border px-2.5 text-[0.8125rem] font-semibold whitespace-nowrap transition-colors',
                           'border-line bg-white text-ink shadow-sm' => $active,
                           'border-transparent text-muted hover:bg-white hover:text-ink focus-visible:bg-white focus-visible:text-ink' => ! $active,
                       ])>
                        <x-shell-icon :name="$icon" @class(['size-4 shrink-0', 'text-[#c99400]' => $active]) />
                        <span @class([
                            'overflow-hidden transition-[max-width,opacity,margin] duration-300 ease-out',
                            'ml-1.5 max-w-[14rem] opacity-100' => $active,
                            'ml-0 max-w-0 opacity-0 group-hover:ml-1.5 group-hover:max-w-[14rem] group-hover:opacity-100 group-focus-visible:ml-1.5 group-focus-visible:max-w-[14rem] group-focus-visible:opacity-100' => ! $active,
                        ])>{{ $label }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        <main @class(['float-in flex-1 px-4 pt-4 pb-8 sm:px-6', 'lg:flex lg:min-h-0 lg:flex-col lg:overflow-hidden' => $fit])>
            {{-- Only the Segmentation Tracker runs on the working date (its header shows it as a pill); the Exclusive Lead Tracker is by real date with paired lead days. --}}
            @if (request()->routeIs('segmentation.*', 'exclusive-leads.*') && ! request()->routeIs('segmentation.index') && ($workingDate = \App\Support\WorkingDate::get()))
                <div role="note" class="mb-2 flex flex-wrap items-center gap-x-1.5 text-xs text-muted">
                    <span class="font-semibold">Working date: {{ $workingDate->format('M j, Y') }}</span>
                    @if (request()->routeIs('exclusive-leads.*'))
                        <span>· This page goes by the real date (today is {{ \App\Support\WorkingDate::realToday()->format('M j') }}). Each day's leads come from the lead day paired with it.</span>
                    @else
                        <span>· The Segmentation Tracker shows this lead day as today (real date {{ \App\Support\WorkingDate::realToday()->format('M j') }}). Sales and conversion follow the real date.</span>
                    @endif
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

    {{-- Shown while the next page loads (page-loader.js); moved over by half the rail so it sits over the content --}}
    <div data-page-loader role="status" hidden class="pointer-events-none fixed top-1/2 left-1/2 z-50 flex -translate-x-1/2 -translate-y-1/2 items-center gap-2.5 rounded-full bg-white px-5 py-3 text-sm font-semibold text-ink shadow-lg ring-1 ring-line lg:left-[calc(50%+34px)]">
        <span class="spinner" aria-hidden="true"></span>
        Loading…
    </div>

    {{-- Supervisors and managers: the floating CRA schedule, on every page but the CRA Schedule itself --}}
    @if ($user->can('on_duty.view') && ! request()->routeIs('cra-schedule.*'))
        <x-schedule-peek />
    @endif

    {{-- A CRA's on-duty pop-up (log in for today + My schedule): opens by itself until they log in, and after saving the schedule --}}
    @if (request()->routeIs('segmentation.*', 'exclusive-leads.*') && $user->role?->slug === \App\Models\Role::CRA)
        <x-on-duty-prompt :open="\App\Support\OnDuty::needsLogin($user) || session('schedule_open') || $errors->has('dates') || $errors->has('dates.*')" />
    @endif
</body>
</html>
