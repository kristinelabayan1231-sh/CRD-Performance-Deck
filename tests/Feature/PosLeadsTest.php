<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\PosLeads;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PosLeadsTest extends TestCase
{
    use RefreshDatabase;

    private User $lhea;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Manila'));
        $this->today = CarbonImmutable::parse('2026-10-16');
        Product::create(['name' => 'Pterygium', 'consumption_days' => 15]);
        $this->lhea = User::create([
            'email' => 'lhea@example.com', 'display_name' => 'Lhea', 'pancake_name' => 'CRD Lhei',
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    private function order(string $id, string $phone, array $fields = []): PancakeOrder
    {
        return PancakeOrder::create([
            'pancake_order_id' => $id, 'ordered_on' => '2026-10-16', 'ordered_at' => '2026-10-16 01:00:00',
            'seller_name' => 'CRD LHEI', 'customer_name' => "Customer {$id}", 'phone_key' => $phone, 'status' => 0,
            'total_price' => 1500, 'items' => [['name' => 'Pterygium', 'qty' => 2]], 'non_crd' => false, ...$fields,
        ]);
    }

    private function trackerLead(string $phone, string $leadDay): Lead
    {
        return Lead::create([
            'order_id' => "L{$phone}", 'customer_name' => 'Known', 'phone_number' => $phone, 'product_name' => 'Pterygium',
            'qty' => 1, 'delivered_date' => '2026-08-01', 'consumption_days' => 15, 'est_out_of_stock_date' => $leadDay,
            'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $this->lhea->id,
        ]);
    }

    public function test_a_cra_sale_to_a_customer_not_in_the_tracker_becomes_a_catered_repeat_purchase(): void
    {
        $this->order('5001', '9171111111');

        $this->assertSame(['added' => 1, 'marked' => 0], app(PosLeads::class)->record($this->today));

        $lead = Lead::sole();
        $this->assertSame('POS-5001', $lead->order_id);
        $this->assertSame($this->lhea->id, $lead->assigned_to);
        $this->assertSame(['Pterygium', 2, '2026-10-16'], [$lead->product_name, $lead->qty, $lead->est_out_of_stock_date->toDateString()]);
        $this->assertSame(['yes', '2026-10-16'], [$lead->repeat_purchase, $lead->repeat_purchase_on->toDateString()]);
        $this->assertTrue($lead->isProcessed());
        // No earlier CRA-handled order: an FSD Lead.
        $this->assertSame(Lead::TYPE_FSD, $lead->lead_type);
    }

    public function test_a_customer_with_an_earlier_cra_handled_order_is_a_crd_lead(): void
    {
        // Earlier sale from a CRA account, and a CRD delivery for another customer.
        $this->order('4001', '9172222222', ['ordered_on' => '2026-09-20', 'ordered_at' => '2026-09-20 01:00:00']);
        LogisticsOrder::create(['order_id' => 'D1', 'team' => LogisticsOrder::TEAM_CRD, 'customer_name' => 'Cy', 'phone_number' => '09173333333',
            'phone_key' => '9173333333', 'product' => 'Pterygium', 'delivered_date' => '2026-09-01']);
        $this->order('5002', '9172222222');
        $this->order('5003', '9173333333');

        app(PosLeads::class)->record($this->today);

        $this->assertSame([Lead::TYPE_CRD, Lead::TYPE_CRD], Lead::orderBy('order_id')->pluck('lead_type')->all());
    }

    public function test_a_cra_sale_to_a_tracker_customer_marks_their_latest_lead_bought(): void
    {
        $earlier = $this->trackerLead('09174444444', '2026-08-20');   // stored as 09…: same customer
        $latest = $this->trackerLead('9174444444', '2026-09-05');
        $later = $this->trackerLead('639174444444', '2026-11-01');     // lead day after the order
        $this->order('5004', '9174444444');

        $this->assertSame(['added' => 0, 'marked' => 1], app(PosLeads::class)->record($this->today));

        $this->assertSame(['yes', '2026-10-16'], [$latest->fresh()->repeat_purchase, $latest->fresh()->repeat_purchase_on->toDateString()]);
        $this->assertTrue($latest->fresh()->isProcessed());
        $this->assertSame([null, null], [$earlier->fresh()->repeat_purchase, $later->fresh()->repeat_purchase]);

        // Already Yes: left as it was, dated the first purchase.
        $this->order('5012', '9174444444', ['ordered_on' => '2026-10-17', 'ordered_at' => '2026-10-17 01:00:00']);
        $this->assertSame(['added' => 0, 'marked' => 0], app(PosLeads::class)->record(CarbonImmutable::parse('2026-10-17')));
        $this->assertSame('2026-10-16', $latest->fresh()->repeat_purchase_on->toDateString());
    }

    public function test_other_orders_are_left_out(): void
    {
        $this->order('5005', '9175555555', ['status' => PancakeOrder::CANCELED]);
        $this->order('5006', '9176666666', ['seller_name' => 'KIM GAN']);   // not a CRA account
        $this->order('5007', '9177777777', ['non_crd' => true]);           // no listed product
        $this->order('5008', '9178888888', ['ordered_on' => '2026-10-15', 'ordered_at' => '2026-10-15 01:00:00']);

        $this->assertSame(['added' => 0, 'marked' => 0], app(PosLeads::class)->record($this->today));
    }

    public function test_the_command_records_earlier_days(): void
    {
        $this->order('5013', '9171212121', ['ordered_on' => '2026-10-01', 'ordered_at' => '2026-10-01 01:00:00']);
        $this->order('5014', '9171313131', ['ordered_on' => '2026-10-15', 'ordered_at' => '2026-10-15 01:00:00']);

        $this->artisan('leads:record-pos-sales', ['from' => '2026-10-01', 'to' => '2026-10-15'])
            ->expectsOutputToContain('2 customers added')->assertSuccessful();

        $this->assertSame(['2026-10-01', '2026-10-15'], Lead::orderBy('order_id')->get()->map(fn (Lead $lead) => $lead->repeat_purchase_on->toDateString())->all());
    }

    public function test_running_again_adds_each_customer_once(): void
    {
        $this->order('5009', '9179999999');
        $this->order('5010', '9179999999', ['ordered_at' => '2026-10-16 03:00:00']);

        app(PosLeads::class)->record($this->today);
        app(PosLeads::class)->record($this->today);

        $this->assertSame(['POS-5009'], Lead::pluck('order_id')->all());
    }

    public function test_pos_leads_do_not_use_up_a_cras_daily_quota(): void
    {
        config(['services.shecom.key' => 'test-key']);
        Http::fake(['*' => Http::response(['count' => 0, 'stock_outs' => []])]);
        User::create(['email' => 'joanna@example.com', 'display_name' => 'Joanna', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        $this->order('5011', '9170000011');
        app(PosLeads::class)->record($this->today);
        Lead::create([
            'order_id' => 'NEW1', 'customer_name' => 'New', 'phone_number' => '9170000012', 'product_name' => 'Pterygium', 'qty' => 1,
            'delivered_date' => '2026-10-01', 'consumption_days' => 15, 'est_out_of_stock_date' => '2026-10-16', 'lead_type' => Lead::TYPE_FSD,
        ]);

        app(LeadGenerator::class)->assign($this->today);

        // Lhea (the earlier account) gets it on the tie, as though her POS lead weren't there.
        $this->assertSame($this->lhea->id, Lead::firstWhere('order_id', 'NEW1')->assigned_to);
    }
}
