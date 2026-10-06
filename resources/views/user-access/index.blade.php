<x-layouts.app title="User Access">
    @include('user-access._header')

    @php($canManage = auth()->user()->can('user_access.manage'))
    @php($defaultRoleId = $roles->firstWhere('slug', \App\Models\Role::USER)?->id)

    {{-- Grant access --}}
    @if ($canManage)
        <section class="mb-8 rounded-xl bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">Grant access</h2>
            <form method="POST" action="{{ route('user-access.store') }}" class="mt-4 flex flex-col gap-4 md:flex-row md:items-end">
                @csrf
                <label class="flex-1">
                    <span class="mb-1 block text-sm font-medium">Google account email</span>
                    <input type="email" name="email" value="{{ old('email') }}" required placeholder="name@gmail.com"
                           class="h-11 w-full rounded-lg border border-line px-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                </label>
                <label class="md:w-56">
                    <span class="mb-1 block text-sm font-medium">Display name <span class="font-normal text-muted">(optional)</span></span>
                    <input type="text" name="display_name" value="{{ old('display_name') }}" maxlength="100" placeholder="e.g. Anna"
                           class="h-11 w-full rounded-lg border border-line px-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                </label>
                <label class="md:w-56">
                    <span class="mb-1 block text-sm font-medium">Role</span>
                    <select name="role_id" class="h-11 w-full rounded-lg border border-line bg-white px-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected((int) old('role_id', $defaultRoleId) === $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="h-11 rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700">
                    Grant access
                </button>
            </form>
            @if ($errors->any())
                <ul role="alert" class="mt-3 space-y-1 text-sm text-coral-700">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-3 text-xs text-muted">
                What each role can do is set under
                @can('roles.manage')<a href="{{ route('roles.index') }}" class="font-medium text-brand-600 hover:underline">Roles</a>@else Roles @endcan.
            </p>
        </section>
    @endif

    {{-- Accounts --}}
    <section class="overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-line px-6 py-4">
            <h2 class="text-lg font-semibold">Accounts</h2>
            <span class="text-sm text-muted">{{ $users->count() }} total</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                    <tr>
                        <th class="px-6 py-3 font-semibold">Account</th>
                        <th class="px-6 py-3 font-semibold">Display name</th>
                        <th class="px-6 py-3 font-semibold">Role</th>
                        <th class="px-6 py-3 font-semibold">Status</th>
                        <th class="px-6 py-3 font-semibold">Last sign-in</th>
                        @if ($canManage)
                            <th class="px-6 py-3 text-right font-semibold">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($users as $account)
                        @php($locked = ! $canManage || $account->isOwner() || $account->is(auth()->user()) || ($account->isSuperAdmin() && ! auth()->user()->isSuperAdmin()))
                        @php($canRename = $canManage && (! $account->isSuperAdmin() || auth()->user()->isSuperAdmin()))
                        <tr>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <x-avatar :user="$account" class="size-9" />
                                    <div class="min-w-0">
                                        <p class="truncate font-medium" data-display-name-for="{{ $account->id }}">{{ $account->displayName() }}</p>
                                        @if ($account->displayName() !== $account->email)
                                            <p class="truncate text-muted">{{ $account->email }}</p>
                                        @endif
                                        @if ($account->display_name && $account->name && $account->name !== $account->display_name)
                                            <p class="truncate text-xs text-muted">Google name: {{ $account->name }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($canRename)
                                    <form method="POST" action="{{ route('user-access.display-name', $account) }}" data-autosave
                                          data-update-text="[data-display-name-for='{{ $account->id }}']">
                                        @csrf @method('PATCH')
                                        <input type="text" name="display_name" value="{{ $account->display_name }}" maxlength="100"
                                               placeholder="{{ $account->name ?: 'Not set' }}" aria-label="Display name for {{ $account->email }}"
                                               class="h-9 w-44 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                    </form>
                                @else
                                    <span @class(['text-muted' => ! $account->display_name])>{{ $account->display_name ?: '—' }}</span>
                                @endif
                                @if ($account->role?->slug === \App\Models\Role::CRA)
                                    @if ($canManage)
                                        <form method="POST" action="{{ route('user-access.pancake-account', $account) }}" data-autosave class="mt-2">
                                            @csrf @method('PATCH')
                                            <label class="flex flex-col gap-1 text-xs text-muted">
                                                Pancake account
                                                <input type="text" name="pancake_name" value="{{ $account->pancake_name }}" maxlength="100"
                                                       placeholder="e.g. CRD Lhei" aria-label="Pancake account for {{ $account->email }}"
                                                       class="h-8 w-44 rounded-lg border border-line bg-white px-3 text-sm text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                            </label>
                                        </form>
                                    @else
                                        <p class="mt-1 text-xs text-muted">Pancake: {{ $account->pancake_name ?: 'not set' }}</p>
                                    @endif
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @if ($locked)
                                    <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700">{{ $account->roleLabel() }}</span>
                                @else
                                    <form method="POST" action="{{ route('user-access.update', $account) }}">
                                        @csrf @method('PATCH')
                                        <select name="role_id" onchange="this.form.submit()" aria-label="Role for {{ $account->email }}"
                                                class="rounded-lg border border-line bg-white px-2 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                                            @foreach ($roles as $role)
                                                <option value="{{ $role->id }}" @selected($account->role_id === $role->id)>{{ $role->name }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @if (! $account->is_active)
                                    <span class="rounded-full bg-coral/15 px-2.5 py-1 text-xs font-semibold text-coral-700">Disabled</span>
                                @elseif (! $account->last_login_at)
                                    <span class="rounded-full bg-sky/15 px-2.5 py-1 text-xs font-semibold text-sky-800">Invited</span>
                                @else
                                    <span class="rounded-full bg-teal/15 px-2.5 py-1 text-xs font-semibold text-teal-700">Active</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-muted">
                                {{ $account->last_login_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            @if ($canManage)
                                <td class="px-6 py-4">
                                    @if ($locked)
                                        <p class="text-right text-xs text-muted">
                                            {{ $account->isOwner() ? 'Default super admin' : ($account->is(auth()->user()) ? 'You' : 'Super admin') }}
                                        </p>
                                    @else
                                        <div class="flex justify-end gap-2">
                                            <form method="POST" action="{{ route('user-access.update', $account) }}">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="is_active" value="{{ $account->is_active ? 0 : 1 }}">
                                                <button type="submit" class="rounded-lg border border-line px-3 py-1.5 font-medium hover:border-brand-400 hover:text-brand-600">
                                                    {{ $account->is_active ? 'Disable' : 'Enable' }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('user-access.destroy', $account) }}"
                                                  data-confirm="Remove access for {{ $account->email }}?" onsubmit="return confirm(this.dataset.confirm)">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="rounded-lg border border-coral/60 px-3 py-1.5 font-medium text-coral-700 hover:bg-coral/10">
                                                    Remove
                                                </button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
