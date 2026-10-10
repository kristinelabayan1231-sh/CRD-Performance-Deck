<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadTransfer;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Support\MonthWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeeklySegmentationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $seq = 0;

    /** Rows the fake retention API returns. */
    private array $apiRows = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shecom.key' => 'test-key']);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00', 'Asia/Manila'));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);

        // Mark today as freshly synced so page loads don't call the API.
        Http::fake(['*' => fn () => Http::response(['count' => count($this->apiRows), 'stock_outs' => $this->apiRows])]);
        app(LeadGenerator::class)->generate(Lead::today());
    }

    private function user(string $email, string $role): User
    {
        return User::create(['email' => $email, 'name' => ucfirst(strtok($email, '@')), 'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true]);
    }

    private function lead(User $cra, string $day, ?string $status = null, ?string $statusAt = null): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => "o{$this->seq}", 'customer_name' => "Customer {$this->seq}", 'phone_number' => "9170000{$this->seq}",
            'product_name' => 'Pterygium Drops', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 15,
            'est_out_of_stock_date' => $day, 'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $cra->id,
            'status' => $status, 'status_updated_at' => $statusAt ? CarbonImmutable::parse($statusAt, 'Asia/Manila') : null,
        ]);
    }

    public function test_weeks_always_start_on_the_first(): void
    {
        $labels = fn (string $month) => array_column(MonthWeeks::for(CarbonImmutable::parse($month)), 'label');

        $this->assertSame(['Sep 1–7', 'Sep 8–14', 'Sep 15–21', 'Sep 22–28', 'Sep 29–30'], $labels('2026-09-01'));
        $this->assertSame(['Oct 1–7', 'Oct 8–14', 'Oct 15–21', 'Oct 22–28', 'Oct 29–31'], $labels('2026-10-01'));
        $this->assertSame(['Feb 1–7', 'Feb 8–14', 'Feb 15–21', 'Feb 22–28'], $labels('2026-02-01'));
        $this->assertSame('Feb 29', $labels('2028-02-01')[4]);
        $this->assertSame(2, MonthWeeks::containing(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-09')));
    }

    public function test_unprocessed_leads_carry_over_with_label_until_handled(): void
    {
        $this->markTestSkipped('The tracker\'s carry-over list is turned off for now (SegmentationController).');

        $alice = $this->user('alice@gmail.com', Role::CRA);
        $old = $this->lead($alice, '2026-10-07');                                        // still unprocessed
        $handledToday = $this->lead($alice, '2026-10-08', 'active', '2026-10-09 08:00');  // handled this morning
        $handledBefore = $this->lead($alice, '2026-10-06', 'active', '2026-10-07 10:00'); // handled earlier: not backlog

        $this->actingAs($alice)->get('/segmentation')->assertOk()
            ->assertSee('Carry-over')
            ->assertSee($old->customer_name)->assertSee('Pending since Oct 7')
            ->assertSee($handledToday->customer_name)->assertSee('Catered · carried since Oct 8')
            ->assertDontSee($handledBefore->customer_name);

        // A past day shows only its own leads: no carry-over section.
        $this->actingAs($alice)->get('/segmentation?date=2026-10-08')
            ->assertSee($handledToday->customer_name)
            ->assertDontSee('Carry-over')
            ->assertDontSee($old->customer_name);
    }

    public function test_resync_never_reassigns_only_new_leads_are_assigned(): void
    {
        $alice = $this->user('alice@gmail.com', Role::CRA);
        $row = fn (string $id, string $phone) => [
            'order_id' => $id, 'tracking_number' => null, 'customer_name' => "C{$id}", 'phone_number' => $phone,
            'product_name' => 'Sinuxyl', 'qty' => 1, 'delivered_date' => '2026-09-25', 'consumption_days_per_unit' => 15,
            'estimated_out_of_stock_date' => '2026-10-09',
        ];

        $this->apiRows = [$row('a', '9171'), $row('b', '9172')];
        app(LeadGenerator::class)->generate(Lead::today());
        $this->assertSame(2, Lead::where('assigned_to', $alice->id)->count());

        // A second CRA joins; re-sync brings one new order.
        $bob = $this->user('bob@gmail.com', Role::CRA);
        $this->apiRows = [$row('a', '9171'), $row('b', '9172'), $row('c', '9173')];
        app(LeadGenerator::class)->generate(Lead::today());

        $this->assertSame($alice->id, Lead::firstWhere('order_id', 'a')->assigned_to);
        $this->assertSame($alice->id, Lead::firstWhere('order_id', 'b')->assigned_to);
        $this->assertSame($bob->id, Lead::firstWhere('order_id', 'c')->assigned_to);
    }

    public function test_weekly_view_shows_handled_per_day_and_unprocessed_customers(): void
    {
        $alice = $this->user('alice@gmail.com', Role::CRA);
        $bob = $this->user('bob@gmail.com', Role::CRA);
        $this->lead($alice, '2026-10-08', 'active', '2026-10-08 10:00');
        $missed = $this->lead($alice, '2026-10-08');
        $this->lead($alice, '2026-10-09');                               // today: in progress, not unprocessed
        $this->lead($bob, '2026-10-08', 'busy_callback', '2026-10-08 11:00');
        $this->lead($alice, '2026-10-01');                               // previous week: backlog, not this week

        $response = $this->actingAs($this->owner)->get('/segmentation/weekly')->assertOk()
            ->assertSee('Weekly Segmentation')->assertSee('Oct 8–14')
            ->assertSee('1 / 2')   // Alice on Oct 8
            ->assertSee('1 / 1')   // Bob on Oct 8
            ->assertSee('Carry-over customers')
            ->assertSee($missed->customer_name)
            ->assertSee('Pending since Oct 8');

        $rows = $response->viewData('rows')->keyBy(fn ($r) => $r['cra']->id);
        $this->assertSame(['assigned' => 3, 'handled' => 1, 'unprocessed' => 1, 'in_progress' => 1],
            array_intersect_key($rows[$alice->id], array_flip(['assigned', 'handled', 'unprocessed', 'in_progress'])));
        $this->assertSame(2, $response->viewData('totals')['backlog']); // Oct 8 miss + Oct 1 miss

        $this->actingAs($this->owner)->get('/segmentation/weekly?month=2026-10&week=1')->assertOk()->assertSee('Oct 1–7');
    }

    public function test_cra_sees_only_their_own_week(): void
    {
        $alice = $this->user('alice@gmail.com', Role::CRA);
        $bob = $this->user('bob@gmail.com', Role::CRA);
        $this->lead($bob, '2026-10-08');

        $this->actingAs($alice)->get('/segmentation/weekly?cra=all')->assertOk()
            ->assertSee('Alice')->assertDontSee('Bob')->assertDontSee('Transfer selected');
    }

    public function test_supervisor_transfers_backlog_after_seeing_workload(): void
    {
        $supervisor = $this->user('sup@gmail.com', Role::CRA_SUPERVISOR);
        $alice = $this->user('alice@gmail.com', Role::CRA);
        $bob = $this->user('bob@gmail.com', Role::CRA);
        $backlog = $this->lead($alice, '2026-10-08');
        $this->lead($bob, '2026-10-09');

        // The dialog is given each CRA's current load.
        $workload = $this->actingAs($supervisor)->get('/segmentation/weekly')->assertOk()->viewData('workload');
        $this->assertSame([
            ['id' => $alice->id, 'name' => 'Alice', 'today' => 0, 'backlog' => 1],
            ['id' => $bob->id, 'name' => 'Bob', 'today' => 1, 'backlog' => 0],
        ], $workload);

        $this->actingAs($supervisor)->post('/segmentation/backlog/transfer', ['lead_ids' => [$backlog->id], 'to' => $bob->id])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Transferred 1 backlog lead from Alice to Bob.');

        $this->assertSame($bob->id, $backlog->fresh()->assigned_to);
        $this->assertDatabaseHas('lead_transfers', ['lead_id' => $backlog->id, 'from_user_id' => $alice->id, 'to_user_id' => $bob->id, 'transferred_by' => $supervisor->id]);

        // Bob's tracker carry-over list (with "from Alice") is turned off for now.
    }

    public function test_only_backlog_can_be_transferred_and_only_to_cras(): void
    {
        $supervisor = $this->user('sup@gmail.com', Role::CRA_SUPERVISOR);
        $alice = $this->user('alice@gmail.com', Role::CRA);
        $bob = $this->user('bob@gmail.com', Role::CRA);
        $today = $this->lead($alice, '2026-10-09');
        $handled = $this->lead($alice, '2026-10-08', 'active', '2026-10-08 10:00');
        $backlog = $this->lead($alice, '2026-10-08');

        $this->actingAs($supervisor)->post('/segmentation/backlog/transfer', ['lead_ids' => [$today->id], 'to' => $bob->id])->assertSessionHasErrors('transfer');
        $this->actingAs($supervisor)->post('/segmentation/backlog/transfer', ['lead_ids' => [$handled->id], 'to' => $bob->id])->assertSessionHasErrors('transfer');
        $this->actingAs($supervisor)->post('/segmentation/backlog/transfer', ['lead_ids' => [$backlog->id], 'to' => $supervisor->id])->assertSessionHasErrors('to');
        $this->actingAs($supervisor)->post('/segmentation/backlog/transfer', ['lead_ids' => [$backlog->id], 'to' => $alice->id])->assertSessionHasErrors('transfer');

        $this->assertSame(0, LeadTransfer::count());
        $this->assertSame($alice->id, $backlog->fresh()->assigned_to);
    }

    public function test_cras_cannot_transfer(): void
    {
        $alice = $this->user('alice@gmail.com', Role::CRA);
        $bob = $this->user('bob@gmail.com', Role::CRA);
        $backlog = $this->lead($alice, '2026-10-08');

        $this->actingAs($alice)->post('/segmentation/backlog/transfer', ['lead_ids' => [$backlog->id], 'to' => $bob->id])->assertForbidden();
        $this->assertSame($alice->id, $backlog->fresh()->assigned_to);
    }

    public function test_bulk_transfer_moves_every_selected_lead(): void
    {
        $alice = $this->user('alice@gmail.com', Role::CRA);
        $bob = $this->user('bob@gmail.com', Role::CRA);
        $leads = collect([$this->lead($alice, '2026-10-07'), $this->lead($alice, '2026-10-08')]);

        $this->actingAs($this->owner)->post('/segmentation/backlog/transfer', ['lead_ids' => $leads->pluck('id')->all(), 'to' => $bob->id])
            ->assertSessionHas('status', 'Transferred 2 backlog leads from Alice to Bob.');

        $this->assertSame(2, Lead::where('assigned_to', $bob->id)->count());
    }

    public function test_pjr_inactive_and_no_status_carry_over(): void
    {
        $alice = $this->user('alice@gmail.com', Role::CRA);
        $bob = $this->user('bob@gmail.com', Role::CRA);
        $at = '2026-10-08 10:00';
        $carry = [
            'none' => $this->lead($alice, '2026-10-08'),
            'pjr' => $this->lead($alice, '2026-10-08', 'pjr_drop_call', $at),
            'inactive' => $this->lead($alice, '2026-10-08', 'inactive', $at),
        ];
        $done = [
            $this->lead($alice, '2026-10-08', 'active', $at),
            $this->lead($alice, '2026-10-08', 'busy_callback', $at),
            $this->lead($alice, '2026-10-08', 'blocked', $at),
        ];

        $this->assertEqualsCanonicalizing(collect($carry)->pluck('id')->all(), Lead::carryOver()->pluck('id')->all());

        $this->markTestSkipped('The tracker\'s carry-over list is turned off for now (SegmentationController).');

        $page = $this->actingAs($alice)->get('/segmentation')->assertOk()
            ->assertSee('Pending since Oct 8')
            ->assertSee('PJR/Inactive/CBR/Drop call since Oct 8')
            ->assertSee('Inactive since Oct 8');
        foreach ($done as $lead) {
            $page->assertDontSee($lead->customer_name.'<', false);
        }

        // Carry-over leads with a status can still be transferred.
        $this->actingAs($this->owner)->post('/segmentation/backlog/transfer', ['lead_ids' => [$carry['pjr']->id], 'to' => $bob->id])->assertSessionHasNoErrors();
        $this->assertSame($bob->id, $carry['pjr']->fresh()->assigned_to);

        // Once the status changes to Active it stops carrying over.
        $carry['repeat']->update(['status' => 'active', 'status_updated_at' => CarbonImmutable::parse('2026-10-08 12:00', 'Asia/Manila')]);
        $this->assertNotContains($carry['repeat']->id, Lead::carryOver()->pluck('id')->all());
    }
}
