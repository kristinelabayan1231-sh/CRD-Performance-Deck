<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateMissingLeadsTest extends TestCase
{
    use RefreshDatabase;

    private User $joanna;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00', 'Asia/Manila'));
        Product::create(['name' => 'Pterygium', 'consumption_days' => 15]);
        $this->joanna = User::create(['email' => 'joanna@example.com', 'display_name' => 'Joanna Rose', 'pancake_name' => 'CRD JOANNA PACLIBARE',
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
    }

    /**
     * A delivered order and the Pancake order $seller sold three days before it.
     */
    private function delivered(string $phone, string $deliveredOn, int $qty, string $seller, string $team = 'crd'): string
    {
        $id = (string) (5000 + ++$this->seq);
        LogisticsOrder::remember([['order_id' => $id, 'team' => $team, 'customer_name' => 'Customer '.$phone, 'phone_number' => '0'.$phone,
            'product' => 'Pterygium', 'qty' => $qty, 'delivered_date' => $deliveredOn]]);
        PancakeOrder::create(['pancake_order_id' => $id, 'ordered_on' => CarbonImmutable::parse($deliveredOn)->subDays(3), 'phone_key' => $phone,
            'seller_name' => $seller, 'status' => 3, 'total_price' => 949]);

        return $id;
    }

    public function test_missing_leads_go_to_their_cras_backlog_or_the_pool_on_their_out_of_stock_day(): void
    {
        // Delivered May 12, 4 × 15 days: out of stock Jul 10, Joanna's customer.
        $yolanda = $this->delivered('9369254493', '2026-05-12', 4, 'CRD JOANNA PACLIBARE');
        // FSD customer no CRA has handled: the pool.
        $fsd = $this->delivered('9174444444', '2026-08-02', 1, 'FSD SELLER', 'fsd');
        // Already has a lead for the delivery.
        $withLead = $this->delivered('9171111111', '2026-05-12', 1, 'CRD JOANNA PACLIBARE');
        Lead::create(['order_id' => $withLead, 'customer_name' => 'Has Lead', 'phone_number' => '09171111111', 'product_name' => 'Pterygium',
            'qty' => 1, 'delivered_date' => '2026-05-12', 'consumption_days' => 15, 'est_out_of_stock_date' => '2026-05-26', 'lead_type' => Lead::TYPE_CRD]);
        // Ordered again after the delivery.
        $this->delivered('9172222222', '2026-05-12', 1, 'CRD JOANNA PACLIBARE');
        PancakeOrder::create(['pancake_order_id' => 'R1', 'ordered_on' => '2026-06-01', 'phone_key' => '9172222222', 'seller_name' => 'FSD SELLER', 'status' => 1, 'total_price' => 499]);
        // Not out of stock yet.
        $this->delivered('9173333333', '2026-11-10', 1, 'CRD JOANNA PACLIBARE');

        $this->artisan('leads:create-missing')->expectsOutputToContain('2 missing leads. Dry run')->assertSuccessful();
        $this->assertSame(1, Lead::count());

        $this->artisan('leads:create-missing --apply')->expectsOutputToContain('Created 2 of 2 missing leads.')->assertSuccessful();

        $lead = Lead::firstWhere('order_id', $yolanda);
        $this->assertSame([$this->joanna->id, '2026-07-10', '2026-07-10', Lead::TYPE_CRD, 4], [
            $lead->assigned_to, $lead->listed_on->toDateString(), $lead->est_out_of_stock_date->toDateString(), $lead->lead_type, $lead->qty,
        ]);
        $this->assertNull($lead->status);
        $pool = Lead::firstWhere('order_id', $fsd);
        $this->assertSame([null, Lead::TYPE_FSD, '2026-08-16'], [$pool->assigned_to, $pool->lead_type, $pool->listed_on->toDateString()]);

        // Run again: nothing left to make.
        $this->artisan('leads:create-missing --apply')->expectsOutputToContain('Created 0 of 0 missing leads.')->assertSuccessful();
    }
}
