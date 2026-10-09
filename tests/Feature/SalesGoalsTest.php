<?php

namespace Tests\Feature;

use App\Models\DeliveredOrder;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
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

    public function test_progress_counts_tagged_sales_against_the_goals(): void
    {
        $lhea = $this->cra('Lhea', 'CRD LHEI');
        $regina = $this->cra('Regina', 'CRD REJ VERGARA', goal: 50000);

        $this->sale('CRD LHEI', '2026-10-10', 38500, PancakeOrder::BROADCAST);
        $this->sale('CRD LHEI', '2026-10-10', 38500);
        $this->sale('CRD REJ VERGARA', '2026-10-10', 25000);
        $this->sale('CRD REJ VERGARA', '2026-10-03', 100000);
        // Not sales: untagged, canceled, and last month.
        $this->sale('CRD LHEI', '2026-10-10', 9999, type: null);
        $this->sale('CRD LHEI', '2026-10-10', 9999, status: 6);
        $this->sale('CRD LHEI', '2026-09-30', 9999);

        // Month to date (Oct 1–10): the whole monthly goal, paced by day 10 of 31.
        $goals = app(SalesGoalProgress::class)->for(collect([$lhea, $regina]), DashboardRange::fromFilters([], CarbonImmutable::parse('2026-10-10')));

        $this->assertEqualsWithDelta(202000.0, $goals['month']['sales'], 0.001);
        $this->assertEqualsWithDelta(0.202, $goals['month']['progress'], 1e-9);
        $this->assertEqualsWithDelta(10 / 31, $goals['month']['pace'], 1e-9);
        $this->assertEqualsWithDelta(798000.0, $goals['month']['remaining'], 0.001);
        $this->assertSame([4, 202000.0], [$goals['team']['orders'], (float) $goals['team']['gross']]);

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
            ->assertSee('CRD monthly goal · October 2026')
            ->assertSeeText('₱38,500 gross of ₱1,000,000')
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
        // Not confirmed: untagged, canceled, last month.
        $this->sale('CRD LHEI', '2026-10-10', 9999, type: null);
        $this->sale('CRD LHEI', '2026-10-10', 9999, status: 6);
        $this->sale('CRD LHEI', '2026-09-30', 9999);

        $this->actingAs($this->owner)->get(route('conversion.orders'))
            ->assertOk()
            ->assertSeeTextInOrder(['Confirmed orders', '3', 'Gross sales', '₱6,000.00', 'AOV', '₱2,000.00'])
            ->assertSeeTextInOrder(['5001', 'Lhea', 'Broadcast', '₱1,000.00', '5002', 'Segmentation', '5003', 'Regina'])
            ->assertDontSee('₱9,999.00');

        // The dashboard's dates carry over: Oct 9 only.
        $this->actingAs($this->owner)->get(route('conversion.orders', ['from' => '2026-10-09', 'to' => '2026-10-09']))
            ->assertSee('5002')->assertDontSee('5001')->assertDontSee('5003');

        $this->actingAs($lhea)->get(route('conversion.orders'))
            ->assertOk()->assertSee('5001')->assertSee('5002')->assertDontSee('5003');
    }

    public function test_churn_counts_customers_with_no_reorder_within_30_days_of_running_out(): void
    {
        $delivered = function (string $phone, string $day, int $qty, int $days) {
            DeliveredOrder::create(['order_id' => uniqid(), 'customer_name' => 'C', 'phone_number' => $phone, 'product_raw' => 'Unlisted product',
                'qty' => $qty, 'delivered_date' => $day, 'consumption_days_per_unit' => $days, 'source' => DeliveredOrder::SOURCE_SHECOM]);
        };
        $ordered = fn (string $phone, string $day, int $status = 3) => PancakeOrder::create([
            'pancake_order_id' => uniqid(), 'ordered_on' => $day, 'phone_key' => $phone, 'status' => $status, 'total_price' => 500,
        ]);

        // Ran out Sep 3–4, so their 30 days to reorder ended Oct 3–4, inside October.
        $delivered('09171111111', '2026-08-20', 1, 15);  // no order since: lost
        $delivered('9172222222', '2026-08-25', 1, 10);   // ordered Sep 20: back
        $ordered('9172222222', '2026-09-20');
        $delivered('9174444444', '2026-08-26', 1, 10);   // only a canceled order: lost
        $ordered('9174444444', '2026-09-10', status: 6);
        $delivered('9175555555', '2026-08-20', 1, 15);   // delivered again Sep 25: back
        LogisticsOrder::remember([['order_id' => 'L1', 'team' => 'crd', 'customer_name' => 'E', 'phone_number' => '9175555555',
            'product' => 'X', 'qty' => null, 'delivered_date' => '2026-09-25']]);
        $delivered('9176666666', '2026-08-20', 1, 15);   // came back Oct 5, after the 30 days: lost
        $ordered('9176666666', '2026-10-05');
        // Ran out Sep 25: still inside their 30 days, not counted yet.
        $delivered('9173333333', '2026-09-11', 1, 15);

        $churn = app(CustomerChurn::class)->for(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-10'));
        $this->assertSame(['customers' => 5, 'lost' => 3, 'rate' => 0.6, 'grace_days' => 30], $churn);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertSeeTextInOrder(['Churn rate', '60.00%', '3 lost of 5'])
            ->assertSeeTextInOrder(['How the numbers are worked out', 'Churn rate', 'Customers lost ÷ customers due × 100', '30 days to order again']);
    }
}
