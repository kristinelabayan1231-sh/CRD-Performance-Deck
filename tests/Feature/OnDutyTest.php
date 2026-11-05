<?php

namespace Tests\Feature;

use App\Models\DutyCount;
use App\Models\DutyLogin;
use App\Models\DutySchedule;
use App\Models\Role;
use App\Models\User;
use App\Support\OnDuty;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnDutyTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $lhea;

    private User $regina;

    private User $rose;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00', 'Asia/Manila'));
        $this->withoutDefer();
        $this->supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $this->lhea = $this->cra('Lhea');
        $this->regina = $this->cra('Regina');
        $this->rose = $this->cra('Rose');
    }

    private function cra(string $name): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    public function test_a_cra_logs_in_from_the_segmentation_tracker_once_a_day(): void
    {
        Http::fake(['*' => Http::response(['count' => 0, 'stock_outs' => []])]);

        $this->actingAs($this->lhea)->get(route('segmentation.index'))->assertOk()->assertSeeText('Log in for today?');

        $this->actingAs($this->lhea)->post(route('segmentation.on-duty'))->assertRedirect()
            ->assertSessionHas('status', "You're on duty for today (Fri, Oct 9), logged in at 6:00 PM.");
        $this->travel(2)->hours();
        $this->actingAs($this->lhea)->post(route('segmentation.on-duty'));

        $this->assertSame(1, DutyLogin::where('user_id', $this->lhea->id)->count());
        $this->assertSame('2026-10-09 10:00:00', DutyLogin::firstWhere('user_id', $this->lhea->id)->logged_in_at->utc()->toDateTimeString());
        $this->actingAs($this->lhea)->get(route('segmentation.index'))->assertOk()->assertDontSeeText('Log in for today?');
    }

    public function test_a_cra_sets_days_ahead_as_rest_days_and_clears_them(): void
    {
        $this->actingAs($this->lhea)->put(route('segmentation.schedule'), ['dates' => ['2026-10-11', '2026-10-09'], 'type' => DutySchedule::REST])
            ->assertRedirect()
            ->assertSessionHas('status', 'My schedule saved: Oct 9 and Oct 11 set as Rest Day.')
            ->assertSessionHas('schedule_open', true);
        $this->assertSame(['2026-10-09' => 'rest', '2026-10-11' => 'rest'], DutySchedule::where('user_id', $this->lhea->id)->orderBy('date')->get()
            ->mapWithKeys(fn (DutySchedule $day) => [$day->date->toDateString() => $day->type])->all());

        $this->actingAs($this->lhea)->put(route('segmentation.schedule'), ['dates' => ['2026-10-11'], 'type' => 'clear'])
            ->assertSessionHas('status', 'My schedule saved: Oct 11 set back to on duty.');
        $this->assertSame(['2026-10-09'], DutySchedule::where('user_id', $this->lhea->id)->get()->map(fn (DutySchedule $day) => $day->date->toDateString())->all());
    }

    public function test_past_days_and_days_after_the_month_after_next_cannot_be_set(): void
    {
        $this->actingAs($this->lhea)->put(route('segmentation.schedule'), ['dates' => ['2026-10-08'], 'type' => DutySchedule::LEAVE])
            ->assertSessionHasErrors(['dates.0' => 'Past days are locked: ask your supervisor to change one.']);
        $this->actingAs($this->lhea)->put(route('segmentation.schedule'), ['dates' => ['2027-01-01'], 'type' => DutySchedule::LEAVE])
            ->assertSessionHasErrors(['dates.0' => 'You can plan up to December 2026.']);

        $this->assertSame(0, DutySchedule::count());
    }

    public function test_only_cras_set_a_schedule(): void
    {
        $this->actingAs($this->supervisor)->put(route('segmentation.schedule'), ['dates' => ['2026-10-10'], 'type' => DutySchedule::REST])->assertForbidden();

        $this->assertSame(0, DutySchedule::count());
    }

    public function test_the_pop_up_does_not_open_by_itself_on_a_day_off(): void
    {
        Http::fake(['*' => Http::response(['count' => 0, 'stock_outs' => []])]);
        DutySchedule::factory()->rest()->create(['user_id' => $this->lhea->id]);

        $this->actingAs($this->lhea)->get(route('segmentation.index'))
            ->assertSeeText('Today is your Rest Day')
            ->assertSeeText('Log in anyway')
            ->assertDontSee('data-auto-open', false);
    }

    public function test_only_cras_can_log_in_for_duty(): void
    {
        $this->actingAs($this->supervisor)->post(route('segmentation.on-duty'))->assertForbidden();

        $this->assertSame(0, DutyLogin::count());
    }

    public function test_supervisors_see_who_is_on_duty_and_cras_see_only_the_count(): void
    {
        DutyLogin::factory()->create(['user_id' => $this->regina->id, 'logged_in_at' => '2026-10-09 00:15:00']);
        DutyLogin::factory()->on('2026-10-08')->create(['user_id' => $this->rose->id]);   // yesterday: not on duty today

        Http::fake(['*' => Http::response(['count' => 0, 'stock_outs' => []])]);
        $this->actingAs($this->supervisor)->get(route('segmentation.index'))->assertOk()
            ->assertSeeTextInOrder(['1 CRA on duty', 'On duty today · Oct 9', 'Regina', '8:15 AM']);

        $this->actingAs($this->lhea)->get(route('segmentation.index'))->assertOk()
            ->assertSeeText('1 CRA on duty')->assertDontSeeText('On duty today');
    }

    public function test_on_duty_for_a_range_uses_a_count_entered_by_hand_and_skips_days_nobody_worked(): void
    {
        // Oct 7: 5 entered by hand (instead of its 1 log-in); Oct 8: 2 log-ins; Oct 6 and 9: nobody.
        DutyCount::create(['date' => '2026-10-07', 'count' => 5]);
        DutyLogin::factory()->on('2026-10-07')->create(['user_id' => $this->lhea->id]);
        DutyLogin::factory()->on('2026-10-08')->create(['user_id' => $this->lhea->id]);
        DutyLogin::factory()->on('2026-10-08')->create(['user_id' => $this->regina->id]);

        $this->assertSame(5.0, OnDuty::countFor(CarbonImmutable::parse('2026-10-07'), CarbonImmutable::parse('2026-10-07')));
        $this->assertSame(3.5, OnDuty::countFor(CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-09')));
        $this->assertNull(OnDuty::countFor(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-02')));
    }
}
