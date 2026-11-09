<?php

namespace Tests\Feature;

use App\Models\ExclusiveListLead;
use App\Models\Lead;
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
            ->assertSeeInOrder(['ClearSight · 1 bottle · 3 customers', 'Ana Runs Out Soon', 'Nov 8 · in 2 days', 'Bea Runs Out Later', 'Cora Already Out', 'Nov 1 · out'])
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
}
