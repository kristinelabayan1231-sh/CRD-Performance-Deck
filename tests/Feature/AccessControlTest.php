<?php

namespace Tests\Feature;

use App\Models\AllowedEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/admin/emails')->assertRedirect('/login');
    }

    public function test_authenticated_member_can_view_the_deck(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_non_admin_cannot_view_admin_pages(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin/emails')->assertForbidden();
    }

    public function test_admin_can_view_and_manage_allowed_emails(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        AllowedEmail::create(['email' => $admin->email, 'is_admin' => true]);

        $this->actingAs($admin)->get('/admin/emails')->assertOk();

        $this->actingAs($admin)
            ->post('/admin/emails', ['email' => 'newperson@example.com'])
            ->assertRedirect();

        $this->assertDatabaseHas('allowed_emails', ['email' => 'newperson@example.com']);
    }

    public function test_admin_cannot_remove_the_last_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $allowedAdmin = AllowedEmail::create(['email' => $admin->email, 'is_admin' => true]);

        $this->actingAs($admin)
            ->delete("/admin/emails/{$allowedAdmin->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('allowed_emails', ['id' => $allowedAdmin->id]);
    }
}
