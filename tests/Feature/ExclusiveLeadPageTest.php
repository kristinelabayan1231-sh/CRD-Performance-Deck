<?php

namespace Tests\Feature;

use App\Models\DutyLogin;
use App\Models\DutySchedule;
use App\Models\ExclusiveListLead;
use App\Models\Lead;
use App\Models\PancakeReservation;
use App\Models\Role;
use App\Models\User;
use App\Services\ExclusiveLeads;
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

    private function lead(User $cra, string $name, string $phone, string $day): Lead
    {
        return Lead::create([
            'order_id' => 'S'.$phone, 'customer_name' => $name, 'phone_number' => $phone,
            'product_name' => 'Pterygium', 'qty' => 1, 'delivered_date' => '2026-06-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => $day, 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra->id,
        ]);
    }

    public function test_search_finds_the_cras_leads_on_any_day_by_name_or_contact_and_not_another_cras(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $regina = $this->user(Role::CRA, 'Regina', '2026-10-02');
        $this->lead($joanna, 'Maria Santos', '09171234567', '2026-10-03');
        $this->lead($joanna, 'Pedro Reyes', '09177654321', '2026-10-04');
        $this->lead($regina, 'Maria Cruz', '09170001111', '2026-10-03');

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['q' => 'maria']))
            ->assertOk()
            ->assertSee('Search results for')
            ->assertSee('Maria Santos')
            ->assertDontSee('Pedro Reyes')
            ->assertDontSee('Maria Cruz')
            ->assertDontSee('Month goals');

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['q' => '0917 765']))
            ->assertOk()
            ->assertSee('Pedro Reyes')
            ->assertDontSee('Maria Santos');
    }

    public function test_an_exclusive_cras_repeat_purchase_fills_the_date_and_time_of_contact(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:30', 'Asia/Manila'));
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $lead = $this->lead($joanna, 'Maria Santos', '09171234567', '2026-10-03');

        $this->actingAs($joanna)->patchJson(route('segmentation.update', $lead), ['repeat_purchase' => 'yes'])
            ->assertOk()
            ->assertJson(['contact_date' => '2026-11-06', 'contact_time' => '10']);

        $this->assertSame('2026-11-06', $lead->fresh()->contact_date->toDateString());
        $this->assertSame('10', $lead->fresh()->contact_time);
    }

    public function test_clearing_repeat_purchase_leaves_the_contact_and_the_segmentation_tracker_stays_as_it_was(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:30', 'Asia/Manila'));
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $regina = $this->user(Role::CRA, 'Regina');
        $exclusiveLead = $this->lead($joanna, 'Maria Santos', '09171234567', '2026-10-03');
        $exclusiveLead->forceFill(['repeat_purchase' => 'yes', 'contact_date' => '2026-11-01', 'contact_time' => '09'])->save();
        $segmentationLead = $this->lead($regina, 'Pedro Reyes', '09177654321', '2026-10-03');

        $this->actingAs($joanna)->patchJson(route('segmentation.update', $exclusiveLead), ['repeat_purchase' => null])->assertOk();
        $this->actingAs($regina)->patchJson(route('segmentation.update', $segmentationLead), ['repeat_purchase' => 'yes'])->assertOk();

        $this->assertSame('2026-11-01', $exclusiveLead->fresh()->contact_date->toDateString());
        $this->assertNull($segmentationLead->fresh()->contact_date);
        $this->assertNull($segmentationLead->fresh()->contact_time);
    }

    public function test_the_whole_month_lists_each_lead_once_for_the_cra_and_those_who_oversee_them(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $regina = $this->user(Role::CRA, 'Regina', '2026-10-02');
        $carried = $this->lead($joanna, 'Maria Santos', '09171234567', '2026-11-03');
        $later = $this->lead($joanna, 'Pedro Reyes', '09177654321', '2026-11-05');
        $lastMonth = $this->lead($joanna, 'Ana Lastmonth', '09170002222', '2026-10-30');
        $pos = $this->lead($joanna, 'Rico Possale', '09170003333', '2026-11-05');
        $others = $this->lead($regina, 'Maria Cruz', '09170001111', '2026-11-05');
        $list = fn (User $cra, Lead $lead, string $day, string $source = ExclusiveLeads::HANDLED) => ExclusiveListLead::create(['user_id' => $cra->id, 'list_date' => $day, 'lead_id' => $lead->id, 'source' => $source]);
        $list($joanna, $carried, '2026-11-03');
        $list($joanna, $carried, '2026-11-04', ExclusiveLeads::LEFTOVER);
        $list($joanna, $later, '2026-11-05');
        $list($joanna, $lastMonth, '2026-10-30');
        $list($joanna, $pos, '2026-11-05', ExclusiveLeads::POS);
        $list($regina, $others, '2026-11-05');
        $teamLead = Role::create(['slug' => Role::CRA_TEAM_LEAD, 'name' => 'CRA Team Lead', 'permissions' => ['exclusive_leads.view', 'exclusive_leads.view_all']]);

        $asJoanna = $this->actingAs($joanna)->get(route('exclusive-leads.index', ['view' => 'month']))->assertOk();
        $asJoanna->assertSee('Maria Santos')->assertSee('Pedro Reyes')->assertSee('Last listed')->assertSee('Nov 4 · 2 days');
        $asJoanna->assertSee('2 different leads')->assertDontSee('Ana Lastmonth')->assertDontSee('Rico Possale')->assertDontSee('Maria Cruz');

        foreach ([$this->user(Role::CRA_SUPERVISOR, 'Lead'), User::create(['email' => 'team@example.com', 'display_name' => 'Team', 'role_id' => $teamLead->id, 'is_active' => true])] as $overseer) {
            $this->actingAs($overseer)->get(route('exclusive-leads.index', ['cra' => $joanna->id, 'view' => 'month']))
                ->assertOk()->assertSee('Maria Santos')->assertDontSee('Maria Cruz');
        }

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['cra' => $regina->id, 'view' => 'month']))
            ->assertOk()->assertDontSee('Maria Cruz');
    }

    public function test_a_day_still_shows_only_that_days_leads(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        foreach (['Maria Santos' => '2026-11-03', 'Pedro Reyes' => '2026-11-05'] as $name => $day) {
            $lead = $this->lead($joanna, $name, '0917'.substr(md5($name), 0, 7), $day);
            ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => $day, 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);
        }

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))
            ->assertOk()->assertSee('Pedro Reyes')->assertDontSee('Maria Santos')->assertDontSee('Last listed')->assertSee('Whole month');
    }

    public function test_type_and_product_narrow_the_list_and_the_calendar_keeps_them(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $crd = $this->lead($joanna, 'Maria Santos', '09170000001', '2026-11-05');
        $fsd = $this->lead($joanna, 'Pedro Reyes', '09170000002', '2026-11-05');
        $fsd->update(['lead_type' => Lead::TYPE_FSD]);
        $otherProduct = $this->lead($joanna, 'Ana Cruz', '09170000003', '2026-11-05');
        $otherProduct->update(['product_name' => 'Sinuxyl']);
        foreach ([$crd, $fsd, $otherProduct] as $lead) {
            ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => '2026-11-05', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);
        }

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05', 'type' => Lead::TYPE_FSD]))
            ->assertOk()->assertSee('Pedro Reyes')->assertDontSee('Maria Santos')->assertDontSee('Ana Cruz')
            ->assertSee(route('exclusive-leads.index', ['cra' => $joanna->id, 'type' => Lead::TYPE_FSD, 'date' => '2026-11-03']));

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05', 'product' => 'Sinuxyl']))
            ->assertOk()->assertSee('Ana Cruz')->assertDontSee('Maria Santos')->assertDontSee('Pedro Reyes');
    }

    public function test_a_date_range_lists_each_lead_of_those_days_once_in_any_order(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $carried = $this->lead($joanna, 'Maria Santos', '09170000001', '2026-11-03');
        $outside = $this->lead($joanna, 'Pedro Reyes', '09170000002', '2026-11-05');
        foreach ([[$carried, '2026-11-03'], [$carried, '2026-11-04'], [$outside, '2026-11-05']] as [$lead, $day]) {
            ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => $day, 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);
        }

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['from' => '2026-11-04', 'to' => '2026-11-03']))
            ->assertOk()->assertSee('Maria Santos')->assertDontSee('Pedro Reyes')->assertSee('Last listed')
            ->assertSeeInOrder(['1 different lead', 'Clear range'])
            ->assertViewHas('unprocessed', fn ($leads) => $leads->total() === 1);
    }

    public function test_the_pending_full_view_has_the_cras_own_follow_up_tabs(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $regina = $this->user(Role::CRA, 'Regina');
        $followUps = [
            ['callback', 'Callback Customer', ['callback_date' => '2026-11-05', 'status' => 'pjr_drop_call', 'contact_date' => '2026-11-01']],
            ['reactivation', 'Reactivation Customer', ['status' => 'pjr_drop_call', 'contact_date' => '2026-11-01']],
            ['reserved', 'Reserved Customer', ['repeat_purchase' => Lead::reserveRepeatPurchases()[0], 'status' => 'pjr_drop_call', 'contact_date' => '2026-11-01']],
        ];
        foreach ($followUps as $index => [, $name, $fields]) {
            $this->lead($joanna, $name, '0917000000'.$index, '2026-09-01')->forceFill(['listed_on' => '2026-09-01', ...$fields])->save();
            $this->lead($regina, 'Regina '.$name, '0918000000'.$index, '2026-09-01')->forceFill(['listed_on' => '2026-09-01', ...$fields])->save();
        }
        foreach ([[$joanna, 'Pancake Customer', '09190000001'], [$regina, 'Regina Pancake Customer', '09190000002']] as [$cra, $name, $phone]) {
            PancakeReservation::create(['assigned_to' => $cra->id, 'created_by' => $cra->id, 'customer_name' => $name, 'phone_number' => $phone, 'phone_key' => substr($phone, 1)]);
        }

        $tab = fn (?string $list) => $this->actingAs($joanna)->get(route('exclusive-leads.index', array_filter(['show' => 'unprocessed', 'full' => 1, 'list' => $list])));

        $tab(null)->assertOk()->assertSeeInOrder(['Pending', 'Call back for today', 'Reactivation', 'Reserved', 'Reserved - Pancake']);
        $tab('callback')->assertOk()->assertSee('Callback Customer')->assertDontSee('Regina Callback Customer');
        $tab('reactivation')->assertOk()->assertSee('Reactivation Customer')->assertDontSee('Regina Reactivation Customer');
        $tab('reserved')->assertOk()->assertSee('Reserved Customer')->assertDontSee('Regina Reserved Customer');
        $tab('reserved_pancake')->assertOk()->assertSee('Pancake Customer')->assertDontSee('Regina Pancake Customer');
    }

    public function test_the_catered_full_view_has_no_follow_up_tabs(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['show' => 'processed', 'full' => 1]))
            ->assertOk()->assertDontSee('Reserved - Pancake');
    }

    public function test_the_filters_offer_only_one_cra_at_a_time_and_a_cra_cannot_pick_another(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $other = $this->user(Role::CRA, 'Lhea', '2026-10-02');
        $otherLead = $this->lead($other, 'Maria Santos', '09170000001', '2026-11-05');
        ExclusiveListLead::create(['user_id' => $other->id, 'list_date' => '2026-11-05', 'lead_id' => $otherLead->id, 'source' => ExclusiveLeads::HANDLED]);

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['cra' => $other->id, 'date' => '2026-11-05']))
            ->assertOk()->assertDontSee('Maria Santos')->assertDontSee('<option value="all"', false);
    }

    public function test_a_search_with_no_match_says_so_and_a_cra_cannot_search_another_cras_list(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $regina = $this->user(Role::CRA, 'Regina', '2026-10-02');
        $this->lead($regina, 'Maria Cruz', '09170001111', '2026-10-03');

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['cra' => $regina->id, 'q' => 'maria']))
            ->assertOk()
            ->assertDontSee('Maria Cruz')
            ->assertSee('No pending leads match this search.');
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
