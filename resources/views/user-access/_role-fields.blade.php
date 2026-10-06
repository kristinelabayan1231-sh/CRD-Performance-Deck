{{-- Shared name / description / permission fields for creating and editing a role. --}}
@php($role ??= null)
{{-- Only the create form re-fills from old input, so a failed create doesn't leak into the edit forms. --}}
@php($old = fn ($key, $default) => $role ? $default : old($key, $default))
@php($checked = $old('permissions', $role?->permissions ?? []))

<div class="grid gap-4 md:grid-cols-2">
    <label>
        <span class="mb-1 block text-sm font-medium">Role name</span>
        <input type="text" name="name" value="{{ $old('name', $role?->name) }}" maxlength="50" placeholder="e.g. Marketing Analyst"
               @if ($role?->is_system) disabled @else required @endif
               class="h-11 w-full rounded-lg border border-line px-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none disabled:bg-canvas disabled:text-muted">
    </label>
    <label>
        <span class="mb-1 block text-sm font-medium">Description <span class="font-normal text-muted">(optional)</span></span>
        <input type="text" name="description" value="{{ $old('description', $role?->description) }}" maxlength="255" placeholder="What is this role for?"
               class="h-11 w-full rounded-lg border border-line px-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
    </label>
</div>

<fieldset class="mt-5">
    <legend class="text-sm font-medium">Permissions</legend>
    <p class="text-xs text-muted">Every role can sign in and see the dashboard.</p>
    <div class="mt-3 grid gap-4 md:grid-cols-2">
        @foreach ($modules as $module => $permissions)
            <div class="rounded-lg border border-line p-4">
                <p class="mb-2 text-sm font-semibold">{{ $module }}</p>
                @foreach ($permissions as $key => $label)
                    <label class="flex items-start gap-2 py-1 text-sm">
                        <input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $checked, true))
                               class="mt-0.5 size-4 accent-brand-500">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        @endforeach
    </div>
</fieldset>
