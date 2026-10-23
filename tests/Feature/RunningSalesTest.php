<?php

namespace Tests\Feature;

use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\PancakeSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RunningSalesTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $regina;

    private User $lhea;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Fri, Oct 9, 10:30 AM: week 2 (Oct 8–14) of October; the next fetch is at noon.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:30', 'Asia/Manila'));
        $this->supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $this->regina = $this->cra('Regina', 'CRD Rej Vergara', 20000);
        $this->lhea = $this->cra('Lhea', 'CRD Lhei', 5000);
        Product::create(['name' => 'CanPro', 'srp' => 1000]);
        Product::create(['name' => 'Sinuxyl', 'keywords' => 'Sinux', 'srp' => 500]);
    }

    private function cra(string $name, string $pancake, float $goal): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake, 'daily_sales_goal' => $goal,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    /**
     * @param  list<array{name: string, qty: int}>  $items
     */
    private function order(string $seller, float $total, array $items, string $day = '2026-10-09', int $status = 2, string $type = PancakeOrder::SEGMENTATION): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => "R{$this->seq}", 'ordered_on' => $day, 'ordered_at' => $day.' 02:00:00', 'seller_name' => $seller,
            'customer_name' => "Buyer {$this->seq}", 'status' => $status, 'total_price' => $total, 'conversion_type' => $type, 'items' => $items,
        ]);
    }

    public function test_each_cras_sales_orders_products_and_goal_left_for_today(): void
    {
        // CanPro ×1 (₱1,000 SRP) and Sinux Spray ×2 (₱500): split 50/50.
        $this->order('CRD REJ VERGARA', 10000, [['name' => 'CanPro', 'qty' => 1], ['name' => 'Sinux Spray', 'qty' => 2]], type: PancakeOrder::BROADCAST);
        // Canceled: in sales, not in orders.
        $this->order('CRD REJ VERGARA', 2000, [['name' => 'Haplunas', 'qty' => 1]], status: PancakeOrder::CANCELED);
        $this->order('CRD LHEI', 6000, [['name' => 'Sinuxyl', 'qty' => 3]]);
        $this->order('CRD LHEI', 9000, [['name' => 'CanPro', 'qty' => 1]], day: '2026-10-08');
        // Unsold still gets a column; a product added after today doesn't.
        Product::create(['name' => 'Rose Soap', 'srp' => 300]);
        Product::create(['name' => 'Cystora', 'srp' => 800, 'counts_from' => '2026-10-10']);

        $this->actingAs($this->supervisor)->get(route('running-sales.index'))->assertOk()
            ->assertViewHas('snapshot', function (array $snapshot) {
                [$regina, $lhea] = $snapshot['cras'];

                return [$regina['name'], $regina['sales'], $regina['orders'], $regina['goal']] === ['Regina', 12000.0, 1, 20000.0]
                    && $regina['products'] === ['CanPro' => ['sales' => 5000.0, 'qty' => 1], 'Sinuxyl' => ['sales' => 5000.0, 'qty' => 2], 'Others' => ['sales' => 2000.0, 'qty' => 1]]
                    && [$lhea['name'], $lhea['sales'], $lhea['orders'], $lhea['goal']] === ['Lhea', 6000.0, 1, 5000.0]
                    // Best seller first, unsold after, Others last.
                    && array_keys($snapshot['products']) === ['Sinuxyl', 'CanPro', 'Rose Soap', 'Others']
                    && $snapshot['team'] === ['sales' => 18000.0, 'orders' => 2, 'goal' => 25000.0];
            })
            ->assertSeeTextInOrder(['Updated 10:30 AM', 'next at 12:00 PM', 'Regina', '₱12,000', 'Daily goal', '₱20,000', 'Left to reach goal', '₱8,000',
                'Lhea', '₱6,000', 'Goal reached', '+₱1,000 over']);

        $this->actingAs($this->supervisor)->get(route('running-sales.index', ['show' => 'qty']))->assertOk()
            ->assertSeeTextInOrder(['Sinuxyl', 'CanPro', 'Rose Soap', 'Others', 'Regina', '2', '1', '—', '1', '4', 'Lhea', '3', '—', '—', '—', '3', 'Team', '5', '1', '—', '1', '7']);
    }

    public function test_the_week_and_the_month_count_the_days_so_far_against_the_daily_goal(): void
    {
        $this->order('CRD LHEI', 9000, [['name' => 'CanPro', 'qty' => 1]], day: '2026-10-08');
        $this->order('CRD LHEI', 4000, [['name' => 'CanPro', 'qty' => 1]], day: '2026-10-01');

        $this->actingAs($this->supervisor)->get(route('running-sales.index', ['period' => 'week']))->assertOk()
            ->assertViewHas('snapshot', fn (array $week) => $week['days'] === 2 && $week['cras'][0]['sales'] === 9000.0 && $week['cras'][0]['goal'] === 10000.0)
            ->assertSeeText('Oct 8–9, 2026');

        $this->actingAs($this->supervisor)->get(route('running-sales.index', ['period' => 'month']))->assertOk()
            ->assertViewHas('snapshot', fn (array $month) => $month['days'] === 9 && $month['cras'][0]['sales'] === 13000.0 && $month['cras'][0]['goal'] === 45000.0);
    }

    public function test_the_snapshot_is_kept_for_two_hours_unless_refreshed(): void
    {
        $this->order('CRD LHEI', 6000, [['name' => 'Sinuxyl', 'qty' => 3]]);
        $teamSales = fn (float $sales) => fn (array $snapshot) => $snapshot['team']['sales'] === $sales;

        $this->actingAs($this->supervisor)->get(route('running-sales.index'))->assertViewHas('snapshot', $teamSales(6000.0));
        $this->order('CRD LHEI', 1000, [['name' => 'Sinuxyl', 'qty' => 1]]);

        $this->travel(119)->minutes();
        $this->actingAs($this->supervisor)->get(route('running-sales.index'))->assertViewHas('snapshot', $teamSales(6000.0));

        $this->travel(2)->minutes();
        $this->actingAs($this->supervisor)->get(route('running-sales.index'))->assertViewHas('snapshot', $teamSales(7000.0));
    }

    public function test_refresh_fetches_todays_new_orders_and_takes_the_snapshot_again(): void
    {
        $this->actingAs($this->supervisor)->get(route('running-sales.index'));
        $this->mock(PancakeSync::class)->shouldReceive('syncNewOrders')->once()
            ->withArgs(fn (CarbonImmutable $day) => $day->toDateString() === '2026-10-09')
            ->andReturnUsing(function () {
                $this->order('CRD REJ VERGARA', 3000, [['name' => 'CanPro', 'qty' => 1]]);

                return 1;
            });

        $this->actingAs($this->supervisor)->post(route('running-sales.refresh'), ['period' => 'week', 'show' => 'qty'])
            ->assertRedirect(route('running-sales.index', ['period' => 'week', 'show' => 'qty']))
            ->assertSessionHas('status', 'Running sales refreshed.');

        $this->actingAs($this->supervisor)->get(route('running-sales.index'))
            ->assertViewHas('snapshot', fn (array $snapshot) => $snapshot['team']['sales'] === 3000.0);
    }

    public function test_refresh_uses_the_saved_orders_when_pancake_does_not_respond(): void
    {
        $this->order('CRD LHEI', 6000, [['name' => 'Sinuxyl', 'qty' => 3]]);
        $this->mock(PancakeSync::class)->shouldReceive('syncNewOrders')->once()->andThrow(new RuntimeException('timeout'));

        $this->actingAs($this->supervisor)->post(route('running-sales.refresh'))
            ->assertSessionHas('status', 'Pancake did not respond, so the running sales use the orders already saved.');

        $this->actingAs($this->supervisor)->get(route('running-sales.index'))
            ->assertViewHas('snapshot', fn (array $snapshot) => $snapshot['team']['sales'] === 6000.0);
    }

    public function test_the_scheduled_command_takes_the_snapshot_again(): void
    {
        $this->actingAs($this->supervisor)->get(route('running-sales.index'));
        $this->order('CRD LHEI', 6000, [['name' => 'Sinuxyl', 'qty' => 3]]);

        $this->artisan('running-sales:refresh')->assertSuccessful();

        $this->actingAs($this->supervisor)->get(route('running-sales.index'))
            ->assertViewHas('snapshot', fn (array $snapshot) => $snapshot['team']['sales'] === 6000.0);
    }

    public function test_only_users_with_the_running_sales_permission_can_open_or_refresh_it(): void
    {
        $this->actingAs($this->regina)->get(route('running-sales.index'))->assertForbidden();
        $this->actingAs($this->regina)->post(route('running-sales.refresh'))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('running-sales.index', ['period' => 'year']))->assertSessionHasErrors('period');
    }
}
