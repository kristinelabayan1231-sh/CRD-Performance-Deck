<?php

namespace Tests\Feature;

use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerChurn;
use App\Services\SalesGoalProgress;
use App\Support\DashboardRange;
use App\Support\SalesGoals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesGoalsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 18:00', 'Asia/Manila'));
        $this->withoutDefer();
        Http::fake(fn () => Http::response(['success' => true, 'count' => 0, 'stock_outs' => [], 'data' => [], 'users_engagements' => []]));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
    }

    private function cra(string $name, string $pancake, ?float $goal = null): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake, 'daily_sales_goal' => $goal,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    private function sale(string $seller, string $day, float $total, ?string $type = PancakeOrder::SEGMENTATION, int $status = 2): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => (string) (5000 + $this->seq), 'ordered_on' => $day, 'seller_name' => $seller,
            'status' => $status, 'total_price' => $total, 'conversion_type' => $type,
        ]);
    }

    public function test_goals_default_to_77k_daily_and_1m_monthly(): void
    {
        $this->assertSame(77000.0, SalesGoals::craDaily());
        $this->assertSame(1000000.0, SalesGoals::crdMonthly());
    }

    public function test_manager_sets_the_goals_and_a_cras_own_daily_goal(): void
    {
        $lhea = $this->cra('Lhea', 'CRD LHEI');
        $regina = $this->cra('Regina', 'CRD REJ VERGARA', goal: 50000);
        $supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);

        $this->actingAs($supervisor)->get(route('settings.sales-goals.index'))
            ->assertOk()
            ->assertSee('CRD monthly goal')
            ->assertSee('Lhea');

        $this->actingAs($supervisor)->put(route('settings.sales-goals.update'), [
            'cra_daily' => 80000,
            'crd_monthly' => 1200000,
            // A new own goal for Lhea; blank clears Regina's, so she uses the general goal again.
            'cra_goals' => [$lhea->id => 90000, $regina->id => ''],
        ])->assertRedirect()->assertSessionHas('status', 'Sales goals saved.');

        $this->assertSame(80000.0, SalesGoals::craDaily());
        $this->assertSame(1200000.0, SalesGoals::crdMonthly());
        $this->assertSame(90000.0, SalesGoals::dailyFor($lhea->fresh()));
        $this->assertSame(80000.0, SalesGoals::dailyFor($regina->fresh()));
    }

    public function test_manager_sets_and_clears_the_net_income_goal(): void
    {
        $this->assertNull(SalesGoals::netIncomeMonthly());

        $this->actingAs($this->owner)->put(route('settings.sales-goals.update'), ['cra_daily' => 77000, 'crd_monthly' => 1000000, 'net_income_monthly' => 450000])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(450000.0, SalesGoals::netIncomeMonthly());
        $this->actingAs($this->owner)->get(route('settings.sales-goals.index'))->assertOk()->assertSee('Net income goal')->assertSee('value="450000"', false);

        // Blank = no net income goal.
        $this->actingAs($this->owner)->put(route('settings.sales-goals.update'), ['cra_daily' => 77000, 'crd_monthly' => 1000000, 'net_income_monthly' => '']);
        $this->assertNull(SalesGoals::netIncomeMonthly());

        $this->actingAs($this->owner)->put(route('settings.sales-goals.update'), ['cra_daily' => 77000, 'crd_monthly' => 1000000, 'net_income_monthly' => -1])
            ->assertSessionHasErrors('net_income_monthly');
    }

    public function test_goals_must_be_amounts(): void
    {
        $this->actingAs($this->owner)->put(route('settings.sales-goals.update'), ['cra_daily' => 'lots', 'crd_monthly' => -5])
            ->assertSessionHasErrors(['cra_daily', 'crd_monthly']);
    }

    public function test_cras_cannot_change_the_goals(): void
    {
        $lhea = $this->cra('Lhea', 'CRD LHEI');

        $this->actingAs($lhea)->get(route('settings.sales-goals.index'))->assertForbidden();
        $this->actingAs($lhea)->put(route('settings.sales-goals.update'), ['cra_daily' => 1, 'crd_monthly' => 1])->assertForbidden();
    }

    public function test_each_cras_top_product_sales_show_per_cra(): void
    {
        $regina = $this->cra('Regina', 'CRD REJ VERGARA');
        $this->cra('Lhea', 'CRD LHEI');
        Product::create(['name' => 'CanPro', 'srp' => 1000]);
        Product::create(['name' => 'Sinuxyl', 'keywords' => 'Sinux', 'srp' => 500]);
        $order = fn (string $id, float $total, array $items, ?string $type = PancakeOrder::SEGMENTATION, int $status = 2) => PancakeOrder::create([
            'pancake_order_id' => $id, 'ordered_on' => '2026-10-05', 'seller_name' => 'CRD REJ VERGARA', 'status' => $status,
            'total_price' => $total, 'conversion_type' => $type, 'items' => $items,
        ]);
        $order('T1', 15000, [['name' => 'CANPRO 60s', 'qty' => 1]], PancakeOrder::BROADCAST);
        // CanPro ×1 (₱1,000 SRP) and Sinux Spray ×2 (₱500): split 50/50.
        $order('T2', 10000, [['name' => 'CanPro', 'qty' => 1], ['name' => 'Sinux Spray', 'qty' => 2]]);
        $order('T3', 9000, [['name' => 'Sinuxyl', 'qty' => 1]]);
        $order('T4', 3000, [['name' => 'CanPro', 'qty' => 1]], status: 6);    // canceled: still in gross sales
        $order('T5', 50000, [['name' => 'Sinuxyl', 'qty' => 5]], type: null); // not CRD-tagged

        $progress = app(SalesGoalProgress::class)->for(collect([$regina]), DashboardRange::fromFilters([], CarbonImmutable::parse('2026-10-10')));
        $this->assertSame(['name' => 'CanPro', 'amount' => 23000.0], $progress['cras']->first()['top_product']);

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Top product sales', 'Regina', 'CanPro', '₱23,000'])
            ->assertSee('No sales yet');
    }

    public function test_progress_counts_tagged_sales_against_the_goals(): void
    {
        $lhea = $this->cra('Lhea', 'CRD LHEI');
        $regina = $this->cra('Regina', 'CRD REJ VERGARA', goal: 50000);

        $this->sale('CRD LHEI', '2026-10-10', 38500, PancakeOrder::BROADCAST);
        // Canceled: in gross sales (every status), not a confirmed order.
        $this->sale('CRD LHEI', '2026-10-10', 38500, status: 6);
        $this->sale('CRD REJ VERGARA', '2026-10-10', 25000);
        $this->sale('CRD REJ VERGARA', '2026-10-03', 100000);
        // Not sales: untagged, and last month.
        $this->sale('CRD LHEI', '2026-10-10', 9999, type: null);
        $this->sale('CRD LHEI', '2026-09-30', 9999);

        // Month to date (Oct 1–10): the whole monthly goal, paced by day 10 of 31.
        $goals = app(SalesGoalProgress::class)->for(collect([$lhea, $regina]), DashboardRange::fromFilters([], CarbonImmutable::parse('2026-10-10')));

        $this->assertEqualsWithDelta(202000.0, $goals['month']['sales'], 0.001);
        $this->assertEqualsWithDelta(0.202, $goals['month']['progress'], 1e-9);
        $this->assertEqualsWithDelta(10 / 31, $goals['month']['pace'], 1e-9);
        $this->assertEqualsWithDelta(798000.0, $goals['month']['remaining'], 0.001);
        $this->assertSame([3, 202000.0], [$goals['team']['orders'], (float) $goals['team']['gross']]);

        // Per CRA over the 10 days: Lhea ₱77k of 10 × ₱77k; Regina ₱125k of 10 × her own ₱50k.
        $byName = $goals['cras']->keyBy(fn ($row) => $row['cra']->display_name);
        $this->assertEqualsWithDelta(0.1, $byName['Lhea']['progress'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $byName['Regina']['progress'], 1e-9);
        $this->assertTrue($byName['Regina']['own_goal']);

        // A one-day range: the monthly goal prorated to 1 day of 31; per CRA one day's goal.
        $day = app(SalesGoalProgress::class)->for(collect([$lhea, $regina]), DashboardRange::fromFilters(['from' => '2026-10-10', 'to' => '2026-10-10'], CarbonImmutable::parse('2026-10-10')));
        $this->assertTrue($day['month']['prorated']);
        $this->assertNull($day['month']['pace']);
        $this->assertEqualsWithDelta(1000000 / 31, $day['month']['goal'], 0.001);
        $this->assertEqualsWithDelta(102000.0, $day['month']['sales'], 0.001);
        $byName = $day['cras']->keyBy(fn ($row) => $row['cra']->display_name);
        $this->assertEqualsWithDelta(1.0, $byName['Lhea']['progress'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $byName['Regina']['progress'], 1e-9);
    }

    public function test_dashboard_has_the_crd_board_with_today_and_the_two_days_before(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('CRD Board')
            ->assertDontSee('Welcome,')
            ->assertSee('CUSTOMER RETENTION DEPARTMENT [CRD]')
            ->assertSeeInOrder(['OCTOBER 8, 2026', 'OCTOBER 9, 2026', 'OCTOBER 10, 2026', 'TOP SELLER'])
            ->assertSee('CRD mascot waving');
    }

    public function test_dashboard_shows_goal_progress_and_a_cra_sees_only_their_own_daily_goal(): void
    {
        $lhea = $this->cra('Lhea', 'CRD LHEI');
        $this->cra('Regina', 'CRD REJ VERGARA');
        $this->sale('CRD LHEI', '2026-10-10', 38500);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('CRD monthly goal (Gross Sales) · October 2026')
            ->assertSeeTextInOrder(['₱38,500', 'Target ₱1,000,000'])
            ->assertSeeTextInOrder(['Total confirmed orders', '1', 'Conversion rate', 'AOV', '₱38,500', 'Churn rate'])
            ->assertSee(route('conversion.orders'))
            ->assertSee('Goal &amp; conversion per CRA', false)
            ->assertDontSee('accounts')
            // Month to date, Oct 1–10: sales still needed per CRA, Lhea 10 × 77k − 38.5k; Regina hasn't sold yet.
            ->assertSeeTextInOrder(['Lhea', '₱731.5k left', 'Regina', '₱770k left']);

        // One day picked: one day's goal.
        $this->actingAs($this->owner)->get(route('dashboard', ['from' => '2026-10-10', 'to' => '2026-10-10']))
            ->assertOk()
            ->assertSeeTextInOrder(['Lhea', '₱38.5k left', 'Regina', '₱77k left']);

        $this->actingAs($lhea)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your confirmed orders')
            ->assertViewHas('results', fn ($results) => $results['cras']->pluck('cra.id')->all() === [$lhea->id]);
    }

    public function test_confirmed_orders_page_lists_the_tagged_orders_and_a_cra_sees_their_own(): void
    {
        $lhea = $this->cra('Lhea', 'CRD LHEI');
        $this->cra('Regina', 'CRD REJ VERGARA');
        $this->sale('CRD LHEI', '2026-10-10', 1000, PancakeOrder::BROADCAST);
        $this->sale('CRD LHEI', '2026-10-09', 3000);
        $this->sale('CRD REJ VERGARA', '2026-10-08', 2000);
        // Canceled: in gross sales, not listed or counted as a confirmed order.
        $this->sale('CRD LHEI', '2026-10-10', 600, status: 6);
        // Not confirmed: untagged, last month.
        $this->sale('CRD LHEI', '2026-10-10', 9999, type: null);
        $this->sale('CRD LHEI', '2026-09-30', 9999);

        $this->actingAs($this->owner)->get(route('conversion.orders'))
            ->assertOk()
            ->assertSeeTextInOrder(['Confirmed orders', '3', 'Gross sales', '₱6,600.00', 'AOV', '₱2,200.00'])
            ->assertSeeTextInOrder(['5001', 'Lhea', 'Broadcast', '₱1,000.00', '5002', 'Segmentation', '5003', 'Regina'])
            ->assertDontSee('₱600.00')->assertDontSee('₱9,999.00');

        // The dashboard's dates carry over: Oct 9 only.
        $this->actingAs($this->owner)->get(route('conversion.orders', ['from' => '2026-10-09', 'to' => '2026-10-09']))
            ->assertSee('5002')->assertDontSee('5001')->assertDontSee('5003');

        $this->actingAs($lhea)->get(route('conversion.orders'))
            ->assertOk()->assertSee('5001')->assertSee('5002')->assertDontSee('5003');
    }

    public function test_churn_counts_crd_fsd_and_overall_customers_with_no_reorder_within_30_days_of_running_out(): void
    {
        // A listed product with no consumption days, so each delivery's own days apply.
        Product::create(['name' => 'Crdol']);
        $delivered = function (string $phone, string $day, int $qty, int $days) {
            DeliveredOrder::create(['order_id' => uniqid(), 'customer_name' => 'C', 'phone_number' => $phone, 'product_raw' => 'Crdol',
                'qty' => $qty, 'delivered_date' => $day, 'consumption_days_per_unit' => $days, 'source' => DeliveredOrder::SOURCE_SHECOM]);
        };
        // FSD: the logistics delivery, with its qty (if known) from the Pancake delivered orders.
        Product::create(['name' => 'Fsdol', 'srp' => 500, 'consumption_days' => 15]);
        $fsd = function (string $id, string $phone, string $day, ?int $qty) {
            LogisticsOrder::remember([['order_id' => $id, 'team' => LogisticsOrder::TEAM_FSD, 'customer_name' => 'F', 'phone_number' => $phone,
                'product' => 'Fsdol', 'qty' => null, 'delivered_date' => $day]]);
            if ($qty) {
                DeliveredOrder::create(['order_id' => $id, 'customer_name' => 'F', 'phone_number' => $phone, 'product_raw' => 'Fsdol',
                    'qty' => $qty, 'delivered_date' => $day, 'source' => DeliveredOrder::SOURCE_PANCAKE]);
            }
        };
        $ordered = fn (string $phone, string $day, int $status = 3) => PancakeOrder::create([
            'pancake_order_id' => uniqid(), 'ordered_on' => $day, 'phone_key' => $phone, 'status' => $status, 'total_price' => 500,
        ]);

        // CRD: ran out Sep 3–4, so their 30 days to reorder ended Oct 3–4, inside October.
        $delivered('09171111111', '2026-08-20', 1, 15);  // FSD delivered them Aug 22 (below): back
        $delivered('9172222222', '2026-08-25', 1, 10);   // ordered Sep 20: back
        $ordered('9172222222', '2026-09-20');
        $delivered('9174444444', '2026-08-26', 1, 10);   // only a canceled order: lost
        $ordered('9174444444', '2026-09-10', status: 6);
        $delivered('9175555555', '2026-08-20', 1, 15);   // delivered again Sep 25: back
        LogisticsOrder::remember([['order_id' => 'L1', 'team' => 'crd', 'customer_name' => 'E', 'phone_number' => '9175555555',
            'product' => 'Crdol', 'qty' => null, 'delivered_date' => '2026-09-25']]);
        $delivered('9176666666', '2026-08-20', 1, 15);   // came back Oct 5, after the 30 days: lost
        $ordered('9176666666', '2026-10-05');
        // Ran out Sep 25: still inside their 30 days, not counted yet.
        $delivered('9173333333', '2026-09-11', 1, 15);

        // FSD: 2 × 15 days from Jul 20 runs out Aug 18, so the 30 days ended Sep 17: not in October.
        $fsd('F1', '9178888888', '2026-07-20', 2);
        // 1 × 15 days from Aug 20: ended Oct 3.
        $fsd('F2', '9177777777', '2026-08-20', 1);       // no order since: lost
        $fsd('F3', '9179999999', '2026-08-20', 1);       // ordered Sep 1: back
        $ordered('9179999999', '2026-09-01');
        $fsd('F4', '9170000000', '2026-08-20', null);    // qty not known yet: left out
        // On both lists: back for CRD (this delivery came after), lost for FSD; overall counts them once, by this later delivery: lost.
        $fsd('F5', '9171111111', '2026-08-22', 1);

        $churn = app(CustomerChurn::class)->for(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-10'));
        $this->assertSame([
            'crd' => ['customers' => 5, 'lost' => 2, 'rate' => 0.4, 'delivered_months' => ['2026-08' => 5]],
            'fsd' => ['customers' => 3, 'lost' => 2, 'rate' => 2 / 3, 'delivered_months' => ['2026-08' => 3]],
            'all' => ['customers' => 7, 'lost' => 4, 'rate' => 4 / 7, 'delivered_months' => ['2026-08' => 7]],
            'grace_days' => 30,
        ], $churn);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertSeeTextInOrder(['Customer churn', 'Reorder deadline Oct 1–10', 'Overall churn rate', '57.14%', '4 lost of 7',
                'CRD churn rate', '40.00%', '2 lost of 5', 'FSD churn rate', '66.67%', '2 lost of 3', 'View breakdown'])
            ->assertSeeTextInOrder(['Came back in time', '3', '1', '3', 'Delivered in', 'CRD', 'Aug 5', 'FSD', 'Aug 3'])
            ->assertSeeTextInOrder(['How the numbers are worked out', 'Churn rate', 'Customers lost ÷ customers due × 100', '30 days to order again'])
            ->assertSee(route('customers.churn', ['status' => 'lost', 'list' => 'all']))
            ->assertSeeText('See customers');

        // Customer Database → Churn: the same customers one by one. The CRA recorded why one didn't reorder.
        Lead::create(['order_id' => 'lead-1', 'customer_name' => 'C', 'phone_number' => '09174444444', 'product_name' => 'Unlisted product', 'qty' => 1,
            'delivered_date' => '2026-08-26', 'consumption_days' => 10, 'est_out_of_stock_date' => '2026-09-04', 'lead_type' => Lead::TYPE_CRD,
            'status' => 'active', 'feedback' => 'no_budget', 'contact_date' => '2026-09-05', 'notes' => 'Will buy next payday']);

        $this->actingAs($this->owner)->get(route('customers.churn'))->assertOk()
            ->assertViewHas('counts', ['due' => 7, 'back' => 3, 'lost' => 4])
            ->assertSeeText('Ran out → reorder by');
        $this->actingAs($this->owner)->get(route('customers.churn', ['status' => 'back']))->assertOk()
            ->assertSeeTextInOrder(['9175555555', 'CRD', 'Delivery L1', 'Sep 25, 2026']);
        $this->actingAs($this->owner)->get(route('customers.churn', ['status' => 'lost']))->assertOk()
            ->assertSeeTextInOrder(['Last ordered', 'Why no reorder'])
            ->assertSeeTextInOrder(['9174444444', 'Aug 26, 2026', config('segmentation.feedback.no_budget.0'), 'contacted Sep 5, 2026', 'Will buy next payday'])
            ->assertSeeTextInOrder(['9176666666', 'Oct 5, 2026', 'after the deadline', 'No tracker lead']);
        $this->actingAs($this->owner)->get(route('customers.churn', ['status' => 'lost', 'list' => 'crd']))->assertOk()
            ->assertViewHas('counts', ['due' => 5, 'back' => 3, 'lost' => 2]);
    }
}
