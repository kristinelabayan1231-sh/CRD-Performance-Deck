<?php

namespace Tests\Feature;

use App\Models\PancakeConversation;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\PcReportRow;
use App\Models\Role;
use App\Models\User;
use App\Services\PcInquiries;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PcReportTest extends TestCase
{
    use RefreshDatabase;

    private User $regina;

    private User $julyAnn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->regina = $this->user('Regina', Role::CRA);
        $this->julyAnn = $this->user('July Ann', Role::CRA);
    }

    private function user(string $name, string $role): User
    {
        return User::create([
            'email' => str_replace(' ', '', strtolower($name)).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true,
        ]);
    }

    public function test_supervisor_sees_every_cra_set_and_can_edit_the_creation_update_time(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'sinuxyl']))
            ->assertOk()
            ->assertSeeInOrder(['Sinuxyl (Regina)', 'PC - Sinuxyl Nasal Spray', 'PC - Sinuxyl Nasal Care', 'PC - Sinuxyl Official Page', 'TOTAL'])
            ->assertSee('Sinuxyl (July Ann)')
            ->assertSee('data-pc-window', false)
            ->assertSee('data-pc-split', false);
    }

    public function test_supervisor_can_narrow_to_one_cra(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->get(route('pc-report.index', ['cra' => $this->julyAnn->id]))
            ->assertOk()
            ->assertSee('Sinuxyl (July Ann)')
            ->assertDontSee('Sinuxyl (Regina)');
    }

    public function test_cra_sees_only_their_own_set_and_cannot_edit_the_creation_update_time(): void
    {
        $this->actingAs($this->regina)->get(route('pc-report.index', ['cra' => $this->julyAnn->id]))
            ->assertOk()
            ->assertSee('Sinuxyl (Regina)')
            ->assertDontSee('Sinuxyl (July Ann)')
            ->assertDontSee('data-pc-window', false)
            ->assertDontSee('data-pc-split', false)
            ->assertSee('set by your CRA Supervisor');
    }

    public function test_product_picks_the_pages_in_its_group(): void
    {
        $this->actingAs($this->regina)->get(route('pc-report.index', ['product' => 'clearsight']))
            ->assertOk()
            ->assertSee('ClearSight (Regina)')
            ->assertSee('PC - Clearsight Eye Drop')
            ->assertDontSee('PC - Sinuxyl Nasal Spray');
    }

    public function test_user_without_pc_report_permission_is_refused(): void
    {
        $this->actingAs($this->user('Plain', Role::USER))->get(route('pc-report.index'))->assertForbidden();
    }

    private function page(): PancakePage
    {
        return PancakePage::create(['name' => 'Clear Sight Hub', 'page_id' => '555', 'access_token' => 'token', 'is_active' => true]);
    }

    public function test_total_inquiries_counts_conversations_created_in_the_months_with_a_delivered_customer_only(): void
    {
        $page = $this->page();
        $orderSeq = 0;
        $conversation = fn (string $phone, string $startedAt, array $tags = []) => PancakeConversation::create([
            'pancake_page_id' => $page->id, 'conversation_id' => uniqid(), 'started_at' => $startedAt, 'phone_keys' => [$phone], 'tags' => $tags,
        ]);
        $order = function (string $phone, int $status, array $tags = []) use (&$orderSeq) {
            PancakeOrder::create(['pancake_order_id' => (string) ++$orderSeq, 'ordered_on' => '2025-01-10', 'phone_key' => $phone, 'status' => $status, 'total_price' => 500, 'tags' => $tags]);
        };

        // Counted: created in the months, Delivered (and a neutral Payment collected) order.
        $conversation('9170000001', '2025-03-05 02:00:00');
        $order('9170000001', 3);
        $order('9170000001', 16);
        // Left out: also has a Returned order.
        $conversation('9170000002', '2025-03-05 02:00:00');
        $order('9170000002', 3);
        $order('9170000002', 5);
        // Left out: an order tagged CRD - SEGMENTATION.
        $conversation('9170000003', '2025-03-05 02:00:00');
        $order('9170000003', 3, [(int) config('segmentation.conversion_tags.segmentation')]);
        // Left out: tagged with the day's CRD - OCT 14.
        $conversation('9170000004', '2025-03-05 02:00:00', ['CRD - OCT 14']);
        $order('9170000004', 3);
        // Left out: created the day before the months start (Feb 28, 11 PM Manila).
        $conversation('9170000005', '2025-02-28 15:00:00');
        $order('9170000005', 3);
        // Left out: no orders.
        $conversation('9170000006', '2025-03-05 02:00:00');

        $count = app(PcInquiries::class)->count($page, CarbonImmutable::parse('2025-03-01'), CarbonImmutable::parse('2025-12-01'), CarbonImmutable::parse('2026-10-14'));

        $this->assertSame(1, $count);
    }

    public function test_supervisor_saves_the_creation_update_time_and_a_sync_button_appears(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $this->page();
        $show = fn () => $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'clearsight', 'cra' => $this->regina->id, 'date' => '2026-10-14']));

        $show()->assertSee('Set the time first')->assertDontSee('data-pc-sync-start', false);

        $this->actingAs($supervisor)->putJson(route('pc-report.rows.update'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => null, 'from' => '2025-05', 'to' => '2025-06'])
            ->assertOk();

        $this->assertDatabaseHas('pc_report_rows', ['user_id' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => '', 'from_month' => '2025-05-01 00:00:00']);
        $show()->assertOk()->assertSee('May – Jun 2025')->assertSee('data-pc-sync-start', false)->assertSee('Not synced yet');
    }

    public function test_sync_starts_in_the_background_for_a_row_with_a_creation_update_time_only(): void
    {
        Process::fake();
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $this->page();
        $start = fn (User $user, User $cra) => $this->actingAs($user)->postJson(route('pc-report.sync'), ['cra' => $cra->id, 'page' => 'Clear Sight Hub', 'category' => null]);

        $start($supervisor, $this->regina)->assertStatus(422);

        $row = PcReportRow::create(['user_id' => $this->regina->id, 'page' => 'Clear Sight Hub', 'from_month' => '2025-07-01', 'to_month' => '2025-07-01']);
        $start($supervisor, $this->regina)->assertOk()->assertJson(['status' => 'starting', 'done' => 0, 'total' => 31]);
        Process::assertRan(fn ($process) => str_contains($process->command, "pc-report:sync {$row->id}"));

        // A CRA syncs their own rows only.
        PcReportRow::create(['user_id' => $this->julyAnn->id, 'page' => 'Clear Sight Hub', 'from_month' => '2025-07-01']);
        $start($this->regina, $this->julyAnn)->assertForbidden();
    }

    public function test_sync_fetches_the_rows_missing_days_and_engagements_and_the_report_shows_them(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 06:00:00');
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $page = $this->page();
        PancakeOrder::create(['pancake_order_id' => '1', 'ordered_on' => '2025-07-25', 'phone_key' => '9170000001', 'status' => 3, 'total_price' => 500]);
        Http::fake([
            '*/conversations*' => Http::response(['success' => true, 'conversations' => [
                ['id' => 'c1', 'has_phone' => true, 'inserted_at' => '2025-07-20T02:00:00', 'recent_phone_numbers' => [['phone_number' => '09170000001']], 'tags' => [['text' => 'crd - oct 1']]],
                ['id' => 'c2', 'has_phone' => false, 'inserted_at' => '2025-07-21T02:00:00', 'recent_phone_numbers' => [], 'tags' => []],
            ]]),
            '*/statistics/customer_engagements*' => Http::response(['success' => true, 'data' => ['series' => [['name' => 'inbox', 'data' => [9, 9]], ['name' => 'total', 'data' => [10, 20]]]]]),
        ]);
        $row = PcReportRow::create(['user_id' => $this->regina->id, 'page' => 'Clear Sight Hub', 'from_month' => '2025-07-01', 'to_month' => '2025-07-01']);
        PancakePage::whereKey($page->id)->update(['conversation_days' => json_encode(['2025-07-01', '2025-07-02'])]);

        $this->artisan('pc-report:sync', ['row' => $row->id])->assertSuccessful();

        // July has 31 days, 2 already saved: 29 fetched, a day per request; engagements are one request for the month.
        Http::assertSentCount(30);
        $this->assertCount(31, $page->fresh()->conversation_days);
        $this->assertSame(30, $row->fresh()->engagements);
        $this->assertSame('done', $row->progress()['status']);
        $this->assertSame(['CRD - OCT 1'], PancakeConversation::sole()->tags);

        $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'clearsight', 'cra' => $this->regina->id, 'date' => '2026-10-14']))
            ->assertSeeInOrder(['PC - Clear Sight Hub', '1', '30'])
            ->assertSee('Sync again')
            ->assertSee('Synced Oct 14');

        // On Oct 1 the conversation has that day's tag, so it isn't counted.
        $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'clearsight', 'cra' => $this->regina->id, 'date' => '2026-10-01']))
            ->assertViewHas('sets', fn ($sets) => $sets[$this->regina->id][3]['inquiries'] === 0);
    }

    public function test_a_sync_cut_off_shows_as_stopped_and_only_synced_pages_get_their_changes_fetched(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $page = $this->page();
        $row = PcReportRow::create(['user_id' => $this->regina->id, 'page' => 'Clear Sight Hub', 'from_month' => '2025-07-01']);
        $row->putProgress(['status' => 'running', 'done' => 4, 'total' => 31]);

        $this->actingAs($supervisor)->getJson(route('pc-report.sync.progress', $row))->assertJson(['status' => 'stopped']);
        Cache::put(PcReportRow::pageBusyKey($page->id), $row->id, 60);
        $this->actingAs($supervisor)->getJson(route('pc-report.sync.progress', $row))->assertJson(['status' => 'running', 'done' => 4]);

        Http::fake(['*' => Http::response(['success' => true, 'conversations' => []])]);
        $this->artisan('pancake:conversations')->assertSuccessful();
        Http::assertNothingSent();

        $page->update(['conversations_synced_at' => now()->subMinutes(10)]);
        $this->artisan('pancake:conversations')->assertSuccessful();
        Http::assertSent(fn ($request) => $request['order_by'] === 'updated_at');
    }

    public function test_cra_saves_their_own_tagging_in_capitals_but_not_the_time_or_another_cras_tagging(): void
    {
        $this->actingAs($this->regina)->putJson(route('pc-report.rows.update'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => null, 'tagging' => 'sw1, sjj'])
            ->assertOk();
        $this->assertSame('SW1, SJJ', PcReportRow::firstWhere('user_id', $this->regina->id)->tagging);

        $this->actingAs($this->regina)->putJson(route('pc-report.rows.update'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => null, 'from' => '2025-05', 'to' => '2025-06'])
            ->assertForbidden();
        $this->actingAs($this->regina)->putJson(route('pc-report.rows.update'), ['cra' => $this->julyAnn->id, 'page' => 'Clear Sight Hub', 'category' => null, 'tagging' => 'X'])
            ->assertForbidden();
        $this->actingAs($this->regina)->postJson(route('pc-report.split'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub'])
            ->assertForbidden();
    }

    public function test_supervisor_splits_a_page_into_dormant_and_active_and_puts_it_back(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $show = fn () => $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'clearsight', 'cra' => $this->regina->id]));

        $this->actingAs($supervisor)->postJson(route('pc-report.split'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub'])->assertOk();

        $show()->assertSeeInOrder(['PC - Clear Sight Hub - DORMANT', 'May 2024 – Dec 2025', 'PC - Clear Sight Hub - ACTIVE', 'Jan 2026 onwards']);

        $this->actingAs($supervisor)->deleteJson(route('pc-report.merge'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub'])->assertOk();

        $show()->assertSee('PC - Clear Sight Hub')->assertDontSee('PC - Clear Sight Hub - DORMANT');
    }
}
