<?php

namespace Tests\Feature;

use App\Models\ExclusiveLeadLimit;
use App\Models\Role;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Support\ExclusiveLimits;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExclusiveLeadLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $slug, string $name, ?string $exclusiveFrom = null): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => 'P '.$name,
            'role_id' => Role::firstWhere('slug', $slug)->id, 'is_active' => true, 'exclusive_from' => $exclusiveFrom,
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function limits(string $from, int $daily = 270, int $month = 7000, int $orders = 54): array
    {
        return ['effective_from' => $from, 'daily_leads' => $daily, 'month_target' => $month, 'orders_per_day' => $orders];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', 'Asia/Manila'));
        // Team Lead is a role made on the live site, with what the migration grants it.
        Role::create(['slug' => Role::CRA_TEAM_LEAD, 'name' => 'CRA Team Lead', 'permissions' => ['exclusive_leads.view', 'exclusive_leads.view_all', 'exclusive_leads.limits']]);
        config([
            'exclusive_leads.quota_overrides' => [],
            'exclusive_leads.daily_quota' => 250,
            'exclusive_leads.month_target' => 7000,
            'exclusive_leads.confirmed_orders_per_day' => 54,
        ]);
    }

    public function test_a_change_holds_from_its_date_and_later_months_keep_it_until_a_new_one(): void
    {
        $this->actingAs($this->user(Role::CRA_SUPERVISOR, 'Lead'))
            ->post(route('settings.exclusive-lead-limits.store'), $this->limits('2026-11-15'))
            ->assertRedirect()
            ->assertSessionHas('status');
        ExclusiveLeadLimit::factory()->create($this->limits('2027-01-01', 300, 8000, 60));

        $on = fn (string $day) => ExclusiveLimits::on(CarbonImmutable::parse($day))['daily_leads'];

        $this->assertSame(250, $on('2026-11-14'));
        $this->assertSame(270, $on('2026-11-15'));
        $this->assertSame(270, $on('2026-11-30'));
        $this->assertSame(270, $on('2026-12-31'));
        $this->assertSame(300, $on('2027-01-01'));
    }

    public function test_the_lists_size_goes_by_the_real_day_paired_with_the_lead_day(): void
    {
        WorkingDate::startTomorrow(CarbonImmutable::parse('2026-10-12'));
        $this->travel(1)->days();
        $lag = WorkingDate::lagDays();
        $this->assertGreaterThan(0, $lag);
        ExclusiveLeadLimit::factory()->create($this->limits('2026-11-15'));
        $startingLeadDay = CarbonImmutable::parse('2026-11-15')->subDays($lag);

        $this->assertSame(270, ExclusiveLeads::quotaFor($startingLeadDay));
        $this->assertSame(250, ExclusiveLeads::quotaFor($startingLeadDay->subDay()));
    }

    public function test_the_month_goals_follow_each_days_limits(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-11-01');
        ExclusiveLeadLimit::factory()->create($this->limits('2026-11-16', 270, 7500, 60));

        $month = app(ExclusiveLeads::class)->month($joanna, CarbonImmutable::parse('2026-11-01'));
        $days = collect($month['days'])->keyBy('date');

        $this->assertSame(250, $days['2026-11-15']['quota']);
        $this->assertSame(270, $days['2026-11-16']['quota']);
        $this->assertSame(54, $days['2026-11-15']['confirmed_goal']);
        $this->assertSame(60, $days['2026-11-16']['confirmed_goal']);
        $this->assertSame(7500, $month['targets']['leads']);
        $this->assertSame(1500, $month['targets']['repeat']);
        $this->assertSame(15 * 54 + 15 * 60, $month['targets']['confirmed_orders']);
        $this->assertTrue($month['limits']['orders_rate_changed']);
    }

    public function test_limits_start_today_or_later_and_saving_a_date_again_replaces_it(): void
    {
        $lead = $this->user(Role::CRA_SUPERVISOR, 'Lead');

        $this->actingAs($lead)->post(route('settings.exclusive-lead-limits.store'), $this->limits('2026-11-09'))
            ->assertSessionHasErrors('effective_from');
        $this->assertSame(0, ExclusiveLeadLimit::count());

        $this->actingAs($lead)->post(route('settings.exclusive-lead-limits.store'), $this->limits('2026-11-10'))->assertRedirect();
        $this->actingAs($lead)->post(route('settings.exclusive-lead-limits.store'), $this->limits('2026-11-10', 280))->assertRedirect();

        $this->assertSame(1, ExclusiveLeadLimit::count());
        $this->assertSame(280, ExclusiveLeadLimit::first()->daily_leads);
        $this->assertSame($lead->id, ExclusiveLeadLimit::first()->set_by);
    }

    public function test_only_limits_that_have_not_started_can_be_removed(): void
    {
        $lead = $this->user(Role::CRA_SUPERVISOR, 'Lead');
        $started = ExclusiveLeadLimit::factory()->create($this->limits('2026-11-10'));
        $upcoming = ExclusiveLeadLimit::factory()->create($this->limits('2026-12-01'));

        $this->actingAs($lead)->delete(route('settings.exclusive-lead-limits.destroy', $started))->assertStatus(422);
        $this->actingAs($lead)->delete(route('settings.exclusive-lead-limits.destroy', $upcoming))->assertRedirect();

        $this->assertModelExists($started);
        $this->assertModelMissing($upcoming);
    }

    public function test_supervisors_and_team_leads_set_limits_and_cras_cannot(): void
    {
        $this->user(Role::CRA, 'Joanna', '2026-11-01');

        foreach ([Role::CRA_SUPERVISOR => 'Lead', Role::CRA_TEAM_LEAD => 'Team'] as $slug => $name) {
            $user = $this->user($slug, $name);
            $this->actingAs($user)->get(route('exclusive-leads.index'))->assertOk()->assertSee('Save limits');
            $this->actingAs($user)->post(route('settings.exclusive-lead-limits.store'), $this->limits('2026-11-20'))->assertRedirect();
        }

        $cra = User::firstWhere('display_name', 'Joanna');
        $this->actingAs($cra)->get(route('exclusive-leads.index'))->assertOk()->assertDontSee('Save limits');
        $this->actingAs($cra)->post(route('settings.exclusive-lead-limits.store'), $this->limits('2026-11-21'))->assertForbidden();
        $this->assertFalse(ExclusiveLeadLimit::whereDate('effective_from', '2026-11-21')->exists());
    }

    public function test_a_team_lead_sees_the_limits_but_not_the_transfer_lists(): void
    {
        $this->user(Role::CRA, 'Joanna', '2026-11-01');

        $this->actingAs($this->user(Role::CRA_TEAM_LEAD, 'Team'))->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertSee('Save limits')
            ->assertDontSee('Move back');
    }
}
