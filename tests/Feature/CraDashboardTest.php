<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\CustomerClaim;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerOwners;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CraDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $regina;

    private User $july;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['customers.reassign_from' => '2026-11-11', 'customers.reassign_after_days' => 30]);
        $this->regina = $this->cra('Regina', 'CRD REJ');
        $this->july = $this->cra('July', 'CRD JULY ANN');
    }

    private function cra(string $name, string $pancake): User
    {
        return User::create(['email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
    }

    private function at(string $day): void
    {
        $this->travelTo(CarbonImmutable::parse($day.' 10:00', 'Asia/Manila'));
    }

    /**
     * A CRD-delivered order sold by $cra, ordered three days before it was delivered.
     */
    private function delivered(User $cra, string $name, string $phone, string $deliveredOn): void
    {
        $id = 'O'.++$this->seq;
        LogisticsOrder::remember([['order_id' => $id, 'team' => 'crd', 'customer_name' => $name, 'phone_number' => $phone,
            'product' => 'Pterygium', 'qty' => 1, 'delivered_date' => $deliveredOn]]);
        PancakeOrder::create(['pancake_order_id' => $id, 'ordered_on' => CarbonImmutable::parse($deliveredOn)->subDays(3), 'phone_key' => $phone,
            'seller_name' => $cra->pancake_name, 'status' => 3, 'total_price' => 499]);
    }

    private function lead(string $phone, User $cra, array $extra = []): Lead
    {
        return Lead::create(['order_id' => 'L'.++$this->seq, 'customer_name' => 'Lead '.$this->seq, 'phone_number' => $phone, 'product_name' => 'Pterygium',
            'qty' => 1, 'delivered_date' => '2026-11-01', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-12-01', 'lead_type' => Lead::TYPE_CRD,
            'assigned_to' => $cra->id, 'listed_on' => '2026-12-01', ...$extra]);
    }

    public function test_a_cra_opens_on_their_cra_dashboard_and_everyone_else_on_the_dashboard(): void
    {
        $this->at('2026-11-20');

        $this->actingAs($this->july)->get('/')->assertRedirect(route('cra-dashboard'));
        $this->actingAs(User::create(['email' => 'boss@example.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]))
            ->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_only_deliveries_from_the_rules_start_count_so_the_first_open_30_days_after_it(): void
    {
        $this->delivered($this->regina, 'Before The Rule', '9171111111', '2026-11-10');
        $this->delivered($this->regina, 'First Day', '9172222222', '2026-11-11');

        $this->at('2026-12-10');
        $this->actingAs($this->july)->get(route('cra-dashboard'))->assertOk()
            ->assertDontSee('First Day')
            ->assertSee('the first customers open up on Dec 11, 2026');

        $this->at('2026-12-11');
        $this->actingAs($this->july)->get(route('cra-dashboard'))->assertOk()
            ->assertSee('First Day')->assertDontSee('Before The Rule');
    }

    public function test_another_cras_customer_without_a_reorder_in_30_days_is_up_for_claiming(): void
    {
        $this->delivered($this->regina, 'Open Customer', '9171111111', '2026-11-12');
        $this->delivered($this->regina, 'Recent Customer', '9172222222', '2026-11-20');
        // Ordered again after the delivery: still on its way, so not open.
        $this->delivered($this->regina, 'Reordered Customer', '9173333333', '2026-11-12');
        PancakeOrder::create(['pancake_order_id' => 'NEW', 'ordered_on' => '2026-12-05', 'phone_key' => '9173333333', 'seller_name' => 'CRD REJ', 'status' => 0]);
        $this->at('2026-12-15');

        $this->actingAs($this->july)->get(route('cra-dashboard'))->assertOk()
            ->assertSee('Open Customer')->assertSee('Claim')
            ->assertDontSee('Recent Customer')->assertDontSee('Reordered Customer');
        // Her own customer isn't hers to claim; she sees it as at risk instead.
        $this->actingAs($this->regina)->get(route('cra-dashboard'))->assertOk()
            ->assertDontSee('Open Customer')->assertSee('1 open to other CRAs');
    }

    public function test_claiming_makes_the_customer_and_their_uncontacted_leads_the_cras_until_30_days_without_a_reorder(): void
    {
        $this->delivered($this->regina, 'Open Customer', '9171111111', '2026-10-20');
        $this->delivered($this->regina, 'Open Customer', '9171111111', '2026-11-12');
        CustomerAccount::create(['phone_key' => '9171111111', 'assigned_cra_id' => $this->regina->id]);
        $untouched = $this->lead('9171111111', $this->regina);
        $contacted = $this->lead('9171111111', $this->regina, ['contact_date' => '2026-12-01']);
        $this->at('2026-12-15');

        $this->actingAs($this->july)->post(route('cra-dashboard.claim'), ['phone_key' => '9171111111', 'name' => 'Open Customer'])
            ->assertRedirect()->assertSessionHas('status', 'Open Customer is now yours · 1 lead moved to your list today.');

        $this->assertSame($this->july->id, CustomerClaim::firstWhere('phone_key', '9171111111')->user_id);
        $this->assertSame($this->july->id, CustomerAccount::firstWhere('phone_key', '9171111111')->assigned_cra_id);
        $this->assertSame($this->july->id, $untouched->fresh()->assigned_to);
        $this->assertSame('2026-12-15', $untouched->fresh()->listed_on->toDateString());
        $this->assertSame($this->regina->id, $contacted->fresh()->assigned_to);
        // The claim beats Regina's two orders.
        $this->assertSame([9171111111 => $this->july->id], app(CustomerOwners::class)->for(['9171111111']));

        // The 30 days start again from the claim.
        $this->actingAs($this->regina)->post(route('cra-dashboard.claim'), ['phone_key' => '9171111111'])
            ->assertSessionHasErrors(['claim' => 'This customer is no longer up for reassignment.']);
        $this->at('2027-01-14');
        $this->actingAs($this->regina)->get(route('cra-dashboard'))->assertOk()->assertSee('Open Customer');
    }

    public function test_a_cra_cannot_claim_their_own_customer_and_only_cras_can_claim(): void
    {
        $this->delivered($this->regina, 'Open Customer', '9171111111', '2026-11-12');
        $this->at('2026-12-15');

        $this->actingAs($this->regina)->post(route('cra-dashboard.claim'), ['phone_key' => '9171111111'])
            ->assertSessionHasErrors(['claim' => 'This customer is already yours.']);
        $this->actingAs(User::create(['email' => 'boss@example.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]))
            ->post(route('cra-dashboard.claim'), ['phone_key' => '9171111111'])->assertForbidden();
        $this->assertDatabaseCount('customer_claims', 0);
    }

    public function test_the_dashboard_shows_todays_pending_leads_with_a_link_to_the_tracker(): void
    {
        $this->at('2026-12-15');
        $this->lead('9171111111', $this->july, ['listed_on' => '2026-12-15']);
        $this->lead('9172222222', $this->july, ['listed_on' => '2026-12-15', 'status' => 'active']);

        $this->actingAs($this->july)->get(route('cra-dashboard'))->assertOk()
            ->assertSee('Hi, July!')
            ->assertSeeInOrder(['Pending today', '1', 'of 2 leads'])
            ->assertSee(route('segmentation.index'));
    }
}
