<?php

namespace Tests\Feature;

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

    public function test_a_working_date_before_the_transfer_opens_the_transfer_month(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-11-01');
        WorkingDate::startTomorrow(CarbonImmutable::parse('2026-10-08'));

        $this->actingAs($joanna)->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertSee('Month goals · Joanna')
            ->assertSee('November plans 6,900 leads')
            ->assertSee('Sunday, Nov 1');
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
