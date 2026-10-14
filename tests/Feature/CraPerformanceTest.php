<?php

namespace Tests\Feature;

use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CraPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 18:00', 'Asia/Manila'));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        Setting::put(PancakeAccounts::CRD, json_encode(['CRD July Ann', 'CRD Lhei']), $this->owner);
        Product::create(['name' => 'Clearsight']);
        Product::create(['name' => 'Canpro']);
    }

    /**
     * @param  list<string>  $items
     */
    private function order(string $seller, int $status, float $total, array $items, string $day = '2026-10-05', ?float $discount = 0): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => (string) (8000 + $this->seq), 'ordered_on' => $day, 'seller_name' => $seller, 'status' => $status,
            'total_price' => $total, 'total_discount' => $discount, 'items' => array_map(fn (string $name) => ['qty' => 1, 'name' => $name], $items),
        ]);
    }

    public function test_sellers_show_orders_per_status_and_products_under_their_main_product(): void
    {
        $this->order('CRD JULY ANN', 3, 1000, ['Clear Sight 3.0']);
        $this->order('CRD JULY ANN', 6, 500, ['Clear Sight 3.0']);
        // Counts once, under the first listed product, with the whole sale.
        $this->order('CRD JULY ANN', 11, 2000, ['Gift Pouch', 'CanPro Guyabano Herbal Drink', 'Clear Sight 3.0']);
        $this->order('CRD JULY ANN', 3, 300, ['Turmeric Soap']);
        // Not counted: deleted, another month, a seller not in Settings.
        $this->order('CRD JULY ANN', 7, 9000, ['Clear Sight 3.0']);
        $this->order('CRD JULY ANN', 3, 9100, ['Clear Sight 3.0'], '2026-09-30');
        $this->order('FSD AGENT', 3, 9200, ['Clear Sight 3.0']);
        $this->order('CRD LHEI', 2, 700, ['Canpro']);

        $this->actingAs($this->owner)->get(route('order-database.cra-performance'))
            ->assertOk()
            ->assertSeeTextInOrder(['Waiting for goods', 'Shipped', 'Delivered', 'Canceled', 'Total qty', 'Total sales'])
            ->assertSeeTextInOrder(['CRD July Ann', '1', '2', '1', '4', '₱3,800'])
            ->assertSeeTextInOrder(['Clearsight', '2', '₱1,500', 'Canpro', '1', '₱2,000', 'Other products', '1', '₱300'])
            ->assertSeeTextInOrder(['CRD Lhei', '1', '1', '₱700', 'TOTAL', '5', '₱4,500'])
            ->assertDontSee('₱9,')
            ->assertDontSee('Payment collected');

        $this->actingAs($this->owner)->get(route('order-database.cra-performance', ['seller' => 'CRD LHEI']))
            ->assertOk()->assertSeeText('₱700')->assertDontSeeText('₱3,800');
    }

    public function test_a_cra_sees_only_their_own_account(): void
    {
        $cra = User::create([
            'email' => 'lhei@example.com', 'display_name' => 'Lhei', 'pancake_name' => 'CRD LHEI',
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
        $this->order('CRD JULY ANN', 3, 1000, ['Clear Sight 3.0']);
        $this->order('CRD LHEI', 3, 700, ['Canpro']);

        $this->actingAs($cra)->get(route('order-database.cra-performance', ['seller' => 'CRD JULY ANN']))
            ->assertOk()->assertSeeText('₱700')->assertDontSeeText('₱1,000')->assertDontSee('All CRAs');
    }

    public function test_sales_count_the_order_before_its_discount_and_show_the_discount(): void
    {
        // A GCash payment entered as a ₱61,000 discount: Pancake's total is ₱0.
        $this->order('CRD JULY ANN', 3, 0, ['Clear Sight 3.0'], discount: 61000);
        $this->order('CRD JULY ANN', 3, 1000, ['Clear Sight 3.0']);

        $this->actingAs($this->owner)->get(route('order-database.cra-performance'))
            ->assertOk()
            ->assertSeeTextInOrder(['CRD July Ann', '2', '₱62,000', 'Discounted: ₱61,000', 'Clearsight', '2', '₱62,000', 'Discounted: ₱61,000', 'TOTAL', '₱62,000', 'Discounted: ₱61,000']);
    }

    public function test_discounts_option_fetches_again_orders_saved_without_their_discount(): void
    {
        config(['services.pancake.key' => 'pos-key', 'services.pancake.shop_id' => '1']);
        $this->order('CRD JULY ANN', 3, 0, ['Clear Sight 3.0'], discount: null);
        Http::fake(fn (Request $request) => Http::response(['success' => true, 'total_pages' => 1, 'data' => str_contains($request->url(), 'search=8001&')
            ? [['display_id' => 8001, 'inserted_at' => '2026-10-05T02:00:00', 'status' => 3, 'total_price' => 0, 'total_discount' => 61000,
                'assigning_seller' => ['id' => 's1', 'name' => 'CRD JULY ANN'], 'items' => [['quantity' => 1, 'variation_info' => ['name' => 'Clear Sight 3.0']]]]]
            : []]));

        $this->artisan('pancake:sync', ['--discounts' => true, '--from' => '2026-10-01'])->assertSuccessful();

        $this->assertSame('61000.00', PancakeOrder::firstWhere('pancake_order_id', '8001')->total_discount);
        $this->actingAs($this->owner)->get(route('order-database.cra-performance'))->assertSeeText('₱61,000');
    }
}
