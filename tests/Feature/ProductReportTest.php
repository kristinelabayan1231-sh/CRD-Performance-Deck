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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductReportTest extends TestCase
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
        Product::create(['name' => 'Sinuxyl', 'srp' => 300, 'unit_cost' => 35]);
        Product::create(['name' => 'Canpro', 'srp' => 900]);
    }

    /**
     * @param  array<string, int>  $items  name => qty
     */
    private function order(string $seller, int $status, float $total, array $items, string $day = '2026-10-05'): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => (string) (9000 + $this->seq), 'ordered_on' => $day, 'seller_name' => $seller, 'status' => $status,
            'total_price' => $total, 'total_discount' => 0,
            'items' => collect($items)->map(fn (int $qty, string $name) => ['qty' => $qty, 'name' => $name])->values()->all(),
        ]);
    }

    public function test_qty_counts_every_piece_and_rates_go_by_sales(): void
    {
        // Two customers, 3 + 5 bottles: 8 pieces. Delivered ₱900 + ₱1,500, returned ₱600, shipped ₱600.
        $this->order('CRD JULY ANN', 3, 900, ['Sinuxyl 60ml' => 3]);
        $this->order('CRD LHEI', 3, 1500, ['Sinuxyl' => 5]);
        $this->order('CRD LHEI', 5, 600, ['Sinuxyl' => 2]);
        $this->order('CRD LHEI', 2, 600, ['Sinuxyl' => 2]);
        // Not counted: deleted, another month, a seller not in Settings.
        $this->order('CRD LHEI', 7, 9000, ['Sinuxyl' => 9]);
        $this->order('CRD LHEI', 3, 9100, ['Sinuxyl' => 9], '2026-09-30');
        $this->order('FSD AGENT', 3, 9200, ['Sinuxyl' => 9]);

        // 12 pieces × ₱35; Actual Del 2,400 ÷ 3,000; Running Del 2,400 ÷ 3,600.
        $this->actingAs($this->owner)->get(route('order-database.product-report'))
            ->assertOk()
            ->assertSeeTextInOrder(['Total qty', 'Total sales', 'Product cost', 'Actual Del%', 'Actual RTS%', 'Running Del%'])
            ->assertSeeTextInOrder(['Sinuxyl', '12', '₱3,600', '₱420', '80%', '20%', '67%'])
            // No per-CRA breakdown — that's CRA's Performance.
            ->assertDontSeeText('₱2,700')
            ->assertDontSee('data-sellers', false)
            ->assertDontSee('₱9,');
    }

    public function test_an_order_with_several_products_splits_its_sale_by_srp_and_counts_once_in_the_total(): void
    {
        // SRP × qty: Sinuxyl 2 × 300 = 600, Canpro 1 × 900 = 900 → ₱800 and ₱1,200 of ₱2,000.
        $this->order('CRD JULY ANN', 3, 2000, ['Sinuxyl' => 2, 'Canpro Guyabano' => 1]);

        $this->actingAs($this->owner)->get(route('order-database.product-report'))
            ->assertOk()
            ->assertSeeTextInOrder(['Sinuxyl', '2', '₱800', '₱70', 'Canpro', '1', '₱1,200', '–', 'TOTAL', '1', '3', '₱2,000', '₱70'])
            ->assertViewHas('total', fn (array $total) => $total['statuses'] === [3 => 1]);

        $this->actingAs($this->owner)->get(route('order-database.product-report', ['product' => 'Canpro']))
            ->assertOk()->assertSeeText('₱1,200')->assertDontSeeText('₱800');
    }

    public function test_items_not_on_the_list_show_under_their_own_name_with_the_rest_of_the_price(): void
    {
        // Sinuxyl + an oil for ₱800: Shecom's ₱300 is the Sinuxyl's, the other ₱500 the oil's.
        $this->order('CRD LHEI', 3, 800, ['Sinuxyl' => 1, 'LUMICARE OIL' => 1]);
        PancakeOrder::latest('id')->first()->forceFill(['shecom_sales' => 300])->save();
        // An order of unlisted items only: split between them by pieces.
        $this->order('CRD LHEI', 3, 300, ['LUMICARE OIL' => 2, 'Turmeric Soap' => 1]);

        $response = $this->actingAs($this->owner)->get(route('order-database.product-report'))
            ->assertOk()
            ->assertSeeTextInOrder(['Sinuxyl', '1', '₱300', 'LUMICARE OIL', 'Not in Product Consumption', '3', '₱700', 'Turmeric Soap', '1', '₱100', 'TOTAL', '5', '₱1,100'])
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('product')->all() === ['Sinuxyl', 'LUMICARE OIL', 'Turmeric Soap']);

        // "Other products" in the filter: every item not on the list.
        $this->actingAs($this->owner)->get(route('order-database.product-report', ['product' => 'Other products']))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('product')->all() === ['LUMICARE OIL', 'Turmeric Soap']);
    }

    public function test_orders_fetched_again_count_pancakes_pieces_prices_and_costs(): void
    {
        config(['services.pancake.key' => 'pos-key', 'services.pancake.shop_id' => '1']);
        $item = fn (string $name, int $qty, string $code, float $price, float $cost) => ['quantity' => $qty,
            'variation_info' => ['name' => $name, 'custom_id' => $code, 'retail_price' => $price, 'exact_price' => $cost]];
        // Two 5-piece Sinuxyl bundles at ₱999 (₱150 cost each) and an oil at ₱1,000, in one ₱2,998 order.
        Http::fake(fn () => Http::response(['success' => true, 'total_pages' => 1, 'data' => [
            ['display_id' => 8101, 'inserted_at' => '2026-10-05T02:00:00', 'status' => 3, 'total_price' => 2998, 'total_discount' => 0,
                'assigning_seller' => ['id' => 'lh', 'name' => 'CRD LHEI'],
                'items' => [$item('Sinuxyl', 2, '5 Sinuxyl Drops', 999, 150), $item('LUMICARE OIL', 1, '3 Lumicare Oil', 1000, 138)]],
        ]]));

        $this->artisan('pancake:sync', ['--orders' => true, '--from' => '2026-10-05', '--to' => '2026-10-05'])->assertSuccessful();

        $this->assertEquals(
            [['name' => 'Sinuxyl', 'qty' => 2, 'pieces' => 5, 'price' => 999.0, 'cost' => 150.0], ['name' => 'LUMICARE OIL', 'qty' => 1, 'pieces' => 3, 'price' => 1000.0, 'cost' => 138.0]],
            PancakeOrder::sole()->items,
        );
        // 10 pieces, ₱1,998, ₱300 cost (not 10 × the ₱35 in Settings); the oil 3 pieces, ₱1,000, ₱138.
        $this->actingAs($this->owner)->get(route('order-database.product-report'))
            ->assertOk()
            ->assertSeeTextInOrder(['Sinuxyl', '10', '₱1,998', '₱300', 'LUMICARE OIL', '3', '₱1,000', '₱138', 'TOTAL', '13', '₱2,998', '₱438']);
    }

    public function test_a_cra_sees_only_their_own_account(): void
    {
        $cra = User::create([
            'email' => 'lhei@example.com', 'display_name' => 'Lhei', 'pancake_name' => 'CRD LHEI',
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
        $this->order('CRD JULY ANN', 3, 1000, ['Sinuxyl' => 3]);
        $this->order('CRD LHEI', 3, 700, ['Sinuxyl' => 2]);

        $this->actingAs($cra)->get(route('order-database.product-report', ['seller' => 'CRD JULY ANN']))
            ->assertOk()->assertSeeText('₱700')->assertDontSeeText('₱1,000');
    }
}
