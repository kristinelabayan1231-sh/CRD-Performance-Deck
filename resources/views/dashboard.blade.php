<x-layouts.app title="Dashboard">
    <div class="mb-6 flex items-center gap-3">
        <span class="grid size-10 place-items-center rounded-lg bg-gradient-to-br from-brand-500 to-violet text-white shadow">
            <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 12h3v8h5v-6h4v6h5v-8h3z"/></svg>
        </span>
        <div>
            <h1 class="text-2xl font-semibold">Dashboard</h1>
            <p class="text-sm text-muted">Welcome, {{ auth()->user()->displayName() }}.</p>
        </div>
    </div>

    <div class="space-y-8">
        @if ($segmentation)
            <x-dashboard.segmentation :periods="$segmentation" />
        @endif

        {{-- Other modules --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @can('user_access.view')
                <a href="{{ route('user-access.index') }}"
                   class="group relative overflow-hidden rounded-xl bg-gradient-to-br from-brand-500 to-violet p-5 text-white shadow-sm transition hover:shadow-lg">
                    <div aria-hidden="true" class="absolute -top-10 -right-10 size-32 rounded-full bg-white/15"></div>
                    <p class="relative text-sm font-medium">User Access</p>
                    <p class="relative mt-2 text-3xl font-bold">{{ \App\Models\User::count() }} <span class="text-sm font-medium">accounts</span></p>
                    <p class="relative mt-2 text-xs font-semibold">Manage &rarr;</p>
                </a>
            @endcan

            @if (! $segmentation && ! auth()->user()->can('user_access.view'))
                <div class="rounded-xl bg-white p-6 text-muted shadow-sm">No modules are available to you yet.</div>
            @endif
        </div>
    </div>
</x-layouts.app>
