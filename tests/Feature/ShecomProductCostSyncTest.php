<?php

namespace Tests\Feature;

use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\ShecomProductCost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShecomProductCostSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sales_sync_saves_each_orders_product_cost_from_shecoms_orders(): void
    {
        config(['services.shecom.product_cost_key' => 'test-key', 'services.shecom.sales_key' => 'sales-key']);
        PancakeOrder::create(['pancake_order_id' => '1389472', 'ordered_on' => '2026-11-09', 'seller_name' => 'CRD REJ', 'customer_name' => 'Buyer', 'status' => 1, 'total_price' => 500, 'tags' => [], 'non_crd' => false]);
        Http::fake([
            '*/management/sales*' => Http::response(['orders' => []]),
            '*/orders*' => Http::response(['rows' => [
                ['orderId' => '1389472', 'productCost' => 35],
                ['orderId' => '1389473', 'productCost' => null],
            ]]),
        ]);

        $this->artisan('pancake:sync', ['--date' => '2026-11-09', '--sales' => true])
            ->expectsOutputToContain('Shecom product costs 2026-11-09 to 2026-11-09: 1 orders updated.')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/external/v1/orders') && $request['from'] === '2026-11-09'
            && $request['to'] === '2026-11-09' && $request->hasHeader('Authorization', 'Bearer test-key'));
        $this->assertEqualsWithDelta(35, (float) PancakeOrder::where('pancake_order_id', '1389472')->value('shecom_product_cost'), 0.001);
    }

    public function test_the_sync_keeps_each_cost_from_its_effective_day_and_skips_items_without_one(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'Asia/Manila'));
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
        // Both costs are kept; the latest is the current one.
        $this->assertSame(2, ShecomProductCost::count());
        $this->assertSame(['3 bottles sinuxyl' => 120.0], ShecomProductCost::current());
    }

    public function test_a_cost_edited_in_shecom_with_an_earlier_effective_date_replaces_the_old_one(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 13:00', 'Asia/Manila'));
        config(['services.shecom.product_cost_key' => 'test-key']);
        $costs = fn (float $cost, string $from) => Http::response(['products' => [
            ['product' => 'Scar Erase', 'currentCost' => $cost, 'currentEffectiveFrom' => $from],
        ]]);
        Http::fakeSequence('*/product-costs')
            ->pushResponse($costs(42, '2026-11-03'))
            ->pushResponse($costs(40, '2026-10-01'))
            ->pushResponse($costs(42, '2026-11-03'));

        $this->artisan('shecom:product-costs')->assertSuccessful();
        $this->assertSame(['scar erase' => 42.0], ShecomProductCost::current());

        $this->travelTo(CarbonImmutable::parse('2026-11-06 13:10', 'Asia/Manila'));
        $this->artisan('shecom:product-costs')->assertSuccessful();
        $this->assertSame(['scar erase' => 40.0], ShecomProductCost::current());

        // Edited back to a cost it had before: the row already exists, and it's current again.
        $this->travelTo(CarbonImmutable::parse('2026-11-06 14:10', 'Asia/Manila'));
        $this->artisan('shecom:product-costs')->assertSuccessful();
        $this->assertSame(['scar erase' => 42.0], ShecomProductCost::current());
    }

    public function test_a_supervisor_refreshes_the_product_costs_from_the_daily_is(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'Asia/Manila'));
        config(['services.shecom.product_cost_key' => 'test-key']);
        Http::fakeSequence('*/product-costs')
            ->pushResponse(Http::response(['products' => [['product' => '1 Bottle Sinuxyl', 'currentCost' => 30, 'currentEffectiveFrom' => '2026-11-03']]]))
            ->pushStatus(500);
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->from(route('daily-is.index'))->post(route('daily-is.product-costs.refresh'))
            ->assertRedirect(route('daily-is.index'))
            ->assertSessionHas('status', 'Product costs refreshed from Shecom: 1 items have a cost.');
        $this->assertSame(['1 bottle sinuxyl' => 30.0], ShecomProductCost::current());

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

    public function test_the_current_cost_is_the_latest_one_and_a_cost_added_later_counts_too(): void
    {
        ShecomProductCost::create(['product' => '1 Bottle Sinuxyl', 'cost' => 30, 'effective_from' => '2026-11-03']);
        ShecomProductCost::create(['product' => '1 Bottle Sinuxyl', 'cost' => 32, 'effective_from' => '2026-11-10']);
        ShecomProductCost::create(['product' => 'AudiCure', 'cost' => 30, 'effective_from' => '2026-11-12']);

        $this->assertSame(['1 bottle sinuxyl' => 32.0, 'audicure' => 30.0], ShecomProductCost::current());
    }
}
