<?php

namespace Tests\Feature;

use App\Models\CvrOrderEntry;
use App\Models\OrderStatusChange;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\PancakeClient;
use App\Services\PancakeSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CancelledOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 18:00', 'Asia/Manila'));
        $this->withoutDefer();
        Http::fake(fn () => Http::response(['success' => true, 'count' => 0, 'stock_outs' => [], 'data' => [], 'users_engagements' => []]));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
    }

    private function cra(string $name, string $pancake): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    private function order(string $seller, string $day, int $status, float $total = 1000, ?string $type = PancakeOrder::SEGMENTATION): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => (string) (7000 + $this->seq), 'ordered_on' => $day, 'seller_name' => $seller,
            'status' => $status, 'total_price' => $total, 'conversion_type' => $type,
        ]);
    }

    public function test_dashboard_tile_counts_tagged_cancelled_and_waiting_orders_and_opens_the_list(): void
    {
        $this->cra('Lhea', 'CRD LHEI');
        $this->order('CRD LHEI', '2026-10-10', 6);
        $this->order('CRD LHEI', '2026-10-09', 11);
        $this->order('CRD LHEI', '2026-10-09', 11);
        // Not counted: untagged (not a confirmed order either), a non-CRD seller, last month; delivered is in the basis.
        $this->order('CRD LHEI', '2026-10-10', 6, type: null);
        $this->order('CRD LHEI', '2026-10-10', 3);
        $this->order('FSD AGENT', '2026-10-10', 6);
        $this->order('CRD LHEI', '2026-09-30', 6);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            // 3 of the 4 tagged October orders (confirmed + canceled), as Total confirmed orders counts them.
            ->assertSeeTextInOrder(['AOV', 'Cancelled / Waiting', '3', '75.0%', 'Cancelled 1', 'Waiting 2'])
            ->assertSee(route('cancelled-orders.index'));
    }

    public function test_page_lists_orders_per_day_week_month_and_range_and_a_cra_sees_their_own(): void
    {
        $lhea = $this->cra('Lhea', 'CRD LHEI');
        $this->cra('Regina', 'CRD REJ VERGARA');
        $this->order('CRD LHEI', '2026-10-10', 6, 600);
        $this->order('CRD LHEI', '2026-10-05', 11, 900, PancakeOrder::BROADCAST);
        $this->order('CRD REJ VERGARA', '2026-10-09', 11, 400);
        $this->order('CRD LHEI', '2026-09-28', 6, 300);
        $this->order('CRD LHEI', '2026-10-10', 3, 9999);

        // The dashboard's month: October so far, most recent first, per day and listed.
        $this->actingAs($this->owner)->get(route('cancelled-orders.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['Cancelled + waiting', '3', 'Cancelled', '1', 'Waiting for goods', '2', 'Amount', '₱1,900.00'])
            ->assertSeeTextInOrder(['Sat, Oct 10', 'Fri, Oct 9', 'Mon, Oct 5'])
            ->assertSeeTextInOrder(['7001', 'Lhea', 'Canceled', '7003', 'Regina', 'Waiting for goods', '7002', 'Broadcast'])
            ->assertDontSee('7004')->assertDontSee('₱9,999.00');

        $index = fn (array $query) => $this->actingAs($this->owner)->get(route('cancelled-orders.index', $query))->assertOk();

        $index(['period' => 'day', 'date' => '2026-10-09'])->assertSee('7003')->assertDontSee('7001')->assertDontSee('7002');
        // The week of Oct 5 (Mon) runs to Oct 10 (today).
        $index(['period' => 'week', 'date' => '2026-10-07'])->assertSee('7001')->assertSee('7002')->assertSee('7003')->assertDontSee('7004');
        $index(['period' => 'month', 'month' => '2026-09'])->assertSee('7004')->assertDontSee('7001');
        $index(['from' => '2026-09-28', 'to' => '2026-10-05'])->assertSee('7004')->assertSee('7002')->assertDontSee('7001');
        $index(['status' => 'waiting'])->assertSee('7002')->assertSee('7003')->assertDontSee('7001');

        $this->actingAs($lhea)->get(route('cancelled-orders.index'))
            ->assertOk()->assertSee('7001')->assertSee('7002')->assertDontSee('7003');
    }

    public function test_orders_count_as_total_confirmed_orders_counts_them(): void
    {
        config(['customers.crd_accounts' => ['CRD LHEI', 'CRD JULY DE LOS SANTOS']]);
        $lhea = $this->cra('Lhea', 'CRD LHEI');
        $this->order('CRD LHEI', '2026-10-09', 6, 499);                              // 7001: counts
        $this->order('AJ DELA CRUZ', '2026-10-09', 11, 1000);                        // 7002: counted for Lhea in the CVR Tracker
        CvrOrderEntry::create(['pancake_order_id' => '7002', 'user_id' => $lhea->id]);
        // Not counted: untagged, an unlisted product, a former CRA's account, a non-CRD seller.
        $this->order('CRD LHEI', '2026-10-09', 6, 300, type: null);                  // 7003
        $this->order('CRD LHEI', '2026-10-09', 11, 400);                             // 7004
        PancakeOrder::where('pancake_order_id', '7004')->update(['non_crd' => true]);
        $this->order('CRD JULY DE LOS SANTOS', '2026-10-09', 11, 800);               // 7005
        $this->order('FSD AGENT', '2026-10-09', 6, 777);                             // 7006
        // In the basis: a delivered order; deleted isn't.
        $this->order('CRD LHEI', '2026-10-09', 3, 9999);                             // 7007
        $this->order('CRD LHEI', '2026-10-09', 7, 5000);                             // 7008

        $this->actingAs($this->owner)->get(route('cancelled-orders.index', ['period' => 'day', 'date' => '2026-10-09']))
            ->assertOk()
            ->assertSeeTextInOrder(['Cancelled + waiting', '2', 'Cancelled', '1', 'Waiting for goods', '1', 'Amount', '₱1,499.00'])
            ->assertSee('7001')->assertSee('7002')
            ->assertDontSee('7003')->assertDontSee('7004')->assertDontSee('7005')->assertDontSee('7006');

        // The % basis: the 3 tagged orders of that day (confirmed + canceled), the same orders Total confirmed orders counts.
        $this->actingAs($lhea)->get(route('dashboard', ['from' => '2026-10-09', 'to' => '2026-10-09']))
            ->assertOk()
            ->assertViewHas('results', fn (array $results) => $results['cancelled']['total'] === 2 && $results['cancelled']['orders'] === 3
                && $results['team']['orders'] === 2);
    }

    public function test_the_sync_records_orders_that_leave_cancelled_or_waiting(): void
    {
        $this->order('CRD LHEI', '2026-10-09', 6);
        $this->order('CRD LHEI', '2026-10-09', 11);
        $this->order('CRD LHEI', '2026-10-09', 3);
        $pancake = fn (string $id, int $status) => ['display_id' => $id, 'inserted_at' => '2026-10-09T02:00:00', 'status' => $status, 'tags' => [398], 'items' => []];
        $client = $this->mock(PancakeClient::class);
        // Days later: the waiting order is changed in Pancake (an older order, picked up by the day's changes).
        $client->shouldReceive('updatedOrders')->andReturn([['display_id' => '7002', 'status' => 6, 'tags' => [398]]]);
        $client->shouldReceive('orders')->andReturn([
            $pancake('7001', 2), // canceled → shipped
            $pancake('7002', 11), // still waiting
            $pancake('7003', 5), // delivered → returned: not from cancelled / waiting
        ]);

        app(PancakeSync::class)->syncOrders(CarbonImmutable::parse('2026-10-09'));

        app(PancakeSync::class)->refreshTags(CarbonImmutable::parse('2026-10-12'));

        $this->assertSame([['7001', 6, 2], ['7002', 11, 6]], OrderStatusChange::orderBy('id')->get()->map(fn ($change) => [$change->pancake_order_id, $change->from_status, $change->to_status])->all());
        $this->assertSame(2, PancakeOrder::firstWhere('pancake_order_id', '7001')->status);
    }

    public function test_page_lists_status_changes_and_supervisors_get_a_pop_up_once(): void
    {
        $lhea = $this->cra('Lhea', 'CRD LHEI');
        $this->order('CRD LHEI', '2026-09-20', 2, 1500);
        OrderStatusChange::factory()->create(['pancake_order_id' => '7001', 'from_status' => 6, 'to_status' => 2]);

        $this->actingAs($this->owner)->get(route('cancelled-orders.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['Status changes', '7001', 'ordered Sep 20', 'Lhea', 'Canceled', 'Shipped', '₱1,500.00'])
            ->assertSee('changes-dialog')
            ->assertSeeText('1 order left Cancelled / Waiting since your last visit');

        // Seen: listed; the pop-up no longer opens by itself, but its button opens the last one again.
        $this->actingAs($this->owner)->get(route('cancelled-orders.index'))
            ->assertOk()->assertSee('7001')
            ->assertDontSee("document.getElementById('changes-dialog').showModal();</script>", false)
            ->assertSeeText('Last pop-up (1)')
            ->assertSeeText('1 order left Cancelled / Waiting (last pop-up)');

        // A CRA sees their own changes, without the pop-up.
        $this->actingAs($lhea)->get(route('cancelled-orders.index'))
            ->assertOk()->assertSee('7001')->assertDontSee('changes-dialog');
    }
}
