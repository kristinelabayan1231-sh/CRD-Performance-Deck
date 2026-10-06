<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
    }

    public function test_built_in_roles_exist(): void
    {
        $this->assertSame(['cra', 'cra-supervisor', 'super-admin', 'user'], Role::orderBy('slug')->pluck('slug')->all());
    }

    public function test_super_admin_can_create_role_with_permissions(): void
    {
        $this->actingAs($this->owner)->get('/user-access/roles')->assertOk()->assertSee('Create role');

        $this->actingAs($this->owner)->post('/user-access/roles', [
            'name' => 'Marketing Analyst',
            'description' => 'Reads reports',
            'permissions' => ['user_access.view'],
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $role = Role::firstWhere('name', 'Marketing Analyst');
        $this->assertSame('marketing-analyst', $role->slug);
        $this->assertSame(['user_access.view'], $role->permissions);
        $this->assertFalse($role->is_system);

        // The new role is now assignable when granting access.
        $this->actingAs($this->owner)->get('/user-access')->assertSee('Marketing Analyst');
    }

    public function test_role_validation(): void
    {
        $this->actingAs($this->owner)->post('/user-access/roles', ['name' => 'User'])->assertSessionHasErrors('name');
        $this->actingAs($this->owner)->post('/user-access/roles', ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($this->owner)->post('/user-access/roles', ['name' => 'X', 'permissions' => ['nuke.everything']])->assertSessionHasErrors('permissions.0');
    }

    public function test_role_can_be_edited_and_permissions_cleared(): void
    {
        $role = Role::create(['slug' => 'temp', 'name' => 'Temp', 'permissions' => ['user_access.view']]);

        $this->actingAs($this->owner)->patch("/user-access/roles/{$role->id}", ['name' => 'Renamed'])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $role->fresh()->name);
        $this->assertSame([], $role->fresh()->permissions);
    }

    public function test_built_in_roles_are_protected(): void
    {
        $super = Role::superAdmin();
        $user = Role::defaultUser();

        $this->actingAs($this->owner)->patch("/user-access/roles/{$super->id}", ['permissions' => []])->assertForbidden();
        $this->actingAs($this->owner)->delete("/user-access/roles/{$user->id}")->assertForbidden();

        // The User role's permissions can change, but not its name.
        $this->actingAs($this->owner)->patch("/user-access/roles/{$user->id}", ['name' => 'Hacked', 'permissions' => ['user_access.view']]);
        $this->assertSame('User', $user->fresh()->name);
        $this->assertSame(['user_access.view'], $user->fresh()->permissions);
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $role = Role::create(['slug' => 'busy', 'name' => 'Busy', 'permissions' => []]);
        User::create(['email' => 'busy@gmail.com', 'role_id' => $role->id, 'is_active' => true]);

        $this->actingAs($this->owner)->delete("/user-access/roles/{$role->id}")->assertSessionHasErrors('role');
        $this->assertModelExists($role);

        $empty = Role::create(['slug' => 'empty', 'name' => 'Empty', 'permissions' => []]);
        $this->actingAs($this->owner)->delete("/user-access/roles/{$empty->id}");
        $this->assertModelMissing($empty);
    }

    public function test_only_super_admins_manage_roles(): void
    {
        $manager = User::create([
            'email' => 'mgr@gmail.com',
            'role_id' => Role::create(['slug' => 'mgr', 'name' => 'Mgr', 'permissions' => ['user_access.view', 'user_access.manage']])->id,
            'is_active' => true,
        ]);

        $this->actingAs($manager)->get('/user-access/roles')->assertForbidden();
        $this->actingAs($manager)->post('/user-access/roles', ['name' => 'Sneaky'])->assertForbidden();
        $this->actingAs($manager)->get('/user-access')->assertOk()->assertDontSee('href="'.route('roles.index').'"', false);
    }
}
