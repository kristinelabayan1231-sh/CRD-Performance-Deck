<?php

namespace Tests\Feature;

use App\Models\DutyLogin;
use App\Models\DutySchedule;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CraScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $lhea;

    private User $regina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00', 'Asia/Manila'));
        $this->supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $this->lhea = $this->cra('Lhea');
        $this->regina = $this->cra('Regina');
    }

    private function cra(string $name): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    public function test_supervisor_sees_each_days_planned_days_off_missed_log_ins_and_log_ins(): void
    {
        // Oct 8: Lhea logged in, Regina didn't (missed). Oct 9: Regina's planned rest day, Lhea not logged in yet.
        DutyLogin::factory()->on('2026-10-08')->create(['user_id' => $this->lhea->id]);
        DutySchedule::factory()->rest()->on('2026-10-09')->create(['user_id' => $this->regina->id]);

        $this->actingAs($this->supervisor)->get(route('cra-schedule.index'))
            ->assertSee('title="Regina: Absent / Leave (no log-in)"', false)
            ->assertSee('title="Regina: Rest Day"', false)
            ->assertSeeText('1 on duty')
            ->assertSeeTextInOrder(['Friday, Oct 9', 'Lhea', 'Not logged in', 'Regina', 'Rest Day', 'Save Oct 9'])
            ->assertViewHas('totals', fn ($totals) => $totals[$this->regina->id] === ['rest' => 1, 'leave' => 1, 'duty' => 0]
                && $totals[$this->lhea->id] === ['rest' => 0, 'leave' => 0, 'duty' => 1]);
    }

    public function test_supervisor_changes_a_past_day_for_every_cra(): void
    {
        DutySchedule::factory()->rest()->on('2026-10-05')->create(['user_id' => $this->regina->id]);

        $this->actingAs($this->supervisor)->put(route('cra-schedule.update'), [
            'date' => '2026-10-05',
            'types' => [$this->lhea->id => DutySchedule::LEAVE, $this->regina->id => ''],
        ])->assertRedirect(route('cra-schedule.index', ['month' => '2026-10', 'day' => '2026-10-05']))
            ->assertSessionHas('status', 'Schedule for Mon, Oct 5 saved.');

        $this->assertSame([$this->lhea->id => DutySchedule::LEAVE], DutySchedule::pluck('type', 'user_id')->all());
    }

    public function test_viewing_without_on_duty_manage_shows_no_changes_and_cannot_save(): void
    {
        $viewer = User::create(['email' => 'lead@example.com', 'is_active' => true, 'role_id' => Role::create([
            'slug' => 'viewer', 'name' => 'Viewer', 'permissions' => ['on_duty.view'],
        ])->id]);

        $this->actingAs($viewer)->get(route('cra-schedule.index'))->assertSeeText("Each CRA's day.")->assertDontSeeText('Save Oct 9');
        $this->actingAs($viewer)->put(route('cra-schedule.update'), ['date' => '2026-10-09', 'types' => [$this->lhea->id => DutySchedule::REST]])->assertForbidden();
        $this->actingAs($this->lhea)->get(route('cra-schedule.index'))->assertForbidden();

        $this->assertSame(0, DutySchedule::count());
    }

    public function test_the_sidebar_lists_who_is_off_today(): void
    {
        DutySchedule::factory()->rest()->create(['user_id' => $this->regina->id]);

        $this->actingAs($this->supervisor)->get(route('cra-schedule.index'))
            ->assertSeeTextInOrder(['No CRA has logged in yet today.', 'Off today', 'Regina', 'Rest Day', 'CRA Schedule']);
    }
}
