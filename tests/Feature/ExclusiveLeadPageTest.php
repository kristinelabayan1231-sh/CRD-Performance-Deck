<?php

namespace Tests\Feature;

use App\Models\DutyLogin;
use App\Models\DutySchedule;
use App\Models\ExclusiveListLead;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\PancakeReservation;
use App\Models\Role;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

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
            ->assertSee('November plans 6,250 different leads: 0 so far, then up to 250 new leads a day.');
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
            ->assertSee('November plans 6,250 different leads')
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
            ->assertSee('November plans 6,250 different leads')
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

    /**
     * The sheet's rows as text, read back from the downloaded file.
     *
     * @return list<list<string>>
     */
    private function downloadedRows(TestResponse $response): array
    {
        $path = $response->assertOk()->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        unlink($path);

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $cells[] = (string) ($cell->is->t ?? $cell->v);
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    public function test_the_excel_file_has_the_whole_months_list_whichever_day_is_open(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $carried = $this->lead($joanna, 'Maria Santos', '09171234567', '2026-11-03');
        $catered = $this->lead($joanna, 'Pedro Reyes', '09177654321', '2026-11-05');
        $catered->forceFill(['status' => 'active', 'contact_date' => '2026-11-05'])->save();
        $lastMonth = $this->lead($joanna, 'Ana Lastmonth', '09170002222', '2026-10-30');
        $pos = $this->lead($joanna, 'Rico Possale', '09170003333', '2026-11-05');
        foreach ([[$carried, '2026-11-03', ExclusiveLeads::HANDLED], [$carried, '2026-11-04', ExclusiveLeads::LEFTOVER], [$catered, '2026-11-05', ExclusiveLeads::HANDLED], [$lastMonth, '2026-10-30', ExclusiveLeads::HANDLED], [$pos, '2026-11-05', ExclusiveLeads::POS]] as [$lead, $day, $source]) {
            ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => $day, 'lead_id' => $lead->id, 'source' => $source]);
        }

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))
            ->assertOk()->assertSee(route('exclusive-leads.export', ['cra' => $joanna->id, 'month' => '2026-11']));

        $rows = $this->downloadedRows($this->actingAs($joanna)->get(route('exclusive-leads.export', ['month' => '2026-11'])));

        $this->assertCount(3, $rows);
        $this->assertSame('Contact #', $rows[0][3]);
        $this->assertSame(['Maria Santos', '09171234567', 'Pending', 'Leftover', '2'], [$rows[1][0], $rows[1][3], $rows[1][10], $rows[1][12], $rows[1][14]]);
        $this->assertSame(['Pedro Reyes', 'Catered', 'Active'], [$rows[2][0], $rows[2][10], $rows[2][11]]);
    }

    public function test_the_tag_column_shows_the_pos_tag_only_for_customers_with_a_tagged_order(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $tagged = $this->lead($joanna, 'Maria Santos', '09171234567', '2026-11-05');
        $otherTag = $this->lead($joanna, 'Pedro Reyes', '09177654321', '2026-11-05');
        foreach ([$tagged, $otherTag] as $lead) {
            ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => '2026-11-05', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);
        }
        PancakeOrder::create(['pancake_order_id' => 'T1', 'ordered_on' => '2026-11-05', 'phone_key' => '9171234567', 'status' => 3, 'total_price' => 300, 'tags' => [398, 749]]);
        PancakeOrder::create(['pancake_order_id' => 'T2', 'ordered_on' => '2026-11-05', 'phone_key' => '9177654321', 'status' => 3, 'total_price' => 300, 'tags' => [398]]);
        $tagName = 'CRD - ANNA (EXCLUSIVE LEADS)';

        $response = $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'text-brand-700">'.$tagName));
        $this->assertSame(1, substr_count($response->getContent(), '— Add tag —'));

        $rows = $this->downloadedRows($this->actingAs($joanna)->get(route('exclusive-leads.export', ['month' => '2026-11'])));
        $this->assertSame('Tag', $rows[0][15]);
        $this->assertSame(['Maria Santos', $tagName], [$rows[1][0], $rows[1][15]]);
        $this->assertSame('Pedro Reyes', $rows[2][0]);
        $this->assertNotContains($tagName, $rows[2]);
    }

    /**
     * A lead on Joanna's list, its saved order, and Pancake answering for that order: found by search, then read by
     * its internal id, as $reads in turn.
     *
     * @param  list<array<string, mixed>>  $reads
     * @return array{0: User, 1: Lead}
     */
    private function leadWithPancakeOrder(array $reads, ?array $put = null): array
    {
        config(['services.pancake.shop_id' => '99', 'services.pancake.access_token' => 'token']);
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $lead = $this->lead($joanna, 'Maria Santos', '09171234567', '2026-11-05');
        $lead->update(['order_id' => '1326186']);
        ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => '2026-11-05', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);
        PancakeOrder::create(['pancake_order_id' => '1326186', 'ordered_on' => '2026-08-01', 'phone_key' => '9171234567', 'status' => 3, 'total_price' => 300, 'tags' => [398, 4]]);

        Http::preventStrayRequests();
        $reading = Http::sequence();
        foreach ($reads as $read) {
            $reading->push(['success' => true, 'data' => ['id' => 555, 'display_id' => 1326186, 'status' => 3, 'total_price' => 300, 'bill_phone_number' => '09171234567', 'items' => [['id' => 1]], ...$read]]);
        }
        Http::fake(function (ClientRequest $request) use ($reading, $put) {
            if ($request->method() === 'PUT') {
                return Http::response($put ?? ['success' => true]);
            }

            return str_contains($request->url(), '/orders/555?')
                ? $reading($request)
                : Http::response(['success' => true, 'data' => [['id' => 555, 'display_id' => 1326186, 'tags' => [398, 4]]]]);
        });

        return [$joanna, $lead];
    }

    public function test_the_dropdown_adds_the_tag_to_the_leads_order_keeping_its_other_tags(): void
    {
        [$joanna, $lead] = $this->leadWithPancakeOrder([['tags' => [398, 4]], ['tags' => [398, 4, 749]]]);

        $this->actingAs($joanna)->postJson(route('exclusive-leads.order-tag', $lead), ['order_tag' => '749'])
            ->assertOk()->assertExactJson(['order_tag' => 'CRD - ANNA (EXCLUSIVE LEADS)']);

        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/shops/99/orders/555?')
            && $request->data() === ['tags' => [398, 4, 749]]);
        $saved = PancakeOrder::firstWhere('pancake_order_id', '1326186');
        $this->assertSame([398, 4, 749], $saved->tags);
        $this->assertSame(PancakeOrder::SEGMENTATION, $saved->conversion_type);

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))
            ->assertOk()->assertSee('CRD - ANNA (EXCLUSIVE LEADS)')->assertDontSee('— Add tag —');
    }

    public function test_an_untagged_lead_shows_the_dropdown(): void
    {
        [$joanna] = $this->leadWithPancakeOrder([]);

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))
            ->assertOk()->assertSee('— Add tag —');
    }

    public function test_an_order_pancake_already_tagged_is_not_written_again(): void
    {
        [$joanna, $lead] = $this->leadWithPancakeOrder([['tags' => [398, 749]]]);

        $this->actingAs($joanna)->postJson(route('exclusive-leads.order-tag', $lead), ['order_tag' => '749'])->assertOk();

        Http::assertNotSent(fn (ClientRequest $request) => $request->method() === 'PUT');
        $this->assertSame([398, 749], PancakeOrder::firstWhere('pancake_order_id', '1326186')->tags);
    }

    public function test_a_refused_update_says_so_and_saves_nothing(): void
    {
        [$joanna, $lead] = $this->leadWithPancakeOrder([['tags' => [398, 4]]], ['success' => false, 'message' => 'No permission']);

        $this->actingAs($joanna)->postJson(route('exclusive-leads.order-tag', $lead), ['order_tag' => '749'])
            ->assertUnprocessable()->assertJsonPath('message', 'Pancake refused the tag on order #1326186 (HTTP 200): No permission');

        $this->assertSame([398, 4], PancakeOrder::firstWhere('pancake_order_id', '1326186')->tags);
    }

    public function test_a_read_back_that_lost_other_tags_is_reported_and_not_saved(): void
    {
        [$joanna, $lead] = $this->leadWithPancakeOrder([['tags' => [398, 4]], ['tags' => [749]]]);

        $this->actingAs($joanna)->postJson(route('exclusive-leads.order-tag', $lead), ['order_tag' => '749'])
            ->assertUnprocessable()->assertJsonPath('message', "Order #1326186's tags didn't save as expected. Check the order in Pancake: it had [398, 4] and now has [749].");

        $this->assertSame([398, 4], PancakeOrder::firstWhere('pancake_order_id', '1326186')->tags);
    }

    public function test_a_read_back_where_the_order_itself_changed_is_reported(): void
    {
        [$joanna, $lead] = $this->leadWithPancakeOrder([['tags' => [398, 4]], ['tags' => [398, 4, 749], 'items' => []]]);

        $this->actingAs($joanna)->postJson(route('exclusive-leads.order-tag', $lead), ['order_tag' => '749'])
            ->assertUnprocessable()->assertJsonPath('message', 'The tag saved on order #1326186, but its items also changed. Check the order in Pancake.');
    }

    public function test_only_the_exclusive_tag_can_be_added_and_nothing_reaches_pancake_otherwise(): void
    {
        [$joanna, $lead] = $this->leadWithPancakeOrder([]);

        $this->actingAs($joanna)->postJson(route('exclusive-leads.order-tag', $lead), ['order_tag' => '398'])
            ->assertUnprocessable()->assertJsonValidationErrors(['order_tag' => "That tag isn't available."]);

        Http::assertNothingSent();
    }

    public function test_a_supervisor_sees_the_dropdown_and_can_tag_an_exclusive_cras_lead(): void
    {
        [$joanna, $lead] = $this->leadWithPancakeOrder([['tags' => [398, 4]], ['tags' => [398, 4, 749]]]);
        $supervisor = $this->user(Role::CRA_SUPERVISOR, 'Lead');

        $this->actingAs($supervisor)->get(route('exclusive-leads.index', ['cra' => $joanna->id, 'date' => '2026-11-05']))
            ->assertOk()->assertSee('— Add tag —');
        $this->actingAs($supervisor)->postJson(route('exclusive-leads.order-tag', $lead), ['order_tag' => '749'])->assertOk();

        $this->assertSame([398, 4, 749], PancakeOrder::firstWhere('pancake_order_id', '1326186')->tags);
    }

    public function test_a_cra_can_tag_a_lead_held_for_them_before_its_day(): void
    {
        [$joanna, $lead] = $this->leadWithPancakeOrder([['tags' => [398, 4]], ['tags' => [398, 4, 749]]]);
        ExclusiveListLead::where('lead_id', $lead->id)->delete();
        $lead->update(['assigned_to' => null]);
        ExclusiveReservedLead::create(['user_id' => $joanna->id, 'month' => '2026-11-01', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::RANDOM]);

        $this->actingAs($joanna)->postJson(route('exclusive-leads.order-tag', $lead), ['order_tag' => '749'])->assertOk();
    }

    public function test_a_cra_cannot_tag_another_cras_lead_and_a_lead_off_the_tracker_has_no_tagging(): void
    {
        [$joanna, $lead] = $this->leadWithPancakeOrder([]);
        $otherExclusive = $this->user(Role::CRA, 'Regina', '2026-10-02');
        $lhea = $this->user(Role::CRA, 'Lhea');
        $offTracker = $this->lead($lhea, 'Pedro Reyes', '09177654321', '2026-11-05');

        $this->actingAs($otherExclusive)->postJson(route('exclusive-leads.order-tag', $lead), ['order_tag' => '749'])->assertForbidden();
        $this->actingAs($lhea)->postJson(route('exclusive-leads.order-tag', $offTracker), ['order_tag' => '749'])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_call_button_dials_from_the_exclusive_list_on_the_cras_linked_phone(): void
    {
        $webhook = 'https://trigger.macrodroid.com/joanna-phone/call';
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $joanna->forceFill(['call_webhook_url' => $webhook])->save();
        $lead = $this->lead($joanna, 'Maria Santos', '9171234567', '2026-11-05');
        ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => '2026-11-05', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);
        Http::preventStrayRequests();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))
            ->assertOk()->assertSee('data-call-url="'.route('segmentation.call', $lead).'"', false);
        $this->actingAs($joanna)->postJson(route('segmentation.call', $lead))->assertOk();

        Http::assertSent(fn (ClientRequest $request) => $request->url() === "{$webhook}?number=09171234567&lead={$lead->id}");
    }

    public function test_clicking_a_row_has_the_cras_spiels_and_the_customers_chat_to_open(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $joanna->spiels()->create(['title' => 'Exclusive Leads', 'body' => '<p>Hello {name}</p>', 'position' => 1]);
        $lead = $this->lead($joanna, 'Maria Santos', '9171234567', '2026-11-05');
        ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => '2026-11-05', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))
            ->assertOk()
            ->assertSee('data-spiels-panel', false)
            ->assertSee('Exclusive Leads')
            ->assertSee('data-spiel-row data-chat-url="'.route('segmentation.chat', $lead).'"', false);
    }

    public function test_the_segmentation_tracker_has_no_tag_column(): void
    {
        $regina = $this->user(Role::CRA, 'Regina');
        $this->lead($regina, 'Maria Santos', '09171234567', '2026-10-07');

        $this->actingAs($regina)->get(route('segmentation.index', ['date' => '2026-10-07']))
            ->assertOk()->assertDontSee('data-col="order_tag"', false);
    }

    public function test_the_excel_file_is_the_cras_own_unless_they_oversee_and_keeps_the_filters(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $regina = $this->user(Role::CRA, 'Regina', '2026-10-02');
        $crd = $this->lead($joanna, 'Maria Santos', '09170000001', '2026-11-05');
        $fsd = $this->lead($joanna, 'Pedro Reyes', '09170000002', '2026-11-05');
        $fsd->update(['lead_type' => Lead::TYPE_FSD]);
        $others = $this->lead($regina, 'Maria Cruz', '09170000003', '2026-11-05');
        foreach ([[$joanna, $crd], [$joanna, $fsd], [$regina, $others]] as [$cra, $lead]) {
            ExclusiveListLead::create(['user_id' => $cra->id, 'list_date' => '2026-11-05', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);
        }
        $names = fn (TestResponse $response) => array_column(array_slice($this->downloadedRows($response), 1), 0);

        $this->assertSame(['Maria Santos', 'Pedro Reyes'], $names($this->actingAs($joanna)->get(route('exclusive-leads.export', ['cra' => $regina->id]))));
        $this->assertSame(['Pedro Reyes'], $names($this->actingAs($joanna)->get(route('exclusive-leads.export', ['type' => Lead::TYPE_FSD]))));
        $this->assertSame(['Maria Cruz'], $names($this->actingAs($this->user(Role::CRA_SUPERVISOR, 'Lead'))->get(route('exclusive-leads.export', ['cra' => $regina->id]))));
    }

    public function test_a_cra_not_on_the_page_has_no_excel_file(): void
    {
        $this->actingAs($this->user(Role::CRA, 'Regina'))->get(route('exclusive-leads.export'))->assertNotFound();
    }

    public function test_the_excel_file_survives_special_characters_and_does_not_run_formulas(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $lead = $this->lead($joanna, "=SUM(A1) <Ana> & \x07Co", '09170000001', '2026-11-05');
        ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => '2026-11-05', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);

        $rows = $this->downloadedRows($this->actingAs($joanna)->get(route('exclusive-leads.export')));

        $this->assertSame('=SUM(A1) <Ana> & Co', $rows[1][0]);
    }

    public function test_the_segmentation_tracker_has_no_excel_or_copy_all_button(): void
    {
        $regina = $this->user(Role::CRA, 'Regina');
        $this->lead($regina, 'Maria Santos', '09170000001', '2026-11-06');

        $this->actingAs($regina)->get(route('segmentation.index', ['date' => '2026-11-06']))
            ->assertOk()->assertDontSee('Download the whole month')->assertDontSee('data-copy-all-phones');
    }

    public function test_copy_all_gets_every_number_on_the_months_list_past_the_page_and_each_customer_once(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $regina = $this->user(Role::CRA, 'Regina', '2026-10-02');
        $list = fn (User $cra, Lead $lead, string $day, string $source = ExclusiveLeads::HANDLED) => ExclusiveListLead::create(['user_id' => $cra->id, 'list_date' => $day, 'lead_id' => $lead->id, 'source' => $source]);

        $expected = [];
        for ($index = 10; $index < 45; $index++) {
            $expected[] = '091700000'.$index;
            $list($joanna, $this->lead($joanna, 'Customer '.$index, '091700000'.$index, '2026-11-03'), '2026-11-03');
        }
        $secondOrder = Lead::create(['order_id' => 'SECOND', 'customer_name' => 'Customer 10', 'phone_number' => '09170000010', 'product_name' => 'Sinuxyl', 'qty' => 1, 'delivered_date' => '2026-06-01', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-11-04', 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $joanna->id]);
        $list($joanna, $secondOrder, '2026-11-04');
        $list($joanna, $this->lead($joanna, 'Last Month', '09170002222', '2026-10-30'), '2026-10-30');
        $list($joanna, $this->lead($joanna, 'Pos Sale', '09170003333', '2026-11-05'), '2026-11-05', ExclusiveLeads::POS);
        $list($regina, $this->lead($regina, 'Regina Customer', '09170004444', '2026-11-05'), '2026-11-05');

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-03']))
            ->assertOk()->assertSee(route('exclusive-leads.phones', ['cra' => $joanna->id, 'month' => '2026-11']));

        $this->actingAs($joanna)->getJson(route('exclusive-leads.phones', ['month' => '2026-11', 'cra' => $regina->id]))
            ->assertOk()->assertExactJson(['phones' => $expected]);
    }

    public function test_copy_all_keeps_the_filters_and_is_not_open_to_a_cra_off_the_page(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $crd = $this->lead($joanna, 'Maria Santos', '09170000001', '2026-11-05');
        $fsd = $this->lead($joanna, 'Pedro Reyes', '09170000002', '2026-11-05');
        $fsd->update(['lead_type' => Lead::TYPE_FSD]);
        foreach ([$crd, $fsd] as $lead) {
            ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => '2026-11-05', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);
        }

        $this->actingAs($joanna)->getJson(route('exclusive-leads.phones', ['type' => Lead::TYPE_FSD]))
            ->assertOk()->assertExactJson(['phones' => ['09170000002']]);

        $this->actingAs($this->user(Role::CRA, 'Regina'))->getJson(route('exclusive-leads.phones'))->assertNotFound();
    }

    public function test_the_whole_list_has_the_upcoming_leads_and_leaves_out_those_moved_away(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $regina = $this->user(Role::CRA, 'Regina');
        $listed = $this->lead($joanna, 'Maria Santos', '09170000001', '2026-11-05');
        $movedAway = $this->lead($joanna, 'Moved Away', '09170000002', '2026-11-05');
        $upcoming = $this->lead($joanna, 'Upcoming Customer', '09170000003', '2026-11-05');
        $upcoming->forceFill(['assigned_to' => null])->save();
        foreach ([$listed, $movedAway] as $lead) {
            ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => '2026-11-05', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);
        }
        $movedAway->forceFill(['assigned_to' => $regina->id])->save();
        ExclusiveReservedLead::create(['user_id' => $joanna->id, 'month' => '2026-11-01', 'lead_id' => $upcoming->id, 'source' => ExclusiveLeads::RANDOM]);

        $this->actingAs($joanna)->getJson(route('exclusive-leads.phones'))
            ->assertOk()->assertExactJson(['phones' => ['09170000001', '09170000003']]);

        $rows = $this->downloadedRows($this->actingAs($joanna)->get(route('exclusive-leads.export')));
        $this->assertSame(['Maria Santos', 'Upcoming Customer'], [$rows[1][0], $rows[2][0]]);
        $this->assertSame(['Pending', 'Upcoming'], [$rows[1][10], $rows[2][10]]);

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['view' => 'month']))
            ->assertOk()->assertSee('Upcoming Customer')->assertDontSee('Moved Away')->assertSee('1 upcoming')
            ->assertDontSee('data-update-url="'.route('segmentation.update', $upcoming).'"', false);

        $this->actingAs($joanna)->patchJson(route('segmentation.update', $upcoming), ['repeat_purchase' => 'yes'])->assertForbidden();
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

    public function test_a_lead_moved_to_another_cra_leaves_her_day_and_her_search(): void
    {
        $joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
        $regina = $this->user(Role::CRA, 'Regina', '2026-10-02');
        $kept = $this->lead($joanna, 'Maria Santos', '09170000001', '2026-11-05');
        $movedAway = $this->lead($joanna, 'Maria Moved', '09170000002', '2026-11-05');
        foreach ([$kept, $movedAway] as $lead) {
            ExclusiveListLead::create(['user_id' => $joanna->id, 'list_date' => '2026-11-05', 'lead_id' => $lead->id, 'source' => ExclusiveLeads::RANDOM]);
        }
        $movedAway->forceFill(['assigned_to' => $regina->id])->save();

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))
            ->assertOk()->assertSee('Maria Santos')->assertDontSee('Maria Moved');

        $this->actingAs($joanna)->get(route('exclusive-leads.index', ['q' => 'maria']))
            ->assertOk()->assertSee('Maria Santos')->assertDontSee('Maria Moved');
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
