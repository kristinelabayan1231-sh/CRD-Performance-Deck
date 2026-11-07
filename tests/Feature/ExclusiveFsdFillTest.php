<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\ExclusiveListLead;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExclusiveFsdFillTest extends TestCase
{
    use RefreshDatabase;

    private User $joanna;

    private User $regina;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00', 'Asia/Manila'));
        config(['exclusive_leads.flat_month_from' => '2026-11-08', 'exclusive_leads.build_from' => '2026-11-01']);
        Product::create(['name' => 'Pterygium', 'srp' => 499, 'consumption_days' => 15]);
        $this->joanna = $this->cra('Joanna Rose', 'CRD ANNA PACLIBARE 2', '2026-11-01');
        $this->regina = $this->cra('Regina', 'CRD REJ VERGARA');
    }

    private function cra(string $name, string $pancake, ?string $exclusiveFrom = null): User
    {
        return User::create([
            'email' => strtolower(strtok($name, ' ')).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true, 'exclusive_from' => $exclusiveFrom,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function delivery(string $phoneKey, string $day = '2026-08-01', array $extra = []): LogisticsOrder
    {
        return LogisticsOrder::create([
            'order_id' => 'D'.++$this->seq, 'team' => LogisticsOrder::TEAM_FSD, 'source' => LogisticsOrder::SOURCE_LOGISTICS, 'customer_name' => 'Buyer '.$this->seq,
            'phone_number' => '0'.$phoneKey, 'phone_key' => $phoneKey, 'product' => 'Pterygium', 'qty' => 1, 'non_crd' => false, 'delivered_date' => $day, ...$extra,
        ]);
    }

    private function order(string $phoneKey, string $day, ?string $seller = null): void
    {
        PancakeOrder::create(['pancake_order_id' => 'P'.++$this->seq, 'ordered_on' => $day, 'phone_key' => $phoneKey, 'seller_name' => $seller, 'status' => 3, 'total_price' => 300, 'items' => []]);
    }

    public function test_only_fsd_customers_no_cra_handled_past_their_out_of_stock_date_become_leads_held_for_her(): void
    {
        $eligible = $this->delivery('9170000001', extra: ['qty' => 2]);
        $this->order('9170000001', '2026-08-01');

        $this->delivery('9170000002');
        Lead::create([
            'order_id' => 'OLD', 'customer_name' => 'Has lead', 'phone_number' => '09170000002', 'product_name' => 'Pterygium', 'qty' => 1,
            'delivered_date' => '2026-06-01', 'consumption_days' => 15, 'est_out_of_stock_date' => '2026-06-15', 'lead_type' => Lead::TYPE_FSD,
        ]);
        $this->delivery('9170000003');
        CustomerAccount::create(['phone_key' => '9170000003', 'assigned_cra_id' => $this->regina->id]);
        $this->delivery('9170000004');
        $this->order('9170000004', '2026-07-01', 'CRD REJ VERGARA');
        $this->delivery('9170000005');
        $this->order('9170000005', '2026-09-01');
        $this->delivery('9170000006', '2026-11-01');
        $this->delivery('9170000007', extra: ['team' => LogisticsOrder::TEAM_CRD]);
        $this->delivery('9170000008', extra: ['non_crd' => true]);
        $this->delivery('9170000009', extra: ['product' => 'Not on the list']);
        $this->delivery('9170000010', '2026-07-01');
        $this->delivery('9170000010', '2026-09-01', ['team' => LogisticsOrder::TEAM_CRD]);

        $this->artisan('leads:fill-exclusive-fsd', ['--apply' => true])->assertSuccessful();

        $lead = Lead::firstWhere('order_id', $eligible->order_id);
        $this->assertSame(2, Lead::count());
        $this->assertNull($lead->assigned_to);
        $this->assertSame(Lead::TYPE_FSD, $lead->lead_type);
        $this->assertSame('2026-08-30', $lead->est_out_of_stock_date->toDateString());
        $this->assertSame('2026-08-30', $lead->listed_on->toDateString());
        $this->assertSame([$lead->id], ExclusiveReservedLead::where('user_id', $this->joanna->id)->pluck('lead_id')->all());
    }

    public function test_a_delivery_with_no_quantity_takes_it_from_the_order_items_and_is_skipped_when_it_has_none(): void
    {
        $withItems = $this->delivery('9170000001', extra: ['qty' => null]);
        PancakeOrder::create(['pancake_order_id' => $withItems->order_id, 'ordered_on' => '2026-08-01', 'phone_key' => '9170000001', 'status' => 3, 'total_price' => 300,
            'items' => [['name' => 'Pterygium', 'qty' => 3], ['name' => 'Not on the list', 'qty' => 9]]]);
        $this->delivery('9170000002', extra: ['qty' => null]);

        $this->artisan('leads:fill-exclusive-fsd', ['--apply' => true])->assertSuccessful();

        $lead = Lead::sole();
        $this->assertSame($withItems->order_id, $lead->order_id);
        $this->assertSame(3, $lead->qty);
        $this->assertSame('2026-09-14', $lead->est_out_of_stock_date->toDateString());
    }

    public function test_the_report_adds_nothing(): void
    {
        $this->delivery('9170000001');

        $this->artisan('leads:fill-exclusive-fsd')
            ->expectsOutputToContain('found 1 of 7000 wanted')
            ->expectsOutputToContain('Nothing was added')
            ->assertSuccessful();

        $this->assertSame(0, Lead::count());
    }

    public function test_it_adds_only_what_the_month_is_short_of_with_the_newest_deliveries_first_and_a_second_run_adds_none(): void
    {
        config(['exclusive_leads.month_target' => 1]);
        $this->delivery('9170000001', '2026-06-01');
        $newest = $this->delivery('9170000002', '2026-08-01');

        $this->artisan('leads:fill-exclusive-fsd', ['--apply' => true])->assertSuccessful();
        $this->artisan('leads:fill-exclusive-fsd', ['--apply' => true])->assertSuccessful();

        $this->assertSame([$newest->order_id], Lead::pluck('order_id')->all());
    }

    public function test_no_other_cra_gets_a_held_lead_and_her_list_takes_it(): void
    {
        $this->delivery('9170000001');
        $this->artisan('leads:fill-exclusive-fsd', ['--apply' => true])->assertSuccessful();
        $lead = Lead::firstOrFail();

        app(LeadGenerator::class)->assign($lead->listed_on);
        $this->assertNull($lead->fresh()->assigned_to);

        app(ExclusiveLeads::class)->syncCra($this->joanna->fresh(), CarbonImmutable::parse('2026-11-08'));

        $this->assertSame($this->joanna->id, $lead->fresh()->assigned_to);
        $this->assertSame(ExclusiveLeads::RANDOM, ExclusiveListLead::where('lead_id', $lead->id)->value('source'));
    }
}
