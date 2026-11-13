<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\PancakeClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LeadConflictsTest extends TestCase
{
    use RefreshDatabase;

    private User $regina;

    private User $july;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00', 'Asia/Manila'));
        $this->regina = $this->cra('Regina', 'CRD REJ VERGARA');
        $this->july = $this->cra('July Ann', 'CRD JULY ANN');
    }

    private function cra(string $name, string $pancake): User
    {
        return User::create(['email' => strtolower(strtok($name, ' ')).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
    }

    /**
     * A customer with an open lead held by $holder, $orders orders sold by $seller and, optionally, a High AOV / VIP CRA.
     */
    private function customer(string $name, string $phone, User $holder, User $seller, int $orders, ?User $highValue = null): void
    {
        foreach (range(1, $orders) as $n) {
            PancakeOrder::create(['pancake_order_id' => 'O'.++$this->seq, 'ordered_on' => '2026-0'.$n.'-10', 'phone_key' => $phone,
                'seller_name' => $seller->pancake_name, 'status' => 3, 'total_price' => 999]);
        }
        if ($highValue !== null) {
            CustomerAccount::create(['phone_key' => $phone, 'assigned_cra_id' => $highValue->id, 'assigned_at' => now()]);
        }
        Lead::create(['order_id' => 'L'.++$this->seq, 'customer_name' => $name, 'phone_number' => '0'.$phone, 'product_name' => 'Pterygium',
            'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-10-13',
            'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $holder->id]);
    }

    public function test_supervisors_see_customers_whose_high_value_cra_and_most_orders_cra_disagree(): void
    {
        // High AOV CRA Regina, but July handled more orders.
        $this->customer('Split Customer', '9171111111', $this->regina, $this->july, 2, $this->regina);
        // Everyone agrees.
        $this->customer('Agreed Customer', '9172222222', $this->july, $this->july, 2, $this->july);
        $supervisor = User::create(['email' => 'lead@example.com', 'display_name' => 'Lead', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);

        // Never worked out inside the page: nothing until the background job has run.
        $this->actingAs($supervisor)->get(route('cra-dashboard'))->assertOk()->assertDontSee('Check who gets these leads');
        $this->artisan('leads:refresh-conflicts')->assertSuccessful();

        $this->actingAs($supervisor)->get(route('cra-dashboard'))->assertOk()
            ->assertSee('Check who gets these leads')
            ->assertSeeInOrder(['Split Customer', 'Regina', 'Regina · High AOV', 'July Ann · 2 orders', 'Regina'])
            ->assertDontSee('Agreed Customer');

        $this->actingAs($this->july)->get(route('cra-dashboard'))->assertOk()->assertDontSee('Check who gets these leads');
    }

    private function supervisor(): User
    {
        return User::create(['email' => 'lead@example.com', 'display_name' => 'Lead', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
    }

    public function test_supervisor_assigns_a_listed_lead_to_another_cra_and_it_leaves_the_list(): void
    {
        $this->customer('Split Customer', '9171111111', $this->regina, $this->july, 2, $this->regina);
        $supervisor = $this->supervisor();
        $this->artisan('leads:refresh-conflicts')->assertSuccessful();
        $lead = Lead::firstWhere('customer_name', 'Split Customer');

        $this->actingAs($supervisor)->get(route('cra-dashboard'))->assertOk()
            ->assertSee(route('cra-dashboard.assign', $lead))->assertSee('Regina (has it)');

        $this->actingAs($supervisor)->from(route('cra-dashboard'))
            ->post(route('cra-dashboard.assign', $lead), ['to' => $this->july->id])
            ->assertRedirect(route('cra-dashboard'))->assertSessionHas('status', "Split Customer's lead is now July Ann's.");

        $lead->refresh();
        $this->assertSame($this->july->id, $lead->assigned_to);
        $this->assertSame($supervisor->id, $lead->assignment_checked_by);
        $this->assertDatabaseHas('lead_transfers', ['lead_id' => $lead->id, 'from_user_id' => $this->regina->id, 'to_user_id' => $this->july->id, 'transferred_by' => $supervisor->id]);

        // Gone at once, and stays gone after the next refresh.
        $this->actingAs($supervisor)->get(route('cra-dashboard'))->assertDontSee('Check who gets these leads');
        $this->artisan('leads:refresh-conflicts')->assertSuccessful();
        $this->actingAs($supervisor)->get(route('cra-dashboard'))->assertDontSee('Check who gets these leads');
    }

    /**
     * Split Customer's lead, held by Regina, whose own POS order carries the exclusive tag (and a broadcast tag).
     */
    private function taggedLead(): Lead
    {
        $this->customer('Split Customer', '9171111111', $this->regina, $this->july, 2, $this->regina);
        $lead = Lead::firstWhere('customer_name', 'Split Customer');
        $lead->update(['order_id' => '55501']);
        PancakeOrder::create(['pancake_order_id' => '55501', 'ordered_on' => '2026-09-01', 'phone_key' => '9171111111',
            'seller_name' => $this->regina->pancake_name, 'status' => 3, 'total_price' => 999, 'tags' => [12, config('exclusive_leads.order_tag.id')]]);

        return $lead;
    }

    public function test_assigning_a_lead_away_takes_the_exclusive_tag_off_its_order(): void
    {
        $lead = $this->taggedLead();
        $tagId = (int) config('exclusive_leads.order_tag.id');
        $this->mock(PancakeClient::class)->shouldReceive('removeOrderTag')->once()->with('55501', $tagId)
            ->andReturn(['before' => [12, $tagId], 'after' => [12]]);

        $this->actingAs($this->supervisor())->post(route('cra-dashboard.assign', $lead), ['to' => $this->july->id])
            ->assertSessionHas('status', "Split Customer's lead is now July Ann's. The ".config('exclusive_leads.order_tag.name').' tag was taken off its order.');

        $this->assertSame($this->july->id, $lead->refresh()->assigned_to);
        $this->assertSame([12], PancakeOrder::firstWhere('pancake_order_id', '55501')->tags);
    }

    public function test_the_lead_stays_put_when_pancake_refuses_to_take_the_tag_off(): void
    {
        $lead = $this->taggedLead();
        $this->mock(PancakeClient::class)->shouldReceive('removeOrderTag')->once()->andThrow(new RuntimeException("Couldn't reach Pancake to find order #55501. Nothing was changed."));

        $this->actingAs($this->supervisor())->post(route('cra-dashboard.assign', $lead), ['to' => $this->july->id])
            ->assertSessionHasErrors('assign');

        $this->assertSame($this->regina->id, $lead->refresh()->assigned_to);
        $this->assertNull($lead->assignment_checked_at);
        $this->assertContains((int) config('exclusive_leads.order_tag.id'), PancakeOrder::firstWhere('pancake_order_id', '55501')->tags);
    }

    public function test_keeping_a_tagged_lead_with_its_cra_leaves_the_tag_alone(): void
    {
        $lead = $this->taggedLead();
        $this->mock(PancakeClient::class)->shouldNotReceive('removeOrderTag');

        $this->actingAs($this->supervisor())->post(route('cra-dashboard.assign', $lead), ['to' => $this->regina->id])
            ->assertSessionHas('status', "Split Customer's lead stays with Regina.");
    }

    public function test_choosing_the_cra_who_has_the_lead_keeps_it_there_and_takes_it_off_the_list(): void
    {
        $this->customer('Split Customer', '9171111111', $this->regina, $this->july, 2, $this->regina);
        $supervisor = $this->supervisor();
        $lead = Lead::firstWhere('customer_name', 'Split Customer');

        $this->actingAs($supervisor)->post(route('cra-dashboard.assign', $lead), ['to' => $this->regina->id])
            ->assertSessionHas('status', "Split Customer's lead stays with Regina.");

        $this->assertSame($this->regina->id, $lead->refresh()->assigned_to);
        $this->assertNotNull($lead->assignment_checked_at);
        $this->assertDatabaseCount('lead_transfers', 0);
        $this->artisan('leads:refresh-conflicts')->assertSuccessful();
        $this->actingAs($supervisor)->get(route('cra-dashboard'))->assertDontSee('Check who gets these leads');
    }

    public function test_a_worked_lead_is_not_moved(): void
    {
        $this->customer('Split Customer', '9171111111', $this->regina, $this->july, 2, $this->regina);
        $lead = Lead::firstWhere('customer_name', 'Split Customer');
        $lead->forceFill(['processed_at' => now()])->save();

        $this->actingAs($this->supervisor())->post(route('cra-dashboard.assign', $lead), ['to' => $this->july->id])
            ->assertSessionHasErrors('assign');

        $this->assertSame($this->regina->id, $lead->refresh()->assigned_to);
        $this->assertNull($lead->assignment_checked_at);
    }

    public function test_only_supervisors_assign_and_only_to_an_active_cra(): void
    {
        $this->customer('Split Customer', '9171111111', $this->regina, $this->july, 2, $this->regina);
        $lead = Lead::firstWhere('customer_name', 'Split Customer');
        $former = $this->cra('Former', 'CRD FORMER');
        $former->update(['is_active' => false]);

        $this->actingAs($this->july)->post(route('cra-dashboard.assign', $lead), ['to' => $this->july->id])->assertForbidden();
        $this->actingAs($this->supervisor())->post(route('cra-dashboard.assign', $lead), ['to' => $former->id])->assertSessionHasErrors('to');

        $this->assertSame($this->regina->id, $lead->refresh()->assigned_to);
    }

    public function test_no_panel_when_every_customers_cras_agree(): void
    {
        $this->customer('Agreed Customer', '9172222222', $this->july, $this->july, 2, $this->july);
        $supervisor = User::create(['email' => 'lead@example.com', 'display_name' => 'Lead', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $this->artisan('leads:refresh-conflicts')->assertSuccessful();

        $this->actingAs($supervisor)->get(route('cra-dashboard'))->assertOk()->assertDontSee('Check who gets these leads');
    }
}
