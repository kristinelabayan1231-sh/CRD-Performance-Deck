<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ConversionBreakdown;
use App\Services\CraProductSales;
use App\Services\PosLeads;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsdUpsellTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $rose;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00', 'Asia/Manila'));
        $this->withoutDefer();
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        $this->rose = User::create(['email' => 'rose@example.com', 'pancake_name' => 'CRD Rose', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        Product::create(['name' => 'Clear Sight 3.0', 'keywords' => 'Clearsight', 'srp' => 499, 'consumption_days' => 15]);
    }

    private function supervisor(): User
    {
        return User::create(['email' => 'sup@gmail.com', 'role_id' => Role::where('slug', Role::CRA_SUPERVISOR)->value('id'), 'is_active' => true]);
    }

    private function order(string $id, array $items, float $total): PancakeOrder
    {
        return PancakeOrder::create(['pancake_order_id' => $id, 'ordered_on' => '2026-10-28', 'ordered_at' => '2026-10-28 02:00:00', 'phone_key' => '91700'.$id,
            'customer_name' => 'Customer '.$id, 'seller_name' => 'CRD ROSE', 'status' => 1, 'total_price' => $total, 'items' => $items,
            'conversion_type' => PancakeOrder::SEGMENTATION]);
    }

    public function test_an_order_with_only_a_tsd_product_counts_in_the_cras_sales_but_not_as_a_lead(): void
    {
        $haplunas = $this->order('1385371', [['name' => 'Haplunas Healing Eye Cream', 'qty' => 1]], 499);
        $mixed = $this->order('1385372', [['name' => 'Clearsight Eye Drops', 'qty' => 1], ['name' => 'Haplunas Healing Eye Cream', 'qty' => 1]], 998);
        $other = $this->order('1385373', [['name' => 'Some Other Cream', 'qty' => 1]], 300);

        $this->actingAs($this->supervisor())
            ->post(route('settings.product-consumption.store'), ['is_tsd' => '1', 'name' => 'Haplunas', 'srp' => '499', 'counts_from' => '2026-10-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame([true, false], [$haplunas->fresh()->non_crd, $mixed->fresh()->non_crd]);
        $this->assertSame([true, false, false], [$haplunas->fresh()->tsd_upsell, $mixed->fresh()->tsd_upsell, $other->fresh()->tsd_upsell]);

        // Gross sales: the Haplunas order and the mixed one, not the order with neither.
        $day = CarbonImmutable::parse('2026-10-28');
        $sales = app(ConversionBreakdown::class)->orders(collect(['CRD ROSE' => $this->rose->id]), $day, $day);
        $this->assertSame(1497.0, (float) $sales[$this->rose->id]['2026-10-28']['sc_gross']);

        // Filed under the TSD product, split by SRP in the mixed order.
        $products = app(CraProductSales::class)->for(collect([$this->rose]), $day, $day)[$this->rose->id];
        $this->assertSame(['Clear Sight 3.0' => 499.0, 'Haplunas' => 998.0], $products->sortKeys()->all());

        // No lead for the TSD-only order; the mixed one still makes one.
        app(PosLeads::class)->record($day);
        $this->assertSame(['Customer 1385372'], Lead::pluck('customer_name')->all());
    }

    public function test_a_tsd_product_counts_only_from_its_starting_date(): void
    {
        $order = $this->order('1385371', [['name' => 'Haplunas Healing Eye Cream', 'qty' => 1]], 499);

        $this->actingAs($this->owner)
            ->post(route('settings.product-consumption.store'), ['is_tsd' => '1', 'name' => 'Haplunas', 'counts_from' => '2026-10-29'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($order->fresh()->tsd_upsell);
    }

    public function test_several_tsd_products_are_added_in_one_submission(): void
    {
        $supervisor = $this->supervisor();
        $this->actingAs($supervisor)->get(route('settings.product-consumption.index', ['add' => 'tsd']))->assertOk()->assertSee('+ Another product');

        // A name already listed, or twice in the list: nothing is added.
        $this->actingAs($supervisor)->post(route('settings.product-consumption.store'), ['is_tsd' => '1', 'counts_from' => '2026-10-01', 'products' => [
            ['name' => 'Turmeric'], ['name' => 'Clear Sight 3.0'],
        ]])->assertSessionHasErrors('products.1.name');
        $this->actingAs($supervisor)->post(route('settings.product-consumption.store'), ['is_tsd' => '1', 'counts_from' => '2026-10-01', 'products' => [
            ['name' => 'Turmeric'], ['name' => 'turmeric'],
        ]])->assertSessionHasErrors('products.1.name');
        $this->assertSame(1, Product::count());

        // A row left blank is skipped.
        $this->actingAs($supervisor)->post(route('settings.product-consumption.store'), ['is_tsd' => '1', 'counts_from' => '2026-10-01', 'products' => [
            ['name' => ' Turmeric ', 'keywords' => 'Turmeric Capsule,, Turmeric Capsule', 'srp' => '399', 'unit_cost' => '80'],
            ['name' => '', 'keywords' => '', 'srp' => ''],
            ['name' => 'Haplunas'],
        ]])->assertSessionHasNoErrors()->assertSessionHas('status', '2 TSD upsell products added: Turmeric, Haplunas.');

        $turmeric = Product::firstWhere('name', 'Turmeric');
        $this->assertSame([true, 'Turmeric Capsule', '399.00', '80.00', '2026-10-01'], [$turmeric->is_tsd, $turmeric->keywords, $turmeric->srp, $turmeric->unit_cost, $turmeric->counts_from->toDateString()]);
        $this->assertTrue(Product::firstWhere('name', 'Haplunas')->is_tsd);
    }

    public function test_a_supervisor_manages_tsd_products_but_not_crd_products(): void
    {
        $supervisor = $this->supervisor();
        $clearSight = Product::firstWhere('name', 'Clear Sight 3.0');

        // TSD: added without a Testing / Existing choice, edited and removed.
        $this->actingAs($supervisor)->post(route('settings.product-consumption.store'), ['is_tsd' => '1', 'name' => 'Haplunas', 'consumption_days' => '15'])
            ->assertSessionHasNoErrors();
        $haplunas = Product::firstWhere('name', 'Haplunas');
        $this->assertSame([true, false, null], [$haplunas->is_tsd, $haplunas->is_testing, $haplunas->consumption_days]);

        $this->actingAs($supervisor)->patch(route('settings.product-consumption.update', $haplunas), ['name' => 'Haplunas', 'keywords' => 'Haplunas Cream'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Haplunas Cream', $haplunas->fresh()->keywords);

        // CRD products, and moving a TSD product to CRD, take the full permission.
        $this->actingAs($supervisor)->post(route('settings.product-consumption.store'), ['is_tsd' => '0', 'is_testing' => '0', 'name' => 'NutriLay'])->assertForbidden();
        $this->actingAs($supervisor)->patch(route('settings.product-consumption.update', $clearSight), ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($supervisor)->patch(route('settings.product-consumption.update', $haplunas), ['name' => 'Haplunas', 'is_tsd' => '0'])->assertForbidden();
        $this->actingAs($supervisor)->delete(route('settings.product-consumption.destroy', $clearSight))->assertForbidden();
        $this->assertTrue($haplunas->fresh()->is_tsd);

        $this->actingAs($supervisor)->delete(route('settings.product-consumption.destroy', $haplunas));
        $this->assertModelMissing($haplunas);
        $this->assertModelExists($clearSight);
    }

    public function test_the_list_filters_by_source_and_opens_a_product_in_the_panel(): void
    {
        Product::create(['name' => 'Haplunas', 'is_tsd' => true, 'keywords' => 'Haplunas Healing Eye Cream']);

        $this->actingAs($this->owner)->get(route('settings.product-consumption.index', ['source' => 'tsd']))
            ->assertOk()->assertSee('Haplunas Healing Eye Cream')->assertDontSee('Clear Sight 3.0');

        $haplunas = Product::firstWhere('name', 'Haplunas');
        $this->actingAs($this->owner)->get(route('settings.product-consumption.index', ['product' => $haplunas->id]))
            ->assertOk()->assertSee(route('settings.product-consumption.update', $haplunas))->assertSee('Clear Sight 3.0');

        // A TSD product isn't on the CRD-only lists.
        $this->actingAs($this->owner)->get(route('settings.product-stage.index'))->assertOk()->assertDontSee('Haplunas');
    }
}
