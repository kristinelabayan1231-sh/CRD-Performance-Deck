<?php

namespace Tests\Feature;

use App\Models\DutyCount;
use App\Models\DutyLogin;
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
