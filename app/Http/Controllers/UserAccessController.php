<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserAccessController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::with(['role', 'grantedBy'])->get()
            ->sortBy(fn (User $user) => [! $user->isSuperAdmin(), $user->email])
            ->values();

        return view('user-access.index', [
            'users' => $users,
            'roles' => $this->assignableRoles($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'display_name' => ['nullable', 'string', 'max:100'],
            'role_id' => ['required', Rule::in($this->assignableRoles($request->user())->pluck('id'))],
        ], [
            'email.unique' => 'This Google account already has access.',
            'role_id.in' => 'You cannot assign that role.',
        ]);

        $user = User::create([
            'email' => $data['email'],
            'display_name' => $this->cleanName($data['display_name'] ?? null),
            'role_id' => $data['role_id'],
            'is_active' => true,
            'granted_by' => $request->user()->id,
        ]);

        $this->assignLeadsIfCra($user);

        return back()->with('status', "Access granted to {$data['email']}.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guardEditable($request, $user);

        $data = $request->validate([
            'role_id' => ['sometimes', Rule::in($this->assignableRoles($request->user())->pluck('id'))],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'role_id.in' => 'You cannot assign that role.',
        ]);

        $user->update($data);

        $this->assignLeadsIfCra($user);

        return back()->with('status', "Updated access for {$user->email}.");
    }

    /**
     * Display names can be set on any account you're allowed to see,
     * including your own; only Super Admins can rename Super Admins.
     */
    public function updateDisplayName(Request $request, User $user): RedirectResponse|JsonResponse
    {
        abort_if(
            $user->isSuperAdmin() && ! $request->user()->isSuperAdmin(),
            403,
            'Only super admins can rename other super admins.',
        );

        $data = $request->validate(['display_name' => ['present', 'nullable', 'string', 'max:100']]);

        $user->update(['display_name' => $this->cleanName($data['display_name'])]);

        if ($request->expectsJson()) {
            return response()->json(['saved' => true, 'display_name' => $user->displayName()]);
        }

        return back()->with('status', "Display name updated for {$user->email}.");
    }

    /**
     * The CRA's staff account name in Pancake, used by Segmentation Productivity.
     */
    public function updatePancakeAccount(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['pancake_name' => ['present', 'nullable', 'string', 'max:100']]);

        $user->update(['pancake_name' => $this->cleanName($data['pancake_name'])]);

        if ($request->expectsJson()) {
            return response()->json(['saved' => true]);
        }

        return back()->with('status', "Pancake account updated for {$user->email}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->guardEditable($request, $user);

        $user->delete();

        return back()->with('status', "Removed access for {$user->email}.");
    }

    /**
     * A new or re-enabled CRA picks up today's unassigned leads straight away.
     */
    private function assignLeadsIfCra(User $user): void
    {
        if ($user->is_active && $user->fresh('role')->role?->slug === Role::CRA) {
            app(LeadGenerator::class)->assign(Lead::today());
        }
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));

        return $name === '' ? null : $name;
    }

    /**
     * Only Super Admins can hand out the Super Admin role.
     */
    private function assignableRoles(User $actor)
    {
        return Role::orderByDesc('is_system')->orderBy('name')->get()
            ->reject(fn (Role $role) => $role->isSuperAdmin() && ! $actor->isSuperAdmin())
            ->values();
    }

    /**
     * The default super admin and your own account can't be changed here,
     * so nobody can lock themselves (or the owner) out. Only Super Admins
     * can change other Super Admins.
     */
    private function guardEditable(Request $request, User $user): void
    {
        abort_if($user->isOwner(), 403, 'The default super admin cannot be changed.');
        abort_if($user->is($request->user()), 403, 'You cannot change your own access.');
        abort_if($user->isSuperAdmin() && ! $request->user()->isSuperAdmin(), 403, 'Only super admins can change other super admins.');
    }
}
