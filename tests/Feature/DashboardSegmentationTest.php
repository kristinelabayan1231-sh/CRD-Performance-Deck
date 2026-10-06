<?php

namespace Tests\Feature;

use App\Models\Lead;
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
            'est_out_of_stock_date' => $day, 'lead_type' => Lead::TYPE_NEW, 'assigned_to' => $cra->id, ...$fields,
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

        $today = app(SegmentationStats::class)->periods(LeadGenerator::cras())['today'];
        $kpis = collect($today['kpis'])->keyBy('label');

        // Leads 5 vs 4 yesterday: +25%, up and good.
        $this->assertSame(['value' => '5', 'previous' => '4', 'change' => '+25%', 'direction' => 'up', 'good' => true],
            array_intersect_key($kpis['Leads'], array_flip(['value', 'previous', 'change', 'direction', 'good'])));
        // Processed 40% vs 100%: -60 pts, down and bad.
        $this->assertSame(['40%', '100%', '-60 pts', false], [$kpis['Processed']['value'], $kpis['Processed']['previous'], $kpis['Processed']['change'], $kpis['Processed']['good']]);
        // Converted 2 of 5 = 40%; retained 1 of 2 CRD = 50%; new 1 of 3 = 33%.
        $this->assertSame('40%', $kpis['Converted']['value']);
        $this->assertSame([50.0, 33.0], [$today['retained'], $today['new_converted']]);
        // Went cold 1 of 5 = 20%, up from 0: bad, since lower is better.
        $this->assertSame(['20%', false], [$kpis['Went cold']['value'], $kpis['Went cold']['good']]);
        $this->assertSame('1/5', $kpis['Went cold']['count']);

        // Tags donut and untagged remainder.
        $this->assertSame(['Hot' => 1, 'Cold' => 1, 'Warm' => 1, 'High value' => 1], collect($today['tags'])->pluck('value', 'label')->all());
        $this->assertSame(1, $today['untagged']);

        // Top CRAs: Alice first with 2 processed.
        $this->assertSame(['name' => 'Alice', 'processed' => 2, 'unprocessed' => 1, 'converted' => 2], $today['ranking'][0]);

        // Today's trend covers the last 7 days, ending today.
        $this->assertCount(7, $today['series']);
        $this->assertSame(['day' => '2026-10-08', 'label' => 'Oct 8', 'processed' => 4, 'unprocessed' => 0], $today['series'][5]);
    }

    public function test_week_and_month_periods(): void
    {
        $this->lead($this->bob, '2026-10-08', ['status' => 'active']);
        $this->lead($this->alice, '2026-10-02');

        $periods = app(SegmentationStats::class)->periods(LeadGenerator::cras());

        $this->assertSame('Oct 8–14', $periods['week']['label']);
        $this->assertSame(1, $periods['week']['leads']);
        $this->assertCount(2, $periods['week']['series']); // Oct 8 & 9 so far; future days left out
        $this->assertSame(2, $periods['month']['leads']);
        $this->assertSame('Bob', $periods['week']['ranking'][0]['name']);
    }

    public function test_dashboard_shows_segmentation_card_for_supervisors(): void
    {
        $this->lead($this->alice, '2026-10-09', ['status' => 'active']);
        $this->lead($this->bob, '2026-10-09');

        $this->actingAs($this->owner)->get('/dashboard')->assertOk()
            ->assertSee('Segmentation Tracker')
            ->assertSeeInOrder(['Today', 'Week', 'Month'])
            ->assertSeeInOrder(['Leads', 'Processed', 'Converted', 'Went cold'])
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
        $this->assertSame(['Alice'], array_column($response->viewData('segmentation')['today']['ranking'], 'name'));
        $this->assertSame(1, $response->viewData('segmentation')['today']['leads']);
    }

    public function test_users_without_segmentation_access_see_no_card(): void
    {
        $plain = User::create(['email' => 'plain@gmail.com', 'role_id' => Role::defaultUser()->id, 'is_active' => true]);

        $this->actingAs($plain)->get('/dashboard')->assertOk()
            ->assertDontSee('Segmentation Tracker</h2>', false)
            ->assertSee('No modules are available to you yet.');
    }
}
