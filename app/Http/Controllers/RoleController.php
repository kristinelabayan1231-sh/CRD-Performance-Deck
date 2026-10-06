<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('user-access.roles', [
            'roles' => Role::withCount('users')->orderByDesc('is_system')->orderBy('name')->get(),
            'modules' => config('access.modules'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Role::create([
            ...$data,
            'slug' => $this->uniqueSlug($data['name']),
            'is_system' => false,
        ]);

        return back()->with('status', "Role \"{$data['name']}\" created.");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->isSuperAdmin(), 403, 'The Super Admin role always has full access.');

        $data = $this->validated($request, $role);

        // System roles keep their name so they stay recognisable.
        if ($role->is_system) {
            unset($data['name']);
        }

        $role->update($data);

        return back()->with('status', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->is_system, 403, 'Built-in roles cannot be deleted.');

        if ($role->users()->exists()) {
            return back()->withErrors(['role' => "\"{$role->name}\" is still assigned to {$role->users()->count()} account(s). Move them to another role first."]);
        }

        $role->delete();

        return back()->with('status', "Role \"{$role->name}\" deleted.");
    }

    /**
     * @return array{name: string, description: ?string, permissions: list<string>}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => [$role?->is_system ? 'nullable' : 'required', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($role)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(array_keys(config('access.permissions')))],
        ], [
            'name.unique' => 'A role with this name already exists.',
        ]);

        return [
            'name' => trim((string) ($data['name'] ?? $role?->name)),
            'description' => $data['description'] ?? null,
            'permissions' => array_values(array_unique($data['permissions'] ?? [])),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;

        for ($i = 2; Role::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
