<x-layouts.app title="Roles">
    @include('user-access._header')

    @if ($errors->any())
        <ul role="alert" class="mb-4 space-y-1 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    {{-- Create role --}}
    <section class="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <h2 class="mb-4 text-base font-semibold">Create role</h2>
        <form method="POST" action="{{ route('roles.store') }}">
            @csrf
            @include('user-access._role-fields', ['role' => null])
            <div class="mt-5 flex justify-end">
                <button type="submit" class="h-11 rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700">
                    Create role
                </button>
            </div>
        </form>
    </section>

    {{-- Existing roles --}}
    <section class="rounded-xl bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-line px-4 py-4">
            <h2 class="text-base font-semibold">Roles</h2>
            <span class="text-sm text-muted">{{ $roles->count() }} total</span>
        </div>

        <ul class="divide-y divide-line">
            @foreach ($roles as $role)
                <li>
                    <details class="group">
                        <summary class="flex cursor-pointer list-none items-center gap-4 px-4 py-4 hover:bg-canvas/40 [&::-webkit-details-marker]:hidden">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2 font-medium">
                                    {{ $role->name }}
                                    @if ($role->is_system)
                                        <span class="rounded-full bg-canvas px-2 py-0.5 text-xs font-semibold text-muted">Built-in</span>
                                    @endif
                                </p>
                                @if ($role->description)
                                    <p class="truncate text-sm text-muted">{{ $role->description }}</p>
                                @endif
                            </div>
                            <span class="hidden text-sm text-muted sm:inline">
                                {{ $role->isSuperAdmin() ? 'All permissions' : trans_choice(':count permission|:count permissions', count($role->grantedPermissions())) }}
                            </span>
                            <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700">
                                {{ trans_choice(':count user|:count users', $role->users_count) }}
                            </span>
                            <svg class="size-4 text-muted transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                        </summary>

                        <div class="border-t border-line bg-canvas/30 px-4 py-5">
                            @if ($role->isSuperAdmin())
                                <p class="text-sm text-muted">Super Admins always have every permission, including creating and editing roles. This role can't be changed.</p>
                            @else
                                <form method="POST" action="{{ route('roles.update', $role) }}">
                                    @csrf @method('PATCH')
                                    @include('user-access._role-fields', ['role' => $role])
                                    <div class="mt-5 flex flex-wrap items-center justify-end gap-2">
                                        @unless ($role->is_system)
                                            <button type="submit" form="delete-role-{{ $role->id }}"
                                                    class="h-10 rounded-lg border border-coral/60 px-4 font-medium text-coral-700 hover:bg-coral/10">
                                                Delete role
                                            </button>
                                        @endunless
                                        <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 font-semibold text-white hover:bg-brand-700">
                                            Save changes
                                        </button>
                                    </div>
                                </form>
                                @unless ($role->is_system)
                                    <form id="delete-role-{{ $role->id }}" method="POST" action="{{ route('roles.destroy', $role) }}"
                                          data-confirm="Delete the {{ $role->name }} role?" onsubmit="return confirm(this.dataset.confirm)">
                                        @csrf @method('DELETE')
                                    </form>
                                @endunless
                            @endif
                        </div>
                    </details>
                </li>
            @endforeach
        </ul>
    </section>
</x-layouts.app>
