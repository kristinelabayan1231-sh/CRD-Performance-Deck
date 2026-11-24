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
            ->assertSee('title="Regina: Absent (no log-in)"', false)
            ->assertSee('title="Regina: Rest Day"', false)
            ->assertSeeText('1 on duty')
            ->assertSeeTextInOrder(['Friday, Oct 9', 'Lhea', 'Not logged in', 'Regina', 'Rest Day', 'Save Oct 9'])
            ->assertViewHas('totals', fn ($totals) => $totals[$this->regina->id] === ['rest' => 1, 'vacation' => 0, 'sick' => 0, 'personal' => 0, 'emergency' => 0, 'leave' => 1, 'duty' => 0]
                && $totals[$this->lhea->id] === ['rest' => 0, 'vacation' => 0, 'sick' => 0, 'personal' => 0, 'emergency' => 0, 'leave' => 0, 'duty' => 1]);
    }

    public function test_each_leave_shows_by_its_own_name_and_counts_on_its_own(): void
    {
        DutySchedule::factory()->on('2026-10-12')->create(['user_id' => $this->lhea->id, 'type' => DutySchedule::SICK]);
        DutySchedule::factory()->on('2026-10-13')->create(['user_id' => $this->lhea->id, 'type' => DutySchedule::EMERGENCY]);

        $this->actingAs($this->supervisor)->get(route('cra-schedule.index', ['day' => '2026-10-12']))
            ->assertSee('title="Lhea: Sick Leave"', false)
            ->assertSee('title="Lhea: Emergency Leave"', false)
            ->assertSeeTextInOrder(['Monday, Oct 12', 'Lhea', 'Sick Leave'])
            ->assertViewHas('totals', fn ($totals) => $totals[$this->lhea->id]['sick'] === 1 && $totals[$this->lhea->id]['emergency'] === 1);
    }

    public function test_the_floating_schedule_shows_each_cras_week_and_month(): void
    {
        // Thu Oct 8: Lhea logged in, Regina didn't. Mon Oct 12: Regina's sick leave (next week).
        DutyLogin::factory()->on('2026-10-08')->create(['user_id' => $this->lhea->id]);
        DutySchedule::factory()->on('2026-10-12')->create(['user_id' => $this->regina->id, 'type' => DutySchedule::SICK]);

        $this->actingAs($this->supervisor)->get(route('cra-schedule.peek'))
            ->assertOk()
            ->assertSeeText('Oct 4 – 10, 2026')
            ->assertSee('title="Absent (didn&#039;t log in)"', false)
            ->assertSeeText('Not in yet')
            ->assertDontSee('title="Sick Leave"', false)
            ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('cra.id', $this->lhea->id)['days']['2026-10-08']['login'] !== null);

        $this->actingAs($this->supervisor)->get(route('cra-schedule.peek', ['view' => 'week', 'date' => '2026-10-12']))
            ->assertSeeText('Oct 11 – 17, 2026')
            ->assertSee('title="Sick Leave"', false);

        $this->actingAs($this->supervisor)->get(route('cra-schedule.peek', ['view' => 'month']))
            ->assertSeeText('October 2026')
            ->assertSee('title="Regina, Oct 12: Sick Leave"', false);
    }

    public function test_cras_do_not_get_the_floating_schedule(): void
    {
        $this->actingAs($this->lhea)->get(route('cra-schedule.peek'))->assertForbidden();
    }

    public function test_supervisor_changes_a_past_day_for_every_cra(): void
    {
        DutySchedule::factory()->rest()->on('2026-10-05')->create(['user_id' => $this->regina->id]);

        $this->actingAs($this->supervisor)->put(route('cra-schedule.update'), [
            'date' => '2026-10-05',
            'types' => [$this->lhea->id => DutySchedule::PERSONAL, $this->regina->id => ''],
        ])->assertRedirect(route('cra-schedule.index', ['month' => '2026-10', 'day' => '2026-10-05']))
            ->assertSessionHas('status', 'Schedule for Mon, Oct 5 saved.');

        $this->assertSame([$this->lhea->id => DutySchedule::PERSONAL], DutySchedule::pluck('type', 'user_id')->all());
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
