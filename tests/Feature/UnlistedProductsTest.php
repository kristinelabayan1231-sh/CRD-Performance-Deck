<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ConversionBreakdown;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UnlistedProductsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $nutrilay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Manila'));
        $this->withoutDefer();
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        $this->nutrilay = Product::create(['name' => 'NutriLay', 'consumption_days' => 15]);
        Product::create(['name' => 'Pterygium', 'consumption_days' => 15]);
    }

    /**
     * A CRD-delivered order with its Pancake copy (items, total), sold by CRA Lhea's account.
     */
    private function order(string $id, string $name, string $phone, array $items, float $total): void
    {
        LogisticsOrder::remember([['order_id' => $id, 'team' => 'crd', 'customer_name' => $name, 'phone_number' => $phone,
            'product' => implode(', ', array_column($items, 'name')), 'qty' => 1, 'delivered_date' => '2026-10-05']]);
        PancakeOrder::create(['pancake_order_id' => $id, 'ordered_on' => '2026-10-02', 'phone_key' => $phone, 'seller_name' => 'CRD LHEI',
            'status' => 3, 'total_price' => $total, 'items' => $items, 'conversion_type' => PancakeOrder::SEGMENTATION]);
    }

    private function lead(string $orderId, string $product, array $fields = []): Lead
    {
        return Lead::create([
            'order_id' => $orderId, 'customer_name' => 'Customer '.$orderId, 'phone_number' => '917000'.$orderId, 'product_name' => $product, 'product_raw' => $product,
            'qty' => 1, 'delivered_date' => '2026-09-25', 'consumption_days' => 15, 'est_out_of_stock_date' => '2026-10-09', 'lead_type' => Lead::TYPE_FSD,
            ...$fields,
        ]);
    }

    private function deleteNutrilay(): TestResponse
    {
        return $this->actingAs($this->owner)->delete(route('settings.product-consumption.destroy', $this->nutrilay));
    }

    public function test_deleting_a_product_tags_orders_with_no_listed_product_and_removes_their_untouched_leads(): void
    {
        $this->order('1', 'Nora Only', '9171111111', [['name' => 'NutriLay Powder', 'qty' => 2]], 3000);
        $this->order('2', 'Mia Mixed', '9172222222', [['name' => 'NutriLay', 'qty' => 1], ['name' => 'Pterygium', 'qty' => 1]], 2500);
        $untouched = $this->lead('3', 'NutriLay');
        $worked = $this->lead('4', 'NutriLay', ['status' => 'active']);
        $mixed = $this->lead('5', 'NutriLay, Pterygium');

        $this->deleteNutrilay()->assertSessionHas('status', 'Product "NutriLay" deleted. Removed 1 untouched lead whose product is no longer on the list.');

        $this->assertSame(['1'], PancakeOrder::where('non_crd', true)->pluck('pancake_order_id')->all());
        $this->assertSame(['1'], LogisticsOrder::where('non_crd', true)->pluck('order_id')->all());
        $this->assertNull($untouched->fresh());
        $this->assertNotNull($worked->fresh());
        $this->assertNotNull($mixed->fresh());

        // New orders are tagged as they're saved.
        $this->order('6', 'Nora Only', '9171111111', [['name' => 'NutriLay', 'qty' => 1]], 1500);
        $this->assertTrue(LogisticsOrder::firstWhere('order_id', '6')->non_crd);

        // Adding it back to the list brings its orders back.
        $this->actingAs($this->owner)->post(route('settings.product-consumption.store'), ['name' => 'NutriLay', 'consumption_days' => 15]);
        $this->assertSame(0, PancakeOrder::where('non_crd', true)->count() + LogisticsOrder::where('non_crd', true)->count());
    }

    public function test_orders_with_no_listed_product_are_left_out_of_the_customer_database_and_sales(): void
    {
        $lhea = User::create(['email' => 'lhea@example.com', 'pancake_name' => 'CRD Lhei', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        $this->order('1', 'Nora Only', '9171111111', [['name' => 'NutriLay', 'qty' => 1]], 3000);
        $this->order('2', 'Mia Mixed', '9172222222', [['name' => 'NutriLay', 'qty' => 1], ['name' => 'Pterygium', 'qty' => 1]], 2500);
        $this->deleteNutrilay();

        $this->actingAs($this->owner)->get(route('customers.index'))->assertOk()
            ->assertSee('Mia Mixed')->assertSee('₱2,500.00')->assertDontSee('Nora Only')
            ->assertViewHas('counts', fn (array $counts) => $counts['all'] === 1);
        $this->get(route('customers.show', '9171111111'))->assertNotFound();

        // Gross sales: only the mixed order.
        $sales = app(ConversionBreakdown::class)->orders(collect(['CRD LHEI' => $lhea->id]), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-10'));
        $this->assertSame(2500.0, (float) $sales[$lhea->id]['2026-10-02']['sc_gross']);
        $this->assertSame(1, $sales[$lhea->id]['2026-10-02']['sc_orders']);
    }
}
