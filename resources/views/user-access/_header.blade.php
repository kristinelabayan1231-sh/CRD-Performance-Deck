<h1 class="mb-4 text-xl font-semibold">User Access <span class="text-sm font-normal text-muted">· Only the Google accounts listed here can sign in.</span></h1>

@can('roles.manage')
    <nav class="mb-4 flex gap-1 border-b border-line" aria-label="User Access sections">
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
