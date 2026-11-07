<?php

namespace Tests\Feature;

use App\Models\DutyLogin;
use App\Models\DutySchedule;
use App\Models\Role;
use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExclusiveLeadPageTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $slug, string $name, ?string $exclusiveFrom = null): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => 'P '.$name,
            'role_id' => Role::firstWhere('slug', $slug)->id, 'is_active' => true, 'exclusive_from' => $exclusiveFrom,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00', 'Asia/Manila'));
        config([
            'exclusive_leads.quota_overrides' => ['2026-11-01' => 100, '2026-11-02' => 100, '2026-11-03' => 100, '2026-11-04' => 100],
            'exclusive_leads.build_from' => '2026-11-06',
        ]);
    }

    public function test_the_page_shows_real_dates_with_the_paired_lead_days_leads(): void
    {
        config(['exclusive_leads' => require config_path('exclusive_leads.php')]);
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        WorkingDate::startTomorrow(CarbonImmutable::parse('2026-10-08'));

        $this->actingAs($joanna)->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertSee('Working date: Oct 7, 2026')
            ->assertSee('Friday, Nov 6')
            ->assertSee('from Oct 7')
            ->assertSee('November plans 6,900 leads: Nov 1–4 ran at 100 leads a day');
    }

    public function test_the_migration_moves_only_the_nov_1_start_to_its_lead_day(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-11-01');
        $regina = $this->user(Role::CRA, 'Regina', '2026-11-03');

        (require database_path('migrations/2026_11_06_000000_move_exclusive_start_to_its_lead_day.php'))->up();

        $this->assertSame('2026-10-02', $joanna->fresh()->exclusive_from->toDateString());
        $this->assertSame('2026-11-03', $regina->fresh()->exclusive_from->toDateString());
    }

    public function test_an_exclusive_cra_sees_their_goals_and_the_month_plan(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-11-01');

        $this->actingAs($joanna)->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertSee('Month goals · Joanna')
            ->assertSee('1,400')
            ->assertSee('₱895')
            ->assertSee('November plans 6,900 leads')
            ->assertDontSee('Settings · Exclusive Lead Tracker');
    }

    public function test_a_transfer_paired_with_a_later_real_day_opens_that_month(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-11-01');
        WorkingDate::startTomorrow(CarbonImmutable::parse('2026-10-08'));

        $this->actingAs($joanna)->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertSee('Month goals · Joanna')
            ->assertSee('Tuesday, Dec 1');
    }

    public function test_a_month_before_the_transfer_opens_the_transfer_month(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-11-01');

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['month' => '2026-10']))
            ->assertOk()
            ->assertSee('November plans 6,900 leads')
            ->assertSee('Friday, Nov 6');
    }

    public function test_the_confirmed_orders_target_reads_per_day_with_the_month_as_their_sum(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-11-01');

        $this->actingAs($joanna)->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertSee('/ 54 today')
            ->assertSee('Month: 0 of 1,620')
            ->assertSee('54 a day × 30 working days')
            ->assertSee('54 confirmed orders a day')
            ->assertDontSee('Month target');

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))
            ->assertOk()
            ->assertSee('/ 54')
            ->assertDontSee('/ 54 today');
    }

    public function test_a_day_off_has_no_confirmed_orders_target(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-11-01');
        DutySchedule::create(['user_id' => $joanna->id, 'date' => '2026-11-05', 'type' => DutySchedule::REST]);

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))
            ->assertOk()
            ->assertSee('Day off, no target · Month: 0 of 1,566')
            ->assertSee('54 a day × 29 working days');
    }

    public function test_an_exclusive_cra_logs_in_for_duty_from_the_page(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-11-01');

        $this->actingAs($joanna)->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertSeeText('Log in for today?')
            ->assertSeeText('My schedule');

        $this->actingAs($joanna)->from(route('exclusive-leads.index'))->post(route('segmentation.on-duty'))
            ->assertRedirect(route('exclusive-leads.index'));

        $this->assertTrue(DutyLogin::where('user_id', $joanna->id)->whereDate('date', '2026-11-06')->exists());
        $this->actingAs($joanna)->get(route('exclusive-leads.index'))->assertOk()->assertDontSeeText('Log in for today?');
    }

    public function test_a_transferred_cra_keeps_todays_log_in_and_is_asked_on_the_page_when_they_have_none(): void
    {
        $supervisor = $this->user(Role::CRA_SUPERVISOR, 'Lead');
        $loggedIn = $this->user(Role::CRA, 'Regina');
        $notLoggedIn = $this->user(Role::CRA, 'Lhea');
        DutyLogin::create(['user_id' => $loggedIn->id, 'date' => '2026-11-06', 'logged_in_at' => now()]);

        foreach ([$loggedIn, $notLoggedIn] as $cra) {
            $this->actingAs($supervisor)->post(route('settings.exclusive-leads.store', $cra))->assertRedirect();
        }

        $this->actingAs($loggedIn->fresh())->get(route('exclusive-leads.index'))->assertOk()->assertDontSeeText('Log in for today?');

        $this->actingAs($notLoggedIn->fresh())->get(route('segmentation.index'))->assertRedirect(route('exclusive-leads.index'));
        $this->actingAs($notLoggedIn->fresh())->get(route('exclusive-leads.index'))->assertOk()->assertSeeText('Log in for today?');
    }

    public function test_a_supervisor_is_not_asked_to_log_in_on_the_page(): void
    {
        $this->user(Role::CRA, 'Joanna', '2026-11-01');

        $this->actingAs($this->user(Role::CRA_SUPERVISOR, 'Lead'))->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertDontSee('on-duty-dialog')
            ->assertDontSeeText('My schedule');
    }

    public function test_a_cra_not_on_the_page_is_pointed_to_the_segmentation_tracker(): void
    {
        $this->actingAs($this->user(Role::CRA, 'Regina'))->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertSee('You are not on the Exclusive Lead Tracker.');
    }

    public function test_a_supervisor_sees_settings_with_the_cras_they_can_move(): void
    {
        $this->user(Role::CRA, 'Joanna', '2026-11-01');
        $this->user(Role::CRA, 'Regina');

        $this->actingAs($this->user(Role::CRA_SUPERVISOR, 'Lead'))->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertSee('Settings · Exclusive Lead Tracker')
            ->assertSee('Move back')
            ->assertSee('Move to Exclusive');
    }
}
