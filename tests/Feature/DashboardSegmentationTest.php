<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\SegmentationStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardSegmentationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $alice;

    private User $bob;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shecom.key' => 'test-key']);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00', 'Asia/Manila'));
        Http::fake(['*' => Http::response(['count' => 0, 'stock_outs' => []])]);
        app(LeadGenerator::class)->generate(Lead::today()); // mark today synced

        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        $cra = Role::firstWhere('slug', Role::CRA)->id;
        $this->alice = User::create(['email' => 'alice@gmail.com', 'name' => 'Alice', 'role_id' => $cra, 'is_active' => true]);
        $this->bob = User::create(['email' => 'bob@gmail.com', 'name' => 'Bob', 'role_id' => $cra, 'is_active' => true]);
    }

    private function lead(User $cra, string $day, array $fields = []): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => "o{$this->seq}", 'customer_name' => "Customer {$this->seq}", 'phone_number' => "9170000{$this->seq}",
            'product_name' => 'Pterygium Drops', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 15,
            'est_out_of_stock_date' => $day, 'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $cra->id, ...$fields,
        ]);
    }

    public function test_kpis_compare_with_previous_period(): void
    {
        // Today (Oct 9): Alice processes 2 of 3, Bob 0 of 2.
        $this->lead($this->alice, '2026-10-09', ['status' => 'active', 'repeat_purchase' => 'yes', 'customer_tag' => 'hot', 'lead_type' => Lead::TYPE_CRD]);
        $this->lead($this->alice, '2026-10-09', ['status' => 'active', 'repeat_purchase' => 'no', 'feedback' => 'purchased', 'customer_tag' => 'high_value']);
        $this->lead($this->alice, '2026-10-09', ['customer_tag' => 'cold']);
        $this->lead($this->bob, '2026-10-09', ['customer_tag' => 'canpro_warm', 'lead_type' => Lead::TYPE_CRD]);
        $this->lead($this->bob, '2026-10-09');
        // Yesterday (Oct 8): Bob processes 4 of 4.
        foreach (range(1, 4) as $i) {
            $this->lead($this->bob, '2026-10-08', ['status' => 'busy_callback']);
        }
        // Earlier this month, outside the week.
        $this->lead($this->alice, '2026-10-02', ['customer_tag' => 'canpro_cold']);

        $today = app(SegmentationStats::class)->range(LeadGenerator::cras(), CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-09'));
        $kpis = collect($today['kpis'])->keyBy('label');

        // Leads 5 vs 4 yesterday: +25%, up and good.
        $this->assertSame(['value' => '5', 'previous' => '4', 'change' => '+25%', 'direction' => 'up', 'good' => true],
            array_intersect_key($kpis['Leads'], array_flip(['value', 'previous', 'change', 'direction', 'good'])));
        // Catered 40% vs 100%: -60 pts, down and bad.
        $this->assertSame(['40%', '100%', '-60 pts', false], [$kpis['Catered']['value'], $kpis['Catered']['previous'], $kpis['Catered']['change'], $kpis['Catered']['good']]);
        // Converted = Retained + Repeat (Repeat Purchase = Yes bought that day; No + PURCHASED isn't one): 1 of 5 = 20%;
        // Retained 0 of 3 FSD, Repeat 1 of 2 CRD = 50%.
        $this->assertSame(['20.0%', '1/5'], [$kpis['Converted']['value'], $kpis['Converted']['count']]);
        $this->assertSame([0.0, 50.0], [$today['retained'], $today['repeat']]);
        // Went cold 1 of 5 = 20%, up from 0: bad, since lower is better.
        $this->assertSame(['20%', false], [$kpis['Went cold']['value'], $kpis['Went cold']['good']]);
        $this->assertSame('1/5', $kpis['Went cold']['count']);

        // Tags donut and untagged remainder.
        $this->assertSame(['Hot' => 1, 'Cold' => 1, 'Warm' => 1, 'High value' => 1], collect($today['tags'])->pluck('value', 'label')->all());
        $this->assertSame(1, $today['untagged']);

        // Top CRAs: Alice first with 2 processed.
        $this->assertSame(['name' => 'Alice', 'processed' => 2, 'unprocessed' => 1, 'converted' => 1], $today['ranking'][0]);

        // A single day's trend covers the 7 days up to it.
        $this->assertCount(7, $today['series']);
        $this->assertSame(['day' => '2026-10-08', 'label' => 'Oct 8', 'processed' => 4, 'unprocessed' => 0], $today['series'][5]);
    }

    public function test_catered_counts_as_the_trackers_catered_list(): void
    {
        $this->lead($this->alice, '2026-10-09', ['status' => 'active']);
        $this->lead($this->alice, '2026-10-09', ['contact_date' => '2026-10-09']);   // contacted, no status yet
        $this->lead($this->alice, '2026-10-09')->forceFill(['processed_at' => now()])->save();   // marked as processed
        $this->lead($this->alice, '2026-10-09');

        $day = app(SegmentationStats::class)->range(LeadGenerator::cras(), CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-09'));

        $this->assertSame(3, Lead::processed()->count());
        $this->assertSame('75%', collect($day['kpis'])->firstWhere('label', 'Catered')['value']);
        $this->assertSame([3, 1], [$day['series'][6]['processed'], $day['series'][6]['unprocessed']]);
        $this->assertSame([3, 1], [$day['ranking'][0]['processed'], $day['ranking'][0]['unprocessed']]);
    }

    public function test_a_range_of_lead_days(): void
    {
        $this->lead($this->bob, '2026-10-08', ['status' => 'active']);
        $this->lead($this->alice, '2026-10-02');

        $range = app(SegmentationStats::class)->range(LeadGenerator::cras(), CarbonImmutable::parse('2026-10-08'), CarbonImmutable::parse('2026-10-09'));
        $month = app(SegmentationStats::class)->range(LeadGenerator::cras(), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-09'));

        $this->assertSame('Oct 8–9', $range['label']);
        $this->assertSame(1, $range['leads']);
        $this->assertCount(2, $range['series']);
        $this->assertSame('Bob', $range['ranking'][0]['name']);
        $this->assertSame(2, $month['leads']);
    }

    public function test_dashboard_shows_segmentation_card_for_supervisors(): void
    {
        $this->lead($this->alice, '2026-10-09', ['status' => 'active']);
        $this->lead($this->bob, '2026-10-09');

        $this->actingAs($this->owner)->get('/dashboard')->assertOk()
            ->assertSee('Segmentation Tracker')
            ->assertSee('Lead days Oct 1–9')
            ->assertSeeInOrder(['Leads', 'Catered', 'Converted', 'Went cold'])
            ->assertSee('Daily trend')->assertSee('Customer tags')->assertSee('Top CRAs')
            ->assertSee('Retained')
            ->assertSee('Alice')->assertSee('Bob')
            ->assertSee('User Access'); // other modules still have room
    }

    public function test_cra_dashboard_shows_only_their_own_numbers(): void
    {
        $this->lead($this->alice, '2026-10-09');
        $this->lead($this->bob, '2026-10-09');

        $response = $this->actingAs($this->alice)->get('/dashboard')->assertOk()->assertSee('Segmentation Tracker');
        $this->assertSame(['Alice'], array_column($response->viewData('segmentation')['ranking'], 'name'));
        $this->assertSame(1, $response->viewData('segmentation')['leads']);
    }

    public function test_dashboard_retained_and_repeat_count_tracker_repeat_purchases_on_the_day_bought(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00', 'Asia/Manila'));
        // A September lead bought today: counts in October, not under its lead day.
        $this->lead($this->alice, '2026-09-20', ['lead_type' => Lead::TYPE_CRD, 'repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-10-16']);
        $this->lead($this->bob, '2026-10-05', ['repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-10-10']);
        $this->lead($this->bob, '2026-10-06', ['repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-09-30']); // bought in September
        $this->lead($this->alice, '2026-10-07', ['repeat_purchase' => 'no']);
        // The same customer twice on October lead days: two leads, as the tracker counts them.
        $this->lead($this->alice, '2026-10-08', ['phone_number' => '9179999999']);
        $this->lead($this->bob, '2026-10-09', ['phone_number' => '9179999999']);
        $this->lead($this->bob, '2026-10-09', ['lead_type' => Lead::TYPE_CRD]);

        $response = $this->actingAs($this->owner)->get('/dashboard')->assertOk()
            ->assertSee(route('segmentation.repeat-purchases', ['from' => '2026-10-01', 'to' => '2026-10-16', 'segment' => 'repeat']));
        // Each out of its own leads on October's lead days: Retained of the FSD Leads, Repeat of the CRD Leads.
        $this->assertSame(['leads' => ['retained' => 5, 'repeat' => 1], 'retained' => 1, 'repeat' => 1], array_diff_key($response->viewData('customers'), ['goals' => 0]));
        $this->assertSame(6, $response->viewData('segmentation')['leads']);
        // The Segmentation Tracker panel agrees: Converted = Retained + Repeat, the same % as the tiles (1 of 5, 1 of 1).
        $panel = $response->viewData('segmentation');
        $this->assertSame('2/6', collect($panel['kpis'])->firstWhere('label', 'Converted')['count']);
        $this->assertSame([20.0, 100.0], [$panel['retained'], $panel['repeat']]);
        // The counts behind them, shown under Converted: 1 of 5 FSD + 1 of 1 CRD = 2 of 6.
        $this->assertSame(['retained' => 1, 'fsd' => 5, 'repeat' => 1, 'crd' => 1], $panel['counts']);
        $response->assertSeeInOrder(['Converted', '33.3%', '2/6', 'Retained', '1/5 · 20.0%', 'Repeat', '1/1 · 100.0%']);

        // A CRA: only their own leads.
        $mine = $this->actingAs($this->alice)->get('/dashboard')->viewData('customers');
        $this->assertSame([2, 0, 0, 1], [$mine['leads']['retained'], $mine['leads']['repeat'], $mine['retained'], $mine['repeat']]);
    }

    public function test_a_tile_opens_its_customers_for_the_dashboards_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00', 'Asia/Manila'));
        $this->lead($this->alice, '2026-09-20', ['customer_name' => 'Sept Sam', 'lead_type' => Lead::TYPE_CRD, 'repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-10-16']);
        $this->lead($this->bob, '2026-10-05', ['customer_name' => 'Fsd Fay', 'repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-10-10']);
        $this->lead($this->bob, '2026-10-06', ['customer_name' => 'Old Olga', 'repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-09-30']);
        // The same customer bought twice in the dates: listed once.
        $this->lead($this->bob, '2026-10-08', ['customer_name' => 'Fsd Fay', 'phone_number' => '91700002', 'repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-10-12']);

        $this->actingAs($this->owner)->get(route('segmentation.repeat-purchases', ['from' => '2026-10-01', 'to' => '2026-10-16', 'segment' => 'retained']))
            ->assertOk()
            ->assertViewHas('customers', fn ($customers) => $customers->pluck('customer_name')->all() === ['Fsd Fay'])
            ->assertViewHas('counts', fn ($counts) => $counts->all() === ['retained' => 1, 'repeat' => 1])
            ->assertSee('Oct 10, 2026')->assertDontSee('Old Olga');

        // A supervisor picks a CRA: their customers and counts only.
        $this->actingAs($this->owner)->get(route('segmentation.repeat-purchases', ['from' => '2026-10-01', 'to' => '2026-10-16', 'segment' => 'repeat', 'cra' => $this->alice->id]))
            ->assertOk()
            ->assertViewHas('customers', fn ($customers) => $customers->pluck('customer_name')->all() === ['Sept Sam'])
            ->assertViewHas('counts', fn ($counts) => $counts->all() === ['retained' => 0, 'repeat' => 1]);

        // A CRA: only their own, whatever CRA is asked for.
        $this->actingAs($this->alice)->get(route('segmentation.repeat-purchases', ['from' => '2026-10-01', 'to' => '2026-10-16', 'segment' => 'retained', 'cra' => $this->bob->id]))
            ->assertOk()->assertDontSee('Fsd Fay')->assertDontSee('All CRAs');
    }

    public function test_months_before_the_tracker_count_retained_and_repeat_per_customer_from_deliveries(): void
    {
        $this->alice->update(['pancake_name' => 'CRD ALICE']);
        $delivered = fn (string $id, string $team, string $phone, string $day) => LogisticsOrder::create([
            'order_id' => $id, 'team' => $team, 'customer_name' => $id, 'phone_number' => $phone, 'phone_key' => $phone,
            'product' => 'Pterygium Drops', 'delivered_date' => $day,
        ]);
        $ordered = fn (string $id, string $phone, string $day, string $seller = 'CRD ALICE', int $status = 3) => PancakeOrder::create([
            'pancake_order_id' => $id, 'ordered_on' => $day, 'seller_name' => $seller, 'phone_key' => $phone, 'status' => $status, 'total_price' => 500,
        ]);

        // Two deliveries, one customer, who ordered from a CRA afterwards.
        $delivered('F1', LogisticsOrder::TEAM_FSD, '9170000001', '2026-08-03');
        $delivered('F2', LogisticsOrder::TEAM_FSD, '9170000001', '2026-08-20');
        $ordered('P1', '9170000001', '2026-09-10');
        // Not retained: ordered only before the delivery, canceled afterwards, or from a seller outside the CRD team.
        $delivered('F3', LogisticsOrder::TEAM_FSD, '9170000002', '2026-08-10');
        $ordered('P2', '9170000002', '2026-08-05');
        $delivered('F4', LogisticsOrder::TEAM_FSD, '9170000003', '2026-08-10');
        $ordered('P3', '9170000003', '2026-08-15', status: PancakeOrder::CANCELED);
        $delivered('F5', LogisticsOrder::TEAM_FSD, '9170000004', '2026-08-10');
        $ordered('P4', '9170000004', '2026-08-15', seller: 'FB SELLER');
        // Repeat, and a delivery outside the dates.
        $delivered('C1', LogisticsOrder::TEAM_CRD, '9170000005', '2026-08-12');
        $ordered('P5', '9170000005', '2026-08-30');
        $delivered('C2', LogisticsOrder::TEAM_CRD, '9170000006', '2026-07-31');
        // The tracker's own purchase in the month no longer counts on the tiles.
        $this->lead($this->alice, '2026-08-01', ['repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-08-02']);

        $response = $this->actingAs($this->owner)->get('/dashboard?month=2026-08')->assertOk()
            ->assertSee('FSD customers delivered')->assertSee('No tracker data');

        $customers = $response->viewData('customers');
        $this->assertSame(['retained' => 4, 'repeat' => 1], $customers['leads']);
        $this->assertSame([1, 1], [$customers['retained'], $customers['repeat']]);
        $this->assertNull($response->viewData('results')['conversion']['sc']['rate']);
        $this->assertNull($response->viewData('results')['conversion']['all']['rate']);
    }

    public function test_orders_count_untagged_only_before_the_tags_existed(): void
    {
        $this->alice->update(['pancake_name' => 'CRD ALICE']);
        $ordered = fn (string $id, string $day, ?string $type = null, int $status = 3) => PancakeOrder::create([
            'pancake_order_id' => $id, 'ordered_on' => $day, 'seller_name' => 'CRD ALICE', 'status' => $status, 'total_price' => 500, 'conversion_type' => $type,
        ]);
        $ordered('P1', '2026-07-08');
        $ordered('P2', '2026-07-08', status: PancakeOrder::CANCELED);
        $ordered('P3', '2026-07-09');
        $ordered('P4', '2026-07-09', PancakeOrder::SEGMENTATION);

        $response = $this->actingAs($this->owner)->get('/dashboard?month=2026-07')->assertOk()->assertSee('Not tagged 1');
        $team = $response->viewData('results')['team'];

        // P1 is an order, P2 counts in gross as canceled orders do, P3 stays out once tagging began.
        $this->assertSame([1, 1, 2], [$team['untagged_orders'], $team['sc_orders'], $team['orders']]);
        $this->assertSame(1500.0, $team['gross']);

        $this->actingAs($this->owner)->get(route('conversion.orders', ['month' => '2026-07']))->assertOk()->assertSee('Not tagged');
    }

    public function test_a_cra_keeps_their_own_tracker_figures_before_the_tracker_month(): void
    {
        $this->lead($this->alice, '2026-08-01', ['repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-08-02']);
        LogisticsOrder::create([
            'order_id' => 'F1', 'team' => LogisticsOrder::TEAM_FSD, 'customer_name' => 'F1', 'phone_number' => '9170000001', 'phone_key' => '9170000001',
            'product' => 'Pterygium Drops', 'delivered_date' => '2026-08-03',
        ]);

        $customers = $this->actingAs($this->alice)->get('/dashboard?month=2026-08')->assertOk()->viewData('customers');

        $this->assertArrayNotHasKey('source', $customers);
        $this->assertSame(1, $customers['retained']);
    }

    public function test_users_without_segmentation_access_see_no_card(): void
    {
        $plain = User::create(['email' => 'plain@gmail.com', 'role_id' => Role::defaultUser()->id, 'is_active' => true]);

        $this->actingAs($plain)->get('/dashboard')->assertOk()
            ->assertDontSee('Segmentation Tracker</h2>', false)
            ->assertSee('No modules are available to you yet.');
    }
}
