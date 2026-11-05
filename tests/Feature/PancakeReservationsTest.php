<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\PancakeReservation;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PancakeReservationsTest extends TestCase
{
    use RefreshDatabase;

    private User $lhea;

    private User $mia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-24 14:20', 'Asia/Manila'));
        Product::create(['name' => 'Pterygium', 'consumption_days' => 15]);
        $this->lhea = $this->cra('lhea@example.com', 'Lhea');
        $this->mia = $this->cra('mia@example.com', 'Mia');
    }

    private function cra(string $email, string $name): User
    {
        return User::create(['email' => $email, 'display_name' => $name, 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
    }

    private function delivery(string $phoneKey, string $deliveredOn, array $fields = []): LogisticsOrder
    {
        return LogisticsOrder::create([
            'order_id' => "D{$deliveredOn}", 'team' => LogisticsOrder::TEAM_CRD, 'source' => LogisticsOrder::SOURCE_LOGISTICS,
            'customer_name' => 'Ana Cruz', 'phone_number' => '0'.$phoneKey, 'phone_key' => $phoneKey,
            'product' => 'Pterygium Drops', 'qty' => 2, 'non_crd' => false, 'delivered_date' => $deliveredOn, ...$fields,
        ]);
    }

    public function test_adding_a_reservation_fills_it_from_their_last_delivered_order_and_tags_it_reserved_and_contacted_now(): void
    {
        $this->delivery('9171234567', '2026-10-01');

        $this->actingAs($this->lhea)->post(route('segmentation.reservations.store'), ['phone_number' => '+63 917 123 4567'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $reservation = PancakeReservation::sole();
        $this->assertSame('Ana Cruz', $reservation->customer_name);
        $this->assertSame($this->lhea->id, $reservation->assigned_to);
        $this->assertSame(['Pterygium', 2, '2026-10-01', '2026-10-30', Lead::TYPE_CRD], [
            $reservation->product_name, $reservation->qty, $reservation->delivered_date->toDateString(),
            $reservation->est_out_of_stock_date->toDateString(), $reservation->lead_type,
        ]);
        $this->assertSame(['hot', 'reserve', '2026-10-24', '14'], [
            $reservation->customer_tag, $reservation->repeat_purchase, $reservation->contact_date->toDateString(), $reservation->contact_time,
        ]);
        $this->assertSame(0, Lead::count());
    }

    public function test_their_lead_is_used_when_it_is_as_recent_as_their_last_delivery(): void
    {
        $this->delivery('9171234567', '2026-09-01');
        Lead::create([
            'order_id' => 'L1', 'customer_name' => 'Ana C.', 'phone_number' => '09171234567', 'product_name' => 'Pterygium',
            'qty' => 3, 'delivered_date' => '2026-09-01', 'consumption_days' => 15, 'est_out_of_stock_date' => '2026-10-15',
            'lead_type' => Lead::TYPE_FSD,
        ]);

        $this->actingAs($this->lhea)->getJson(route('segmentation.reservations.lookup', ['phone' => '9171234567']))
            ->assertOk()
            ->assertJsonPath('customer.filled_from', 'lead')
            ->assertJsonPath('customer.qty', 3)
            ->assertJsonPath('customer.lead_type', Lead::TYPE_FSD)
            ->assertJsonPath('summary', 'From their latest lead: Ana C. · 3 × Pterygium · delivered Sep 1, 2026 · out of stock Oct 15, 2026 · FSD Lead');
    }

    public function test_a_lead_added_from_a_cras_pos_sale_is_passed_over_for_their_delivered_order(): void
    {
        $this->delivery('9171234567', '2026-09-01');
        Lead::create([
            'order_id' => Lead::POS_PREFIX.'77', 'customer_name' => 'Ana Cruz', 'phone_number' => '9171234567', 'product_name' => 'Pterygium',
            'qty' => 1, 'delivered_date' => '2026-10-24', 'consumption_days' => 15, 'est_out_of_stock_date' => '2026-09-24',
            'lead_type' => Lead::TYPE_CRD,
        ]);

        $this->actingAs($this->lhea)->getJson(route('segmentation.reservations.lookup', ['phone' => '9171234567']))
            ->assertJsonPath('customer.filled_from', 'delivered')
            ->assertJsonPath('customer.delivered_date', '2026-09-01');
    }

    public function test_a_customer_with_only_an_undelivered_pancake_order_gets_its_product_and_qty(): void
    {
        PancakeOrder::create([
            'pancake_order_id' => 'P1', 'ordered_on' => '2026-10-20', 'ordered_at' => '2026-10-20 02:00:00', 'customer_name' => 'Ben Uy',
            'phone_key' => '9179999999', 'status' => 0, 'total_price' => 900, 'items' => [['name' => 'Pterygium', 'qty' => 1]], 'non_crd' => false,
        ]);

        $this->actingAs($this->lhea)->getJson(route('segmentation.reservations.lookup', ['phone' => '09179999999']))
            ->assertJsonPath('customer.filled_from', 'pancake')
            ->assertJsonPath('customer.product_name', 'Pterygium')
            ->assertJsonPath('customer.delivered_date', null)
            ->assertJsonPath('customer.lead_type', Lead::TYPE_FSD);
    }

    public function test_a_number_with_no_order_needs_the_customers_name(): void
    {
        $this->actingAs($this->lhea)->post(route('segmentation.reservations.store'), ['phone_number' => '09170000000'])
            ->assertSessionHasErrors(['reservation' => 'No order found for that number: type the customer\'s name too.']);

        $this->actingAs($this->lhea)->post(route('segmentation.reservations.store'), ['phone_number' => '09170000000', 'customer_name' => 'New Buyer'])
            ->assertSessionHasNoErrors();

        $reservation = PancakeReservation::sole();
        $this->assertSame('New Buyer', $reservation->customer_name);
        $this->assertNull($reservation->product_name);
    }

    public function test_an_incomplete_contact_number_is_refused(): void
    {
        $this->actingAs($this->lhea)->post(route('segmentation.reservations.store'), ['phone_number' => '0917', 'customer_name' => 'X'])
            ->assertSessionHasErrors(['phone_number' => 'Enter the customer\'s full contact number.']);

        $this->assertSame(0, PancakeReservation::count());
    }

    public function test_a_cra_saves_cells_on_their_own_reservation_but_not_on_another_cras(): void
    {
        $own = PancakeReservation::factory()->create(['assigned_to' => $this->lhea->id]);
        $other = PancakeReservation::factory()->create(['assigned_to' => $this->mia->id]);

        $this->actingAs($this->lhea)->patchJson(route('segmentation.reservations.update', $own), ['status' => 'active', 'repeat_purchase' => 'yes'])
            ->assertOk();
        $this->actingAs($this->lhea)->patchJson(route('segmentation.reservations.update', $other), ['status' => 'active'])
            ->assertForbidden();
        $this->actingAs($this->lhea)->patchJson(route('segmentation.reservations.update', $own), ['assigned_to' => $this->mia->id])
            ->assertForbidden();

        $own->refresh();
        $this->assertSame(['active', 'yes', '2026-10-24'], [$own->status, $own->repeat_purchase, $own->repeat_purchase_on->toDateString()]);
        $this->assertNull($other->fresh()->status);
    }

    public function test_the_tab_lists_the_cras_own_reservations(): void
    {
        PancakeReservation::factory()->create(['customer_name' => 'Lhea Customer', 'assigned_to' => $this->lhea->id]);
        PancakeReservation::factory()->create(['customer_name' => 'Mia Customer', 'assigned_to' => $this->mia->id]);

        $this->actingAs($this->lhea)->get(route('segmentation.index', ['show' => 'unprocessed', 'full' => 1, 'list' => 'reserved_pancake']))
            ->assertOk()
            ->assertSee('Reserved - Pancake')
            ->assertSee('Lhea Customer')
            ->assertDontSee('Mia Customer');
    }

    public function test_a_cra_removes_only_their_own_reservation(): void
    {
        $own = PancakeReservation::factory()->create(['assigned_to' => $this->lhea->id]);
        $other = PancakeReservation::factory()->create(['assigned_to' => $this->mia->id]);

        $this->actingAs($this->lhea)->delete(route('segmentation.reservations.destroy', $other))->assertForbidden();
        $this->actingAs($this->lhea)->delete(route('segmentation.reservations.destroy', $own))->assertRedirect();

        $this->assertSame([$other->id], PancakeReservation::pluck('id')->all());
    }
}
