<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShecomProductCost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShecomProductCostSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sync_keeps_each_cost_from_its_effective_day_and_skips_items_without_one(): void
    {
        config(['services.shecom.product_cost_key' => 'test-key']);
        $costs = fn (?float $cost, ?string $from) => Http::response(['products' => [
            ['product' => '3 Bottles Sinuxyl', 'currentCost' => $cost, 'currentEffectiveFrom' => $from],
            ['product' => '1 Sinuxyl', 'currentCost' => null, 'currentEffectiveFrom' => null],
        ]]);
        Http::fakeSequence('*/product-costs')
            ->pushResponse($costs(105, null))
            // Midnight Nov 1 in Manila, sent as UTC.
            ->pushResponse($costs(120, '2026-10-31T16:00:00.000Z'));

        $this->artisan('shecom:product-costs')->expectsOutputToContain('1 of 2 items have a cost')->assertSuccessful();
        $this->artisan('shecom:product-costs')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key'));
        $this->assertSame(['3 bottles sinuxyl' => 105.0], ShecomProductCost::costsOn('2026-10-31'));
        $this->assertSame(['3 bottles sinuxyl' => 120.0], ShecomProductCost::costsOn('2026-11-01'));
    }

    public function test_a_supervisor_refreshes_the_product_costs_from_the_daily_is(): void
    {
        config(['services.shecom.product_cost_key' => 'test-key']);
        Http::fakeSequence('*/product-costs')
            ->pushResponse(Http::response(['products' => [['product' => '1 Bottle Sinuxyl', 'currentCost' => 30, 'currentEffectiveFrom' => '2026-11-03']]]))
            ->pushStatus(500);
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->from(route('daily-is.index'))->post(route('daily-is.product-costs.refresh'))
            ->assertRedirect(route('daily-is.index'))
            ->assertSessionHas('status', 'Product costs refreshed from Shecom: 1 items have a cost.');
        $this->assertSame(['1 bottle sinuxyl' => 30.0], ShecomProductCost::costsOn('2026-11-03'));

        // Shecom not answering: a message, and the saved costs stay.
        $this->actingAs($supervisor)->post(route('daily-is.product-costs.refresh'))
            ->assertSessionHas('status', 'Shecom did not send the product costs. Try again in a minute.');
        $this->assertSame(1, ShecomProductCost::count());
    }

    public function test_a_cra_cannot_refresh_the_product_costs(): void
    {
        Http::fake();

        $this->actingAs($this->user('Regina', Role::CRA))->post(route('daily-is.product-costs.refresh'))->assertForbidden();
        Http::assertNothingSent();
    }

    private function user(string $name, string $role): User
    {
        return User::create(['email' => strtolower($name).'@example.com', 'display_name' => $name, 'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true]);
    }

    public function test_a_day_before_an_items_first_cost_takes_that_first_cost(): void
    {
        ShecomProductCost::create(['product' => '1 Bottle Sinuxyl', 'cost' => 30, 'effective_from' => '2026-11-03']);
        ShecomProductCost::create(['product' => '1 Bottle Sinuxyl', 'cost' => 32, 'effective_from' => '2026-11-10']);

        $this->assertSame(['1 bottle sinuxyl' => 30.0], ShecomProductCost::costsOn('2026-11-01'));
        $this->assertSame(['1 bottle sinuxyl' => 30.0], ShecomProductCost::costsOn('2026-11-09'));
        $this->assertSame(['1 bottle sinuxyl' => 32.0], ShecomProductCost::costsOn('2026-11-10'));
    }
}
