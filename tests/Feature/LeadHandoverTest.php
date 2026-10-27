<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\LeadTransfer;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadHandoverTest extends TestCase
{
    use RefreshDatabase;

    private User $rose;

    private User $regina;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', 'Asia/Manila'));
        $cra = Role::firstWhere('slug', Role::CRA)->id;
        $this->rose = User::create(['email' => 'rose@example.com', 'display_name' => 'Rose-An', 'pancake_name' => 'CRD Rose-An Orbaneja', 'role_id' => $cra, 'is_active' => true]);
        $this->regina = User::create(['email' => 'regina@example.com', 'display_name' => 'Regina', 'pancake_name' => 'CRD REJ', 'role_id' => $cra, 'is_active' => true]);
        $this->lead = Lead::create([
            'order_id' => '1348401', 'customer_name' => 'Ernie Almario', 'phone_number' => '09165752354', 'product_name' => 'CanPro',
            'qty' => 1, 'delivered_date' => '2026-08-15', 'consumption_days' => 19, 'est_out_of_stock_date' => '2026-09-03',
            'listed_on' => '2026-09-27', 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $this->rose->id,
        ]);
        $this->order('1360505', '2026-08-30', 'CRD ROSE-AN ORBANEJA');
        $this->order('1384365', '2026-10-25', 'CRD REJ');
        // Canceled: doesn't count.
        $this->order('1390000', '2026-10-26', 'CRD ROSE-AN ORBANEJA', ['status' => 6]);
    }

    private function order(string $id, string $day, string $seller, array $fields = []): PancakeOrder
    {
        return PancakeOrder::create([
            'pancake_order_id' => $id, 'ordered_on' => $day, 'ordered_at' => "{$day} 01:00:00", 'seller_name' => $seller,
            'customer_name' => 'Ernie Almario', 'phone_key' => '9165752354', 'status' => 3, 'total_price' => 2599, 'non_crd' => false, ...$fields,
        ]);
    }

    public function test_the_cra_moves_a_pending_lead_to_the_cra_of_the_customers_latest_order(): void
    {
        $this->actingAs($this->rose)->getJson(route('segmentation.handover.show', $this->lead))
            ->assertOk()->assertJson(['cra' => ['name' => 'Regina', 'ordered_on' => 'Oct 25']]);

        $this->actingAs($this->rose)->postJson(route('segmentation.handover.store', $this->lead))->assertOk();

        $lead = $this->lead->fresh();
        $this->assertSame($this->regina->id, $lead->assigned_to);
        $this->assertSame(Lead::today()->toDateString(), $lead->listed_on->toDateString());
        $this->assertSame([$this->rose->id, $this->regina->id, $this->rose->id], [LeadTransfer::sole()->from_user_id, LeadTransfer::sole()->to_user_id, LeadTransfer::sole()->transferred_by]);
        // Rose's Sep 27 still counts it.
        $this->assertSame([$this->rose->id, '2026-09-27'], [LeadMove::sole()->assigned_to, LeadMove::sole()->listed_on->toDateString()]);
    }

    public function test_no_transfer_when_the_latest_order_is_the_lead_cras_own_or_the_lead_was_worked(): void
    {
        $this->order('1391000', '2026-10-26', 'CRD ROSE-AN ORBANEJA');
        $this->actingAs($this->rose)->getJson(route('segmentation.handover.show', $this->lead))->assertOk()->assertJson(['cra' => null]);

        PancakeOrder::where('pancake_order_id', '1391000')->delete();
        $this->lead->update(['status' => 'active', 'contact_date' => '2026-10-27']);
        $this->actingAs($this->rose)->postJson(route('segmentation.handover.store', $this->lead))->assertStatus(422);
        $this->assertSame($this->rose->id, $this->lead->fresh()->assigned_to);
    }

    public function test_another_cra_cannot_transfer_the_lead(): void
    {
        $this->actingAs($this->regina)->postJson(route('segmentation.handover.store', $this->lead))->assertForbidden();
        $this->assertSame($this->rose->id, $this->lead->fresh()->assigned_to);
    }
}
