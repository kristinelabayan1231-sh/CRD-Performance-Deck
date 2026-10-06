<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeUser('kristinelabayan1231@gmail.com', Role::superAdmin());
    }

    private function makeUser(string $email, Role $role, bool $active = true): User
    {
        return User::create(['email' => $email, 'role_id' => $role->id, 'is_active' => $active]);
    }

    private function makeRole(string $name, array $permissions): Role
    {
        return Role::create(['slug' => str($name)->slug(), 'name' => $name, 'permissions' => $permissions]);
    }

    public function test_super_admin_can_grant_access(): void
    {
        $this->actingAs($this->owner)
            ->post('/user-access', ['email' => ' New.Person@Gmail.com ', 'role_id' => Role::defaultUser()->id])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('users', [
            'email' => 'new.person@gmail.com',
            'role_id' => Role::defaultUser()->id,
            'is_active' => true,
            'granted_by' => $this->owner->id,
        ]);
    }

    public function test_duplicate_and_invalid_grants_are_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post('/user-access', ['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::defaultUser()->id])
            ->assertSessionHasErrors('email');

        $this->actingAs($this->owner)
            ->post('/user-access', ['email' => 'x@gmail.com', 'role_id' => 999])
            ->assertSessionHasErrors('role_id');
    }

    public function test_super_admin_can_change_role_disable_and_remove(): void
    {
        $user = $this->makeUser('a@gmail.com', Role::defaultUser());

        $this->actingAs($this->owner)->patch("/user-access/{$user->id}", ['role_id' => Role::superAdmin()->id]);
        $this->assertTrue($user->fresh()->isSuperAdmin());

        $this->actingAs($this->owner)->patch("/user-access/{$user->id}", ['is_active' => 0]);
        $this->assertFalse($user->fresh()->is_active);

        $this->actingAs($this->owner)->delete("/user-access/{$user->id}");
        $this->assertModelMissing($user);
    }

    public function test_default_super_admin_cannot_be_changed_or_removed(): void
    {
        $other = $this->makeUser('b@gmail.com', Role::superAdmin());

        $this->actingAs($other)->patch("/user-access/{$this->owner->id}", ['role_id' => Role::defaultUser()->id])->assertForbidden();
        $this->actingAs($other)->patch("/user-access/{$this->owner->id}", ['is_active' => 0])->assertForbidden();
        $this->actingAs($other)->delete("/user-access/{$this->owner->id}")->assertForbidden();

        $this->assertTrue($this->owner->fresh()->isSuperAdmin());
    }

    public function test_super_admin_cannot_change_own_access(): void
    {
        $other = $this->makeUser('c@gmail.com', Role::superAdmin());

        $this->actingAs($other)->delete("/user-access/{$other->id}")->assertForbidden();
    }

    public function test_default_user_role_cannot_see_or_manage_access(): void
    {
        $user = $this->makeUser('d@gmail.com', Role::defaultUser());

        $this->actingAs($user)->get('/user-access')->assertForbidden();
        $this->actingAs($user)->post('/user-access', ['email' => 'e@gmail.com', 'role_id' => Role::defaultUser()->id])->assertForbidden();
        $this->actingAs($user)->get('/user-access/roles')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'e@gmail.com']);
    }

    public function test_view_only_role_sees_list_without_controls(): void
    {
        $viewer = $this->makeUser('viewer@gmail.com', $this->makeRole('Viewer', ['user_access.view']));

        $this->actingAs($viewer)->get('/user-access')->assertOk()->assertDontSee('Grant access')->assertDontSee('Remove');
        $this->actingAs($viewer)->post('/user-access', ['email' => 'f@gmail.com', 'role_id' => Role::defaultUser()->id])->assertForbidden();
    }

    public function test_manager_role_cannot_hand_out_or_touch_super_admin(): void
    {
        $manager = $this->makeUser('mgr@gmail.com', $this->makeRole('Manager', ['user_access.view', 'user_access.manage']));
        $admin = $this->makeUser('admin2@gmail.com', Role::superAdmin());
        $user = $this->makeUser('u@gmail.com', Role::defaultUser());

        $this->actingAs($manager)->post('/user-access', ['email' => 'g@gmail.com', 'role_id' => Role::superAdmin()->id])->assertSessionHasErrors('role_id');
        $this->actingAs($manager)->patch("/user-access/{$user->id}", ['role_id' => Role::superAdmin()->id])->assertSessionHasErrors('role_id');
        $this->actingAs($manager)->delete("/user-access/{$admin->id}")->assertForbidden();

        $this->actingAs($manager)->post('/user-access', ['email' => 'h@gmail.com', 'role_id' => Role::defaultUser()->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'h@gmail.com']);
    }

    public function test_display_name_can_be_set_when_granting_and_is_used_everywhere(): void
    {
        $this->actingAs($this->owner)->post('/user-access', [
            'email' => 'anna@gmail.com', 'display_name' => '  Anna   R. ', 'role_id' => Role::defaultUser()->id,
        ])->assertSessionHasNoErrors();

        $anna = User::firstWhere('email', 'anna@gmail.com');
        $this->assertSame('Anna R.', $anna->display_name);

        // A later Google sign-in sets the Google name but keeps the display name.
        $anna->update(['name' => 'Anna Reyes Santos']);
        $this->assertSame('Anna R.', $anna->fresh()->displayName());

        $this->actingAs($anna->fresh())->get('/dashboard')->assertSee('Anna R.');
        $this->actingAs($this->owner)->get('/user-access')->assertSee('Anna R.')->assertSee('Google name: Anna Reyes Santos');
    }

    public function test_display_name_can_be_changed_and_cleared(): void
    {
        $user = $this->makeUser('a@gmail.com', Role::defaultUser());
        $user->update(['name' => 'Google Name']);

        $this->actingAs($this->owner)->patchJson("/user-access/{$user->id}/display-name", ['display_name' => 'Anna'])
            ->assertOk()->assertJson(['display_name' => 'Anna']);
        $this->assertSame('Anna', $user->fresh()->displayName());

        // Clearing falls back to the Google name.
        $this->actingAs($this->owner)->patchJson("/user-access/{$user->id}/display-name", ['display_name' => ''])
            ->assertOk()->assertJson(['display_name' => 'Google Name']);
        $this->assertNull($user->fresh()->display_name);

        $this->actingAs($this->owner)->patchJson("/user-access/{$user->id}/display-name", ['display_name' => str_repeat('x', 101)])
            ->assertUnprocessable();
    }

    public function test_super_admin_can_rename_self_but_managers_cannot_rename_super_admins(): void
    {
        $this->actingAs($this->owner)->patchJson("/user-access/{$this->owner->id}/display-name", ['display_name' => 'Kristine'])->assertOk();
        $this->assertSame('Kristine', $this->owner->fresh()->displayName());

        $manager = $this->makeUser('mgr@gmail.com', $this->makeRole('Manager', ['user_access.view', 'user_access.manage']));
        $user = $this->makeUser('u@gmail.com', Role::defaultUser());

        $this->actingAs($manager)->patchJson("/user-access/{$this->owner->id}/display-name", ['display_name' => 'Hacked'])->assertForbidden();
        $this->actingAs($manager)->patchJson("/user-access/{$user->id}/display-name", ['display_name' => 'Ben'])->assertOk();

        $viewer = $this->makeUser('viewer@gmail.com', $this->makeRole('Viewer', ['user_access.view']));
        $this->actingAs($viewer)->patchJson("/user-access/{$user->id}/display-name", ['display_name' => 'Nope'])->assertForbidden();
        $this->assertSame('Ben', $user->fresh()->displayName());
    }
}
