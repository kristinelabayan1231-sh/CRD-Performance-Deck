<?php

namespace Tests\Feature;

use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesGoalProgress;
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

        $goals = app(SalesGoalProgress::class)->for(collect([$lhea, $regina]), CarbonImmutable::parse('2026-10-10'));

        $this->assertEqualsWithDelta(202000.0, $goals['month']['sales'], 0.001);
        $this->assertEqualsWithDelta(0.202, $goals['month']['progress'], 1e-9);
        $this->assertEqualsWithDelta(10 / 31, $goals['month']['pace'], 1e-9);
        $this->assertEqualsWithDelta(798000.0, $goals['month']['remaining'], 0.001);

        $byName = $goals['cras']->keyBy(fn ($row) => $row['cra']->display_name);
        // Lhea hits the general ₱77,000; Regina is measured against her own ₱50,000.
        $this->assertEqualsWithDelta(1.0, $byName['Lhea']['progress'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $byName['Regina']['progress'], 1e-9);
        $this->assertTrue($byName['Regina']['own_goal']);
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
            ->assertSeeText('₱38,500 of ₱1,000,000')
            ->assertSee('Total conv % per CRA')
            ->assertDontSee('accounts')
            ->assertSee('Regina');

        $this->actingAs($lhea)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('50.0%')
            ->assertViewHas('salesGoals', fn ($goals) => $goals['cras']->pluck('cra.id')->all() === [$lhea->id]);
    }
}
