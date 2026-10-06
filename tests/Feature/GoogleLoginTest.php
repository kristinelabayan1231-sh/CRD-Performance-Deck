<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(string $email, string $name = 'Test Person'): void
    {
        $googleUser = (new SocialiteUser)->map([
            'id' => 'google-'.md5($email),
            'name' => $name,
            'email' => $email,
            'avatar' => 'https://example.com/avatar.png',
        ])->setRaw(['email_verified' => true]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in with Google')->assertSee('CRD logo', false);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/user-access')->assertRedirect('/login');
    }

    public function test_default_super_admin_is_created_on_first_login(): void
    {
        $this->fakeGoogleUser('KristineLabayan1231@gmail.com', 'Kristine');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $user = User::firstWhere('email', 'kristinelabayan1231@gmail.com');
        $this->assertNotNull($user);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertAuthenticatedAs($user);
        $this->get('/user-access')->assertOk()->assertSee('Grant access');
    }

    public function test_unknown_google_account_is_rejected(): void
    {
        $this->fakeGoogleUser('stranger@gmail.com');

        $this->get('/auth/google/callback')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stranger@gmail.com']);
    }

    public function test_granted_account_can_sign_in_but_not_manage_access(): void
    {
        User::create(['email' => 'teammate@gmail.com', 'role_id' => Role::defaultUser()->id, 'is_active' => true]);
        $this->fakeGoogleUser('teammate@gmail.com', 'Team Mate');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertAuthenticated();
        $this->assertSame('Team Mate', User::firstWhere('email', 'teammate@gmail.com')->name);
        $this->get('/dashboard')->assertOk()->assertDontSee('Manage who can sign in');
        $this->get('/user-access')->assertForbidden();
    }

    public function test_disabled_account_cannot_sign_in(): void
    {
        User::create(['email' => 'off@gmail.com', 'role_id' => Role::defaultUser()->id, 'is_active' => false]);
        $this->fakeGoogleUser('off@gmail.com');

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_disabled_while_signed_in_is_logged_out(): void
    {
        $user = User::create(['email' => 'mid@gmail.com', 'role_id' => Role::defaultUser()->id, 'is_active' => true]);
        $this->actingAs($user);
        $user->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }
}
