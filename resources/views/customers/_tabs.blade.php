{{-- Customer Database sections --}}
<nav class="-mb-px flex gap-1 overflow-x-auto border-b border-line" aria-label="Customer Database sections">
    @php($tabs = collect(['customers.index' => ['Customers', 'customers.view'], 'customers.churn' => ['Churn', 'customers.view'], 'customers.high-value' => ['High AOV CVR & VIP', 'customers.high_value.view']])
        ->filter(fn ($tab) => auth()->user()->can($tab[1]))->map(fn ($tab) => $tab[0]))
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif
           @class([
               'shrink-0 border-b-2 px-4 py-2.5 text-sm font-semibold transition',
               'border-brand-500 text-brand-600' => request()->routeIs($route),
               'border-transparent text-muted hover:text-brand-600' => ! request()->routeIs($route),
           ])>{{ $label }}</a>
    @endforeach

    {{-- Eye button: hide customers' names and numbers (VIP 1, High AOV 1, Kr**** L***) for screen sharing --}}
    @php($privacy = app(\App\Support\CustomerPrivacy::class))
    @php($hidden = $privacy->isHidden())
    @if (! $privacy->canReveal())
    <span class="flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold ml-auto shrink-0 self-center bg-canvas text-muted"
          title="Hidden for every role. A Super Admin or CRA Supervisor can show them in Settings → Customer Database.">
        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        Details hidden
    </span>
    @else
    <form method="POST" action="{{ route('customers.privacy') }}" class="ml-auto shrink-0 self-center pl-2">
        @csrf
        <button type="submit" aria-pressed="{{ $hidden ? 'true' : 'false' }}"
                title="{{ $hidden ? 'Show customers\' names and numbers' : 'Hide customers\' names and numbers' }}"
                @class([
                    'flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold transition',
                    'bg-brand-600 text-white hover:bg-brand-700' => $hidden,
                    'text-muted hover:bg-brand-50 hover:text-brand-600' => ! $hidden,
                ])>
            @if ($hidden)
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 5.1A9.8 9.8 0 0 1 12 5c5 0 8.6 4.4 9.6 6.4a1.3 1.3 0 0 1 0 1.2 15 15 0 0 1-3 3.9M6.6 6.6A15 15 0 0 0 2.4 11.4a1.3 1.3 0 0 0 0 1.2C3.4 14.6 7 19 12 19a9.5 9.5 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                Details hidden
            @else
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.4 11.4C3.4 9.4 7 5 12 5s8.6 4.4 9.6 6.4a1.3 1.3 0 0 1 0 1.2C20.6 14.6 17 19 12 19s-8.6-4.4-9.6-6.4a1.3 1.3 0 0 1 0-1.2Z"/><circle cx="12" cy="12" r="3"/></svg>
                Hide details
            @endif
        </button>
    </form>
    @endif
</nav>
