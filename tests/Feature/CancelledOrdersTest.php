<?php

namespace Tests\Feature;

use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
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
        // Not counted: delivered, untagged, last month.
        $this->order('CRD LHEI', '2026-10-10', 3);
        $this->order('CRD LHEI', '2026-10-10', 6, type: null);
        $this->order('CRD LHEI', '2026-09-30', 6);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            // 3 of the 4 tagged October orders (the delivered one counts in the basis).
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
}
