{{-- Order Database's title, tabs and dates/CRA filters; $tab is the tab's route. Switching tabs keeps the dates and CRA. --}}
@php($control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')

<header>
    <h1 class="text-xl font-semibold">Order Database <span class="text-sm font-normal text-muted">· {{ $label }} · Pancake POS orders sold by a CRD account (Settings → Pancake Accounts)</span></h1>
</header>

<nav class="flex gap-1 border-b-2 border-line" aria-label="Order Database">
    @foreach (['order-database.cra-performance' => "CRA's Performance", 'order-database.product-report' => 'Product Report'] as $route => $name)
        <a href="{{ route($route, array_filter(['period' => $period, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'sellers' => $sellers])) }}" @if ($tab === $route) aria-current="page" @endif
           @class(['-mb-0.5 rounded-t-lg border-b-2 px-4 py-2 text-sm font-semibold', 'border-brand-600 bg-white text-brand-600' => $tab === $route, 'border-transparent text-muted hover:text-brand-600' => $tab !== $route])>{{ $name }}</a>
    @endforeach
</nav>

{{-- Day, week (Monday–Sunday), month or From–To; switching keeps the dates shown as the starting point --}}
<form method="GET" action="{{ route($tab) }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-brand-200 bg-gradient-to-r from-brand-100 to-brand-50 p-4 shadow-sm" aria-label="Dates and filters">
    @if ($period !== 'range')
        <input type="hidden" name="from" value="{{ $from->toDateString() }}">
        <input type="hidden" name="to" value="{{ $to->toDateString() }}">
    @endif

    {{-- The period buttons submit their own; the other controls switch this on to keep the current one --}}
    <input type="hidden" name="period" value="{{ $period }}" disabled data-period>
    @php($submit = "this.form.querySelector('[data-period]').disabled = false; this.form.submit()")

    <div class="flex flex-col gap-1.5">
        <span class="text-xs font-semibold tracking-wide text-muted uppercase">Per</span>
        <div class="flex rounded-lg border border-line bg-white p-0.5" role="group" aria-label="Period">
            @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'range' => 'Range'] as $value => $name)
                <button type="submit" name="period" value="{{ $value }}" aria-pressed="{{ $period === $value ? 'true' : 'false' }}"
                        @class(['h-8 rounded-md px-3 text-sm font-semibold', 'bg-brand-600 text-white' => $period === $value, 'text-muted hover:text-brand-600' => $period !== $value])>{{ $name }}</button>
            @endforeach
        </div>
    </div>

    @switch ($period)
        @case ('day')
        @case ('week')
            <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                {{ $period === 'day' ? 'Day' : 'Week of' }}
                <input type="date" name="date" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}"
                       onchange="{{ $submit }}" class="{{ $control }} text-ink normal-case">
            </label>
            @break
        @case ('month')
            <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                Month
                <select name="month" onchange="{{ $submit }}" class="{{ $control }} font-semibold text-ink normal-case">
                    @for ($m = 0; $m < 12; $m++)
                        @php($option = $today->startOfMonth()->subMonths($m))
                        <option value="{{ $option->format('Y-m') }}" @selected($from->isSameMonth($option))>{{ $option->format('F Y') }}</option>
                    @endfor
                </select>
            </label>
            @break
        @default
            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold tracking-wide text-muted uppercase">From – to</span>
                <div class="flex items-center gap-1.5">
                    <input type="date" name="from" value="{{ $from->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="From" onchange="{{ $submit }}" class="{{ $control }}">
                    <span class="text-sm text-muted">to</span>
                    <input type="date" name="to" value="{{ $to->toDateString() }}" max="{{ $today->toDateString() }}" aria-label="To" onchange="{{ $submit }}" class="{{ $control }}">
                </div>
            </div>
    @endswitch

    @if ($canViewAll)
        {{-- Any number of CRAs; none picked = all of them --}}
        <fieldset class="min-w-0">
            <legend class="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">CRA</legend>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($accounts as $key => $name)
                    <label class="cursor-pointer">
                        <input type="checkbox" name="sellers[]" value="{{ $key }}" class="peer sr-only" onchange="{{ $submit }}" @checked(in_array($key, $sellers, true))>
                        <span class="block rounded-full border border-line bg-white px-3 py-1.5 text-sm text-muted transition peer-checked:border-brand-600 peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200">{{ $name }}</span>
                    </label>
                @endforeach
                <a href="{{ route($tab, array_filter(['period' => $period, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'product' => $filters['product'] ?? null])) }}"
                   @class(['rounded-full border px-3 py-1.5 text-sm', 'border-brand-600 bg-brand-600 text-white' => $sellers === [], 'border-dashed border-line bg-white text-muted hover:text-brand-600' => $sellers !== []])>All CRAs</a>
            </div>
        </fieldset>
    @endif

    @isset($products)
        <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
            Product
            <select name="product" onchange="{{ $submit }}" class="{{ $control }} text-ink normal-case">
                <option value="">All products</option>
                @foreach ($products as $name)
                    <option value="{{ $name }}" @selected(($filters['product'] ?? null) === $name)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
    @endisset
    <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white">Apply</button></noscript>
</form>
