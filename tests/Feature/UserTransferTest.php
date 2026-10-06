<?php

namespace Tests\Feature;

use App\Models\PancakePage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UserTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $file = 'storage/framework/testing/users-export.json';

    protected function tearDown(): void
    {
        File::delete(base_path($this->file));

        parent::tearDown();
    }

    public function test_users_and_roles_survive_an_export_and_import(): void
    {
        $owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        $custom = Role::create(['slug' => 'auditor', 'name' => 'Auditor', 'permissions' => ['segmentation.view'], 'is_system' => false]);
        User::create([
            'email' => 'lhea@example.com', 'display_name' => 'Lhea', 'pancake_name' => 'CRD Lhei',
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true, 'granted_by' => $owner->id,
        ]);
        User::create(['email' => 'audit@example.com', 'role_id' => $custom->id, 'is_active' => false]);
        PancakePage::create(['name' => 'Trusted Eye Care', 'page_id' => '1001', 'access_token' => 'secret-page-token', 'is_active' => false]);

        $this->artisan('users:export', ['path' => $this->file])->assertSuccessful();

        // A fresh server: only the built-in roles, no users.
        User::query()->delete();
        PancakePage::query()->delete();
        $custom->delete();

        $this->artisan('users:import', ['path' => $this->file])->assertSuccessful();

        $lhea = User::firstWhere('email', 'lhea@example.com');
        $this->assertSame('CRD Lhei', $lhea->pancake_name);
        $this->assertSame(Role::CRA, $lhea->role->slug);
        $this->assertSame('kristinelabayan1231@gmail.com', $lhea->grantedBy->email);
        $this->assertSame(['segmentation.view'], User::firstWhere('email', 'audit@example.com')->role->permissions);
        $this->assertFalse(User::firstWhere('email', 'audit@example.com')->is_active);

        $page = PancakePage::firstWhere('page_id', '1001');
        $this->assertSame('secret-page-token', $page->access_token);
        $this->assertFalse($page->is_active);

        // Running it again changes nothing.
        $this->artisan('users:import', ['path' => $this->file])->assertSuccessful();
        $this->assertSame(3, User::count());
    }
}
