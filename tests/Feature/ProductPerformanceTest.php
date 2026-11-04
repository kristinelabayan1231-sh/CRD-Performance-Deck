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

    /**
     * @param  list<array{qty: int, name: string, price: float|int}>  $items
     */
    private function itemsOrder(array $items, string $day = '2026-10-05'): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => (string) (9000 + $this->seq), 'ordered_on' => $day, 'ordered_at' => $day.' 02:00:00', 'seller_name' => 'CRD LHEI',
            'status' => 3, 'total_price' => array_sum(array_column($items, 'price')), 'total_discount' => 0, 'items' => $items,
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

    public function test_ranking_moves_against_the_period_before_and_shows_rts_per_product(): void
    {
        // Oct 1–10: Canpro ₱900 (delivered) overtakes Sinuxyl ₱500 (4 delivered, 1 returned); Sep 21–30 Sinuxyl ₱1,000 led Canpro ₱900.
        $this->order('CRD LHEI', 3, 900, 'Canpro');
        foreach ([3, 3, 3, 3, 5] as $status) {
            $this->order('CRD LHEI', $status, 100, 'Sinuxyl');
        }
        $this->order('CRD LHEI', 3, 1000, 'Sinuxyl', '2026-09-25');
        $this->order('CRD LHEI', 3, 900, 'Canpro', '2026-09-26');

        $this->actingAs($this->owner)->get(route('product-performance.index', ['period' => 'month']))
            ->assertOk()
            ->assertSeeInOrder(['RTS', '16.7%', '1 returned of 6 delivered or returned'])
            ->assertSeeInOrder(['Ranking', '1', '▲ 1', 'Canpro', '0 of 1', '0.0%', '2', '▼ 1', 'Sinuxyl', '1 of 5', '20.0%'])
            ->assertSee('Canpro moved up to #1')
            ->assertSee('Sinuxyl dropped to #2')
            ->assertSee('Sinuxyl has the highest RTS: 20% (1/5)')
            ->assertSee('data-pp-graph="rts"', false)
            ->assertDontSee('Retention');
    }

    public function test_crd_tab_leaves_out_the_tsd_products_and_keeps_the_testing_ones(): void
    {
        Product::create(['name' => 'Haplunas', 'srp' => 500, 'is_tsd' => true]);
        Product::create(['name' => 'Lumicare', 'srp' => 700, 'is_testing' => true]);
        // A TSD add-on on a CRD order: only the CRD product's share of the sale.
        $this->itemsOrder([['qty' => 1, 'name' => 'Canpro', 'price' => 900], ['qty' => 1, 'name' => 'Haplunas', 'price' => 350]]);
        $this->order('CRD LHEI', 3, 500, 'Haplunas');
        $this->order('CRD LHEI', 3, 700, 'Lumicare');

        $this->actingAs($this->owner)->get(route('product-performance.crd', ['period' => 'month']))
            ->assertOk()
            ->assertSeeInOrder(['Gross sales per day', '₱1,600', '2 orders'])
            ->assertSee('Canpro leads with ₱900')
            ->assertSee('Lumicare')
            ->assertDontSee('Haplunas');
    }

    public function test_tsd_tab_counts_only_the_tsd_products_of_an_order(): void
    {
        Product::create(['name' => 'Haplunas', 'srp' => 500, 'is_tsd' => true]);
        // A TSD add-on on a CRD order: only its own share of the sale, and the order counts once here.
        $this->itemsOrder([['qty' => 1, 'name' => 'Canpro', 'price' => 900], ['qty' => 1, 'name' => 'Haplunas', 'price' => 350]]);
        $this->order('CRD LHEI', 3, 500, 'Haplunas');
        $this->order('CRD LHEI', 3, 7000, 'Sinuxyl');

        $this->actingAs($this->owner)->get(route('product-performance.tsd', ['period' => 'month']))
            ->assertOk()
            ->assertSeeInOrder(['Gross sales per day', '₱850', '2 orders'])
            ->assertSee('Haplunas leads with ₱850')
            ->assertDontSee('Canpro')
            ->assertDontSee('Sinuxyl');
    }

    public function test_testing_tab_counts_only_the_testing_products(): void
    {
        Product::create(['name' => 'Lumicare', 'srp' => 700, 'is_testing' => true]);
        $this->order('CRD LHEI', 3, 700, 'Lumicare');
        $this->order('CRD LHEI', 3, 900, 'Canpro');

        $this->actingAs($this->owner)->get(route('product-performance.testing', ['period' => 'month']))
            ->assertOk()
            ->assertSeeInOrder(['Gross sales per day', '₱700', '1 order'])
            ->assertSee('Lumicare leads with ₱700')
            ->assertDontSee('Canpro');
    }

    public function test_products_tab_still_counts_every_listed_product(): void
    {
        Product::create(['name' => 'Haplunas', 'srp' => 500, 'is_tsd' => true]);
        Product::create(['name' => 'Lumicare', 'srp' => 700, 'is_testing' => true]);
        $this->order('CRD LHEI', 3, 500, 'Haplunas');
        $this->order('CRD LHEI', 3, 700, 'Lumicare');
        $this->order('CRD LHEI', 3, 900, 'Canpro');

        $this->actingAs($this->owner)->get(route('product-performance.index', ['period' => 'month']))
            ->assertOk()
            ->assertSeeInOrder(['Gross sales per day', '₱2,100', '3 orders']);
    }

    public function test_tab_with_no_orders_says_so(): void
    {
        $this->order('CRD LHEI', 3, 900, 'Canpro');

        $this->actingAs($this->owner)->get(route('product-performance.tsd', ['period' => 'month']))
            ->assertOk()
            ->assertSee('No TSD product orders in these dates.')
            ->assertDontSee('Canpro');
    }

    public function test_product_tabs_keep_the_dates_shown_and_stay_on_the_tab(): void
    {
        $dates = ['period' => 'range', 'from' => '2026-10-01', 'to' => '2026-10-05'];
        $testingUrl = route('product-performance.testing', $dates);

        $this->actingAs($this->owner)->get(route('product-performance.tsd', $dates))
            ->assertOk()
            ->assertSee('href="'.e($testingUrl).'"', false)
            ->assertSee('action="'.route('product-performance.tsd').'"', false)
            ->assertSee('href="'.route('product-performance.segmentation-summary').'"', false);
    }

    public function test_trend_groups_sales_orders_and_rts_by_day_week_and_month_from_the_first_order(): void
    {
        $this->order('CRD LHEI', 3, 350, 'Sinuxyl', '2026-09-28');
        $this->order('CRD LHEI', 3, 100, 'Sinuxyl', '2026-10-05');
        $this->order('CRD LHEI', 5, 200, 'Canpro', '2026-10-07');

        $response = $this->actingAs($this->owner)->getJson(route('product-performance.trend', ['tab' => 'index', 'to' => '2026-10-10']));

        $response->assertOk()
            ->assertJsonPath('month.labels', ['Sep 2026', 'Oct 2026'])
            ->assertJsonPath('month.total.sales', [350, 300])
            ->assertJsonPath('month.total.orders', [1, 2])
            ->assertJsonPath('month.total.rts', [0, 50])
            ->assertJsonPath('month.partial', true)
            ->assertJsonPath('week.labels', ['Week of Sep 28', 'Week of Oct 5'])
            ->assertJsonPath('week.total.sales', [350, 300])
            ->assertJsonPath('week.partial', true)
            ->assertJsonPath('day.labels.0', 'Sep 28')
            ->assertJsonCount(13, 'day.labels')
            ->assertJsonPath('day.total.sales.7', 100)
            ->assertJsonPath('day.total.rts.1', null)
            ->assertJsonPath('month.products.0.name', 'Sinuxyl')
            ->assertJsonPath('month.products.0.sales', [350, 100])
            ->assertJsonPath('month.products.1.sales', [0, 200]);
    }

    public function test_trend_counts_only_the_tabs_products(): void
    {
        Product::create(['name' => 'Haplunas', 'srp' => 500, 'is_tsd' => true]);
        Product::create(['name' => 'Lumicare', 'srp' => 700, 'is_testing' => true]);
        $this->order('CRD LHEI', 3, 500, 'Haplunas');
        $this->order('CRD LHEI', 3, 700, 'Lumicare');
        $this->order('CRD LHEI', 3, 900, 'Canpro');
        $sales = fn (string $tab) => $this->actingAs($this->owner)->getJson(route('product-performance.trend', ['tab' => $tab, 'to' => '2026-10-10']))->json('month.total.sales');

        $this->assertEquals([2100], $sales('index'));
        $this->assertEquals([1600], $sales('crd'));
        $this->assertEquals([500], $sales('tsd'));
        $this->assertEquals([700], $sales('testing'));
    }

    public function test_trend_stops_at_the_day_picked_and_a_finished_month_is_not_partial(): void
    {
        $this->order('CRD LHEI', 3, 350, 'Sinuxyl', '2026-09-28');
        $this->order('CRD LHEI', 3, 100, 'Sinuxyl', '2026-10-05');

        $this->actingAs($this->owner)->getJson(route('product-performance.trend', ['tab' => 'index', 'to' => '2026-09-30']))
            ->assertJsonPath('month.labels', ['Sep 2026'])
            ->assertJsonPath('month.total.sales', [350])
            ->assertJsonPath('month.partial', false);
    }

    public function test_trend_with_no_orders_is_empty_for_the_page_to_say_so(): void
    {
        $this->actingAs($this->owner)->getJson(route('product-performance.trend', ['tab' => 'tsd', 'to' => '2026-10-10']))
            ->assertOk()
            ->assertJsonPath('month.labels', [])
            ->assertJsonPath('week.products', []);
    }

    public function test_trend_refuses_a_cra_and_an_unknown_tab_or_a_future_day(): void
    {
        $cra = User::create(['email' => 'cra@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);

        $this->actingAs($cra)->getJson(route('product-performance.trend', ['tab' => 'index', 'to' => '2026-10-10']))->assertForbidden();
        $this->actingAs($this->owner)->getJson(route('product-performance.trend', ['tab' => 'other', 'to' => '2026-10-10']))->assertJsonValidationErrors('tab');
        $this->actingAs($this->owner)->getJson(route('product-performance.trend', ['tab' => 'index', 'to' => '2026-10-11']))->assertJsonValidationErrors('to');
    }

    public function test_each_tab_asks_for_its_own_graph_up_to_the_last_day_shown(): void
    {
        $this->actingAs($this->owner)->get(route('product-performance.crd', ['period' => 'month', 'month' => '2026-09']))
            ->assertOk()
            ->assertSee('data-pp-trend="'.e(route('product-performance.trend', ['tab' => 'crd', 'to' => '2026-09-30'])).'"', false);
    }

    public function test_cra_without_the_permission_is_refused(): void
    {
        $cra = User::create(['email' => 'cra@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);

        $this->actingAs($cra)->get(route('product-performance.index'))->assertForbidden();
        $this->actingAs($cra)->get(route('product-performance.crd'))->assertForbidden();
        $this->actingAs($cra)->get(route('product-performance.tsd'))->assertForbidden();
        $this->actingAs($cra)->get(route('product-performance.testing'))->assertForbidden();
    }
}
