<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\SalesGoals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesGoalsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

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
}
