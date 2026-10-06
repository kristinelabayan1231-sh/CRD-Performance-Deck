<div class="mb-6 flex items-center gap-3">
    <span class="grid size-10 place-items-center rounded-lg bg-gradient-to-br from-brand-500 to-violet text-white shadow">
        <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-2.7 0-8 1.3-8 4v2h16v-2c0-2.7-5.3-4-8-4Zm8 0c-.3 0-.7 0-1.1.1 1.3.9 2.1 2.2 2.1 3.9v2h7v-2c0-2.7-5.3-4-8-4Z"/></svg>
    </span>
    <div>
        <h1 class="text-2xl font-semibold">User Access</h1>
        <p class="text-sm text-muted">Only the Google accounts listed here can sign in.</p>
    </div>
</div>

@can('roles.manage')
    <nav class="mb-8 flex gap-1 border-b border-line" aria-label="User Access sections">
        @foreach (['user-access.index' => 'Users', 'roles.index' => 'Roles'] as $route => $label)
            <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif
               @class([
                   '-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition',
                   'border-brand-500 text-brand-600' => request()->routeIs($route),
                   'border-transparent text-muted hover:text-brand-600' => ! request()->routeIs($route),
               ])>{{ $label }}</a>
        @endforeach
    </nav>
@endcan
