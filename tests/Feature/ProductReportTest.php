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
            ->assertSeeTextInOrder(['CRD Lhei', '9', '₱2,700', '₱315', 'CRD July Ann', '3', '₱900', '₱105', '100%', '0%', '100%'])
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
