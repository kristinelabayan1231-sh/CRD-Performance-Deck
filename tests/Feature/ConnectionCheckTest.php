<?php

namespace Tests\Feature;

use App\Models\PancakePage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectionCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_runs_the_check_from_settings(): void
    {
        config(['services.pancake.access_token' => null, 'services.pancake.key' => 'pos-key', 'services.pancake.shop_id' => '1']);
        PancakePage::create(['name' => 'Trusted Eye Care', 'page_id' => '1001', 'access_token' => 't']);
        Http::fake(fn (Request $request) => match (true) {
            str_contains($request->url(), 'pos.pages.fm') => Http::response(['success' => false, 'message' => 'api_key is invalid', 'error_code' => 105], 403),
            str_contains($request->url(), 'customer_engagements') => Http::response(['success' => true, 'users_engagements' => []]),
            str_contains($request->url(), 'ipify') => Http::response('203.0.113.7'),
            default => Http::response(['count' => 12, 'stock_outs' => []]),
        });
        $owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);

        $this->actingAs($owner)->from(route('settings.connections.index'))->post(route('settings.connections.run'))
            ->assertRedirect(route('settings.connections.index'));

        $this->actingAs($owner)->get(route('settings.connections.index'))
            ->assertOk()
            ->assertSee('Pancake POS orders · API key (used for syncs)')
            ->assertSee('api_key is invalid')
            ->assertSee('1 of 1 pages OK')
            ->assertSee('203.0.113.7');
    }

    public function test_others_cannot_open_it(): void
    {
        $cra = User::create(['email' => 'cra@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);

        $this->actingAs($cra)->get(route('settings.connections.index'))->assertForbidden();
        $this->actingAs($cra)->post(route('settings.connections.run'))->assertForbidden();
    }
}
