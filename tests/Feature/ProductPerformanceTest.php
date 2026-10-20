<?php

namespace Tests\Feature;

use App\Models\DeliveredOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPerformanceTest extends TestCase
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
        Product::create(['name' => 'Sinuxyl', 'srp' => 300]);
        Product::create(['name' => 'Canpro', 'srp' => 900]);
    }

    private function order(string $seller, int $status, float $total, string $item, string $day = '2026-10-05'): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => (string) (9000 + $this->seq), 'ordered_on' => $day, 'ordered_at' => $day.' 02:00:00', 'seller_name' => $seller,
            'status' => $status, 'total_price' => $total, 'total_discount' => 0, 'items' => [['qty' => 1, 'name' => $item]],
        ]);
    }

    public function test_cards_show_crd_sales_and_order_counts_against_the_period_before(): void
    {
        // Sinuxyl this month: 4 delivered, 1 returned, 1 cancelled, 1 shipped (₱700 gross).
        foreach ([3, 3, 3, 16] as $status) {
            $this->order('CRD JULY ANN', $status, 100, 'Sinuxyl');
        }
        $this->order('CRD LHEI', 5, 100, 'Sinuxyl');
        $this->order('CRD LHEI', 6, 100, 'Sinuxyl');
        $this->order('CRD LHEI', 2, 100, 'Sinuxyl');
        // Not counted: deleted, and a seller not in Settings → Pancake Accounts.
        $this->order('CRD LHEI', 7, 5000, 'Sinuxyl');
        $this->order('FSD AGENT', 3, 5000, 'Sinuxyl');
        // Not counted: a product CRD doesn't handle (not in Settings → Product Consumption).
        $this->order('CRD LHEI', 3, 4000, 'Mystery Tea');
        // The 10 days before (Sep 21–30): ₱350, so Sinuxyl is up 100%.
        $this->order('CRD LHEI', 3, 350, 'Sinuxyl', '2026-09-28');

        $this->actingAs($this->owner)->get(route('product-performance.index', ['period' => 'month']))
            ->assertOk()
            ->assertSeeInOrder(['Sinuxyl', '▲ 100% vs before', '₱700', '7 orders', 'Delivered', '80%', '4/5', 'RTS', '20%', '1/5', 'Cancelled', '14%', '1/7'])
            // Every status with an order, in Pancake's order: shipped, delivered 3, payment collected 1, returned, canceled.
            ->assertSeeInOrder(['orders per status', 'Shipped', 'Delivered', '3', 'Payment collected', '1', 'Returned', 'Canceled'])
            ->assertDontSee('Waiting for pickup')
            ->assertSee('data-pp-toggle', false)
            ->assertSee('Sinuxyl leads with ₱700')
            ->assertSee('1 parcel still on the way')
            ->assertDontSee('₱5,000')
            ->assertDontSee('Other products')
            // The header's total leaves the ₱4,000 unlisted product out.
            ->assertSeeInOrder(['Gross sales per day', '₱700', '7 orders'])
            ->assertDontSee('₱4,000');
    }

    public function test_last_three_months_runs_to_today_and_groups_by_week(): void
    {
        $this->order('CRD LHEI', 3, 900, 'Canpro', '2026-07-11');
        $this->order('CRD LHEI', 3, 900, 'Canpro', '2026-07-10');

        $this->actingAs($this->owner)->get(route('product-performance.index', ['period' => 'quarter']))
            ->assertOk()
            ->assertSee('Gross sales per week')
            ->assertSeeInOrder(['Canpro', '₱900', '1 order']);
    }

    public function test_ranking_moves_against_the_period_before_and_retention_counts_customers_due_per_product(): void
    {
        // Oct 1–10: Canpro ₱900 overtakes Sinuxyl ₱300; Sep 21–30 Sinuxyl ₱1,000 led Canpro ₱900.
        $this->order('CRD LHEI', 3, 900, 'Canpro');
        $this->order('CRD LHEI', 3, 300, 'Sinuxyl');
        $this->order('CRD LHEI', 3, 1000, 'Sinuxyl', '2026-09-25');
        $this->order('CRD LHEI', 3, 900, 'Canpro', '2026-09-26');

        // Two Sinuxyl customers ran out Sep 3, so their 30 days to reorder ended Oct 3: one reordered (back), one didn't (lost).
        foreach (['9171111111', '9172222222'] as $phone) {
            DeliveredOrder::create(['order_id' => uniqid(), 'customer_name' => 'C', 'phone_number' => $phone, 'product_raw' => 'Sinuxyl',
                'qty' => 1, 'delivered_date' => '2026-08-20', 'consumption_days_per_unit' => 15, 'source' => DeliveredOrder::SOURCE_SHECOM]);
        }
        PancakeOrder::create(['pancake_order_id' => 'back-1', 'ordered_on' => '2026-09-20', 'phone_key' => '9171111111', 'seller_name' => 'FSD AGENT',
            'status' => 3, 'total_price' => 300, 'items' => [['qty' => 1, 'name' => 'Sinuxyl']]]);

        $this->actingAs($this->owner)->get(route('product-performance.index', ['period' => 'month']))
            ->assertOk()
            ->assertSeeInOrder(['Retention', '50.0%', '1 came back of 2 due'])
            ->assertSeeInOrder(['Ranking', '1', '▲ 1', 'Canpro', 'No one due', '2', '▼ 1', 'Sinuxyl', '50.0%', '1 of 2'])
            ->assertSee('Canpro moved up to #1')
            ->assertSee('Sinuxyl dropped to #2')
            ->assertSee('Sinuxyl keeps the most customers: 50.0%')
            ->assertSee('data-pp-graph="retention"', false);
    }

    public function test_cra_without_the_permission_is_refused(): void
    {
        $cra = User::create(['email' => 'cra@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);

        $this->actingAs($cra)->get(route('product-performance.index'))->assertForbidden();
    }
}
