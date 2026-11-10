<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\SettingsTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['slug' => 'visitor', 'name' => 'Visitor', 'permissions' => $permissions]);

        return User::create(['email' => 'visitor@example.com', 'display_name' => 'Visitor', 'role_id' => $role->id, 'is_active' => true]);
    }

    public function test_a_role_with_only_api_keys_sees_settings_and_lands_on_api_keys(): void
    {
        $visitor = $this->userWith(['api_keys.manage']);

        $this->actingAs($visitor)->get(route('settings.api-keys.index'))
            ->assertOk()
            ->assertSee('href="'.route('settings.index').'"', false)
            ->assertSeeText('API Keys')
            ->assertDontSeeText('Connections');
        $this->actingAs($visitor)->get(route('settings.index'))->assertRedirect(route('settings.api-keys.index'));
    }

    public function test_every_settings_permission_a_role_can_have_opens_settings_on_its_page(): void
    {
        $visitor = $this->userWith([]);

        // Every one a role can be given (Product Qty is the Super Admin's alone).
        foreach (array_intersect(SettingsTabs::permissions(), array_keys(config('access.permissions'))) as $permission) {
            $visitor->role->update(['permissions' => [$permission]]);
            $landing = SettingsTabs::landingFor($visitor->fresh());

            $this->assertNotNull($landing, $permission);
            $this->actingAs($visitor->fresh())->get(route('settings.index'))->assertRedirect(route($landing));
        }
    }

    public function test_without_a_settings_tab_there_is_no_settings_link(): void
    {
        $visitor = $this->userWith([]);

        $this->assertNull(SettingsTabs::landingFor($visitor));
        $this->actingAs($visitor)->get(route('settings.index'))->assertForbidden();
    }
}
