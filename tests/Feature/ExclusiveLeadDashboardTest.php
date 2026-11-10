<?php

namespace Tests\Feature;

use App\Models\ExclusiveListLead;
use App\Models\Lead;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\ExclusiveLeads;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ExclusiveLeadDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $joanna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00', 'Asia/Manila'));
        $this->joanna = $this->user(Role::CRA, 'Joanna', '2026-10-02');
    }

    private function user(string $slug, string $name, ?string $exclusiveFrom = null): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => 'P '.$name,
            'role_id' => Role::firstWhere('slug', $slug)->id, 'is_active' => true, 'exclusive_from' => $exclusiveFrom,
        ]);
    }

    /**
     * A lead of $cra's, on their list for real day $listedOn (no working date, so lead day = real day).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function listed(User $cra, string $name, string $listedOn, array $attributes = []): Lead
    {
        $lead = Lead::create([
            'order_id' => 'S'.$name, 'customer_name' => $name, 'phone_number' => '0917'.crc32($name),
            'product_name' => 'ClearSight', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => $listedOn, 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra->id,
            ...$attributes,
        ]);
        ExclusiveListLead::create(['user_id' => $cra->id, 'list_date' => $listedOn, 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HANDLED]);

        return $lead;
    }

    /**
     * @return array<string, array<int, int>> product => bottle bucket => leads
     */
    private function cells(Collection $products): array
    {
        return $products->mapWithKeys(fn (array $row) => [$row['product'] => $row['cells']])->all();
    }

    public function test_a_cra_cannot_open_the_dashboard(): void
    {
        $this->actingAs($this->joanna)->get(route('exclusive-leads.dashboard'))->assertForbidden();
    }

    public function test_the_month_groups_the_cras_leads_by_product_and_bottles_and_lists_the_biggest_cell_soonest_out_of_stock_first(): void
    {
        $this->listed($this->joanna, 'Ana Runs Out Soon', '2026-11-06', ['est_out_of_stock_date' => '2026-11-08']);
        $this->listed($this->joanna, 'Bea Runs Out Later', '2026-11-03', ['est_out_of_stock_date' => '2026-11-20']);
        $this->listed($this->joanna, 'Cora Already Out', '2026-11-03', ['est_out_of_stock_date' => '2026-11-01']);
        $this->listed($this->joanna, 'Dina Two Bottles', '2026-11-04', ['qty' => 2]);
        $this->listed($this->joanna, 'Eva Five Bottles', '2026-11-04', ['qty' => 5, 'product_name' => 'Pterygium']);
        $this->listed($this->joanna, 'October Lead', '2026-10-20');
        $this->listed($this->user(Role::CRA, 'Regina', '2026-10-02'), 'Regina Customer', '2026-11-04');
        $supervisor = $this->user(Role::CRA_SUPERVISOR, 'Supervisor');

        $this->actingAs($supervisor)->get(route('exclusive-leads.dashboard'))
            ->assertOk()
            ->assertViewHas('grid', fn (array $grid) => $this->cells($grid['products']) === ['ClearSight' => [1 => 3, 2 => 1], 'Pterygium' => [4 => 1]]
                && $grid['leads'] === 5 && $grid['bottles'] === 1 + 1 + 1 + 2 + 5)
            ->assertSeeInOrder(['ClearSight · 1 bottle · 3 customers', 'Ana Runs Out Soon', 'Nov 8 · in 2 days', 'Bea Runs Out Later', 'Cora Already Out', 'Nov 1'])
            ->assertDontSee('Nov 1 · out')
            ->assertDontSee('Regina Customer')
            ->assertDontSee('Dina Two Bottles');
    }

    public function test_today_and_year_to_date_take_the_lists_of_those_days(): void
    {
        $this->listed($this->joanna, 'Today Lead', '2026-11-06');
        $this->listed($this->joanna, 'Earlier Lead', '2026-11-03', ['qty' => 2]);
        $this->listed($this->joanna, 'October Lead', '2026-10-20');
        $supervisor = $this->user(Role::CRA_SUPERVISOR, 'Supervisor');

        $this->actingAs($supervisor)->get(route('exclusive-leads.dashboard', ['period' => 'today']))
            ->assertViewHas('grid', fn (array $grid) => $this->cells($grid['products']) === ['ClearSight' => [1 => 1]]);

        $this->actingAs($supervisor)->get(route('exclusive-leads.dashboard', ['period' => 'year', 'product' => 'ClearSight', 'qty' => 2]))
            ->assertViewHas('grid', fn (array $grid) => $this->cells($grid['products']) === ['ClearSight' => [1 => 2, 2 => 1]])
            ->assertSee('Earlier Lead')
            ->assertDontSee('Today Lead');
    }

    public function test_it_counts_the_cras_callbacks_due_and_reactivation_and_opens_the_call_back_list(): void
    {
        $this->listed($this->joanna, 'Call Me Back', '2026-11-02', ['callback_date' => '2026-11-06', 'status' => 'busy_callback', 'contact_date' => '2026-11-02']);
        $this->listed($this->joanna, 'Reach Again', '2026-11-02', ['status' => 'inactive', 'contact_date' => '2026-11-02']);
        $this->listed($this->joanna, 'Active Customer', '2026-11-02', ['status' => 'active', 'contact_date' => '2026-11-02']);
        $supervisor = $this->user(Role::CRA_SUPERVISOR, 'Supervisor');

        $this->actingAs($supervisor)->get(route('exclusive-leads.dashboard'))
            ->assertViewHas('callbacks', 1)
            ->assertViewHas('reactivation', 1)
            ->assertSee(route('exclusive-leads.index', ['cra' => $this->joanna->id, 'show' => 'unprocessed', 'full' => 1, 'list' => 'callback']));
    }

    public function test_copy_all_gives_every_number_of_the_picked_cell_each_customer_once(): void
    {
        $this->listed($this->joanna, 'Ana', '2026-11-04', ['phone_number' => '09171111111', 'est_out_of_stock_date' => '2026-11-10']);
        $this->listed($this->joanna, 'Ana Again', '2026-11-05', ['phone_number' => '+639171111111', 'est_out_of_stock_date' => '2026-11-12']);
        $this->listed($this->joanna, 'Bea', '2026-11-05', ['phone_number' => '09172222222', 'est_out_of_stock_date' => '2026-11-08']);
        $this->listed($this->joanna, 'Two Bottles', '2026-11-05', ['phone_number' => '09173333333', 'qty' => 2]);
        $supervisor = $this->user(Role::CRA_SUPERVISOR, 'Supervisor');

        $this->actingAs($supervisor)->getJson(route('exclusive-leads.dashboard.phones', ['product' => 'ClearSight', 'qty' => 1]))
            ->assertOk()
            ->assertExactJson(['phones' => ['09172222222', '09171111111']]);

        $this->actingAs($this->joanna)->getJson(route('exclusive-leads.dashboard.phones'))->assertForbidden();
    }

    public function test_a_search_by_name_or_number_lists_the_periods_matching_customers_from_any_cell(): void
    {
        $this->listed($this->joanna, 'Bernardet Venida', '2026-11-04', ['phone_number' => '09193007651', 'qty' => 2]);
        $this->listed($this->joanna, 'Ronaldo Elegado', '2026-11-05', ['phone_number' => '09531042390']);
        $this->listed($this->joanna, 'October Bernardet', '2026-10-20');
        $this->listed($this->user(Role::CRA, 'Regina', '2026-10-02'), 'Bernardet Other', '2026-11-04');
        $supervisor = $this->user(Role::CRA_SUPERVISOR, 'Supervisor');

        // By name: this month's only, whatever cell is picked; another CRA's and last month's left out.
        $this->actingAs($supervisor)->get(route('exclusive-leads.dashboard', ['product' => 'ClearSight', 'qty' => 1, 'q' => 'bernardet']))
            ->assertOk()
            ->assertSee('Search “bernardet” · 1 customer')
            ->assertSee('Bernardet Venida')
            ->assertDontSee('Ronaldo Elegado')
            ->assertDontSee('October Bernardet')
            ->assertDontSee('Bernardet Other');

        // By number, typed with +63.
        $this->actingAs($supervisor)->get(route('exclusive-leads.dashboard', ['q' => '+63 953 104 2390']))
            ->assertSee('Ronaldo Elegado')
            ->assertDontSee('Bernardet Venida');

        $this->actingAs($supervisor)->get(route('exclusive-leads.dashboard', ['q' => 'nobody']))
            ->assertSee('No customer of Joanna\'s matches “nobody” for this month.');

        // Copy all gives the search's numbers.
        $this->actingAs($supervisor)->getJson(route('exclusive-leads.dashboard.phones', ['q' => 'bernardet']))
            ->assertExactJson(['phones' => ['09193007651']]);
    }

    public function test_the_customer_list_expands_full_size_keeping_the_cell_and_search(): void
    {
        foreach (range(1, 30) as $n) {
            $this->listed($this->joanna, 'Customer '.$n, '2026-11-04');
        }
        $supervisor = $this->user(Role::CRA_SUPERVISOR, 'Supervisor');

        $this->actingAs($supervisor)->get(route('exclusive-leads.dashboard', ['product' => 'ClearSight', 'qty' => 1]))
            ->assertSee(route('exclusive-leads.dashboard', ['cra' => $this->joanna->id, 'period' => 'month', 'product' => 'ClearSight', 'qty' => 1, 'full' => 1]));

        // Full size: every one of the 30 on one page, no sidebar.
        $this->actingAs($supervisor)->get(route('exclusive-leads.dashboard', ['product' => 'ClearSight', 'qty' => 1, 'full' => 1]))
            ->assertOk()
            ->assertViewIs('exclusive-leads.dashboard-full')
            ->assertViewHas('leads', fn ($leads) => $leads->count() === 30)
            ->assertDontSee('Customers by product × bottles ordered');

        $this->actingAs($supervisor)->get(route('exclusive-leads.dashboard', ['q' => 'Customer 12', 'full' => 1]))
            ->assertViewHas('leads', fn ($leads) => $leads->pluck('customer_name')->all() === ['Customer 12'])
            ->assertSee('<input type="hidden" name="full" value="1">', false);
    }

    public function test_repeat_retained_and_orders_count_only_customers_on_the_cras_list(): void
    {
        $bought = ['repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-11-06'];
        $repeat = $this->listed($this->joanna, 'Ana Repeat', '2026-11-06', $bought);
        $this->listed($this->joanna, 'Bea Retained', '2026-11-06', ['lead_type' => Lead::TYPE_FSD, ...$bought]);
        // Bought today but on an earlier list only, or on no list at all: left out of today.
        $this->listed($this->joanna, 'Cora Earlier List', '2026-11-05', $bought);
        $notListed = Lead::create([
            'order_id' => 'POS-77', 'customer_name' => 'Dina Not Listed', 'phone_number' => '09170000077', 'product_name' => 'ClearSight',
            'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-10-01', 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $this->joanna->id, 'listed_on' => '2026-11-06', ...$bought,
        ]);
        $seller = PancakeEngagement::staffKey($this->joanna->pancake_name);
        $order = fn (string $id, Lead $lead, int $status, float $total) => PancakeOrder::create([
            'pancake_order_id' => $id, 'ordered_on' => '2026-11-06', 'seller_name' => $seller, 'status' => $status, 'total_price' => $total,
            'phone_key' => substr(preg_replace('/\D/', '', $lead->phone_number), -10), 'conversion_type' => PancakeOrder::SEGMENTATION,
            'items' => [['name' => 'ClearSight', 'qty' => 1]],
        ]);
        $order('1', $repeat, 1, 1200);
        $order('2', $repeat, 6, 900);
        $order('3', $notListed, 1, 5000);

        $exclusive = app(ExclusiveLeads::class);
        $today = CarbonImmutable::parse('2026-11-06');

        $this->assertSame(['repeat' => 1, 'retained' => 1], $exclusive->repeatsBetween($this->joanna, $today, $today));
        $this->assertSame(['repeat' => 2, 'retained' => 1], $exclusive->repeatsBetween($this->joanna, $today->startOfMonth(), $today));
        // The canceled order and the customer not on the list stay out.
        $this->assertSame(['orders' => 1, 'gross' => 1200.0], $exclusive->ordersBetween($this->joanna, $today, $today));

        $this->actingAs($this->user(Role::CRA_SUPERVISOR, 'Supervisor'))->get(route('exclusive-leads.dashboard', ['period' => 'today']))
            ->assertOk()
            ->assertSeeTextInOrder(['Repeat', '1', 'Retained', '1', 'Confirmed orders', '1', 'Gross sales', '₱1,200']);
    }
}
