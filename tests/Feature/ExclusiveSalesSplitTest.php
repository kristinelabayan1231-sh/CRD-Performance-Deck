<?php

namespace Tests\Feature;

use App\Models\ExclusiveListLead;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\ExclusiveLeads;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExclusiveSalesSplitTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-18';

    private User $joanna;

    private User $regina;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->travelTo(CarbonImmutable::parse(self::DAY.' 10:00', 'Asia/Manila'));
        config(['exclusive_leads.pancake_leads_from' => self::DAY]);
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

    private function order(User $cra, string $type, float $amount, string $phoneKey, array $extra = []): PancakeOrder
    {
        $this->seq++;

        return PancakeOrder::create([
            'pancake_order_id' => (string) (1391000 + $this->seq), 'ordered_on' => self::DAY, 'ordered_at' => self::DAY.' 02:00:00',
            'seller_name' => $cra->pancake_name, 'customer_name' => "Buyer {$this->seq}", 'phone_number' => '0'.$phoneKey,
            'phone_key' => $phoneKey, 'status' => 1, 'total_price' => $amount, 'page_name' => 'Pterygium Eye Repair Drops',
            'tags' => [], 'conversion_type' => $type, 'non_crd' => false, ...$extra,
        ]);
    }

    /**
     * A lead of Joanna's on her exclusive list of $listDay.
     */
    private function exclusiveLead(string $phoneKey, string $listDay): Lead
    {
        $this->seq++;
        $lead = Lead::create([
            'order_id' => "o{$this->seq}", 'customer_name' => "Lead {$this->seq}", 'phone_number' => '0'.$phoneKey,
            'product_name' => 'Pterygium', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => $listDay, 'listed_on' => $listDay, 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $this->joanna->id,
        ]);
        ExclusiveListLead::create(['user_id' => $this->joanna->id, 'list_date' => $listDay, 'lead_id' => $lead->id, 'source' => ExclusiveLeads::RANDOM]);

        return $lead;
    }

    /**
     * Exclusive: her assigned leads' customers, this month's and last month's. Normal: customers she catered on Pancake,
     * one of them canceled (in gross only), and one assigned to her but since moved to another CRA.
     */
    private function joannasDay(): void
    {
        $this->exclusiveLead('9170000001', '2026-11-10');
        $this->exclusiveLead('9170000004', '2026-10-30');
        $this->order($this->joanna, PancakeOrder::BROADCAST, 1000, '9170000001');
        $this->order($this->joanna, PancakeOrder::SEGMENTATION, 500, '9170000002');
        $this->order($this->joanna, PancakeOrder::SEGMENTATION, 200, '9170000003', ['status' => PancakeOrder::CANCELED]);
        $this->order($this->joanna, PancakeOrder::BROADCAST, 300, '9170000004');
        $this->exclusiveLead('9170000006', '2026-11-12')->update(['assigned_to' => $this->regina->id]);
        $this->order($this->joanna, PancakeOrder::BROADCAST, 100, '9170000006');
        // Not on her lists, but tagged CRD - ANNA (EXCLUSIVE LEADS) on an earlier order: an exclusive lead too.
        $this->order($this->joanna, PancakeOrder::BROADCAST, 50, '9170000007', ['ordered_on' => '2026-06-01', 'status' => 3, 'tags' => [749]]);
        $this->order($this->joanna, PancakeOrder::BROADCAST, 400, '9170000007');
    }

    public function test_her_cvr_tracker_labels_each_order_and_splits_exclusive_and_normal_sales(): void
    {
        $this->joannasDay();

        $response = $this->actingAs($this->joanna)->get(route('cvr-tracker.index', ['date' => self::DAY]))->assertOk()
            ->assertSee('Exclusive leads sales')->assertSee('Normal leads sales')->assertSee('Overall sales');

        $data = $response->viewData('data');
        $this->assertSame(['9170000001' => true, '9170000002' => false, '9170000004' => true, '9170000006' => false, '9170000007' => true],
            collect($data['orders'])->mapWithKeys(fn (array $row) => [substr($row['phone'], 1) => $row['exclusive']])->all());
        $this->assertEquals(['exclusive' => ['orders' => 3, 'gross' => 1700.0], 'normal' => ['orders' => 2, 'gross' => 800.0]], $data['summary']['split']);
        $this->assertEquals(2500.0, $data['summary']['gross']);
    }

    public function test_her_dashboard_shows_exclusive_normal_and_overall_sales_matching_her_running_sales(): void
    {
        $this->joannasDay();

        $response = $this->actingAs($this->joanna)->get(route('cra-dashboard'))->assertOk()->assertSee('Sales by lead source');

        $split = $response->viewData('salesSplit');
        $this->assertEquals(['orders' => 3, 'gross' => 1700.0], $split['today']['exclusive']);
        $this->assertEquals(['orders' => 2, 'gross' => 800.0], $split['today']['normal']);
        $this->assertEquals(['orders' => 5, 'gross' => 2500.0], $split['month']['overall']);
        $this->assertEquals($response->viewData('sales')['today'], $split['today']['overall']['gross']);
    }

    public function test_other_cras_have_no_split(): void
    {
        $this->order($this->regina, PancakeOrder::BROADCAST, 700, '9170000005');

        $this->actingAs($this->regina)->get(route('cvr-tracker.index', ['date' => self::DAY]))->assertOk()
            ->assertDontSee('Exclusive / Normal')->assertViewHas('data', fn (array $data) => $data['summary']['split'] === null);
        $this->actingAs($this->regina)->get(route('cra-dashboard'))->assertOk()
            ->assertDontSee('Sales by lead source')->assertViewHas('salesSplit', null);
    }

    public function test_her_own_pos_sale_on_her_list_from_before_she_caters_pancake_leads_is_not_an_assigned_lead(): void
    {
        $sale = $this->posSale('2026-11-10');
        ExclusiveListLead::create(['user_id' => $this->joanna->id, 'list_date' => '2026-11-10', 'lead_id' => $sale->id, 'source' => ExclusiveLeads::POS]);
        $key = substr($sale->phone_number, 1);

        $exclusive = app(ExclusiveLeads::class);
        $this->assertArrayHasKey($key, $exclusive->assignedCustomers($this->joanna, CarbonImmutable::parse('2026-11-17')));
        $this->assertArrayNotHasKey($key, $exclusive->assignedCustomers($this->joanna, CarbonImmutable::parse(self::DAY)));
    }

    public function test_from_the_day_she_caters_pancake_leads_her_own_pos_sales_stay_off_her_exclusive_list(): void
    {
        config(['exclusive_leads.build_from' => '2026-11-17', 'exclusive_leads.pancake_leads_from' => '2026-11-18']);
        $before = $this->posSale('2026-11-17');
        $after = $this->posSale('2026-11-18');

        app(ExclusiveLeads::class)->syncCra($this->joanna, CarbonImmutable::parse('2026-11-18'));

        $this->assertSame(ExclusiveLeads::POS, ExclusiveListLead::where('lead_id', $before->id)->value('source'));
        $this->assertFalse(ExclusiveListLead::where('lead_id', $after->id)->exists());
    }

    private function posSale(string $day): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => "POS-{$this->seq}", 'customer_name' => "Sale {$this->seq}", 'phone_number' => '0917100000'.$this->seq,
            'product_name' => 'Pterygium', 'qty' => 1, 'delivered_date' => $day, 'consumption_days' => 30,
            'est_out_of_stock_date' => $day, 'listed_on' => $day, 'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $this->joanna->id, 'repeat_purchase' => 'yes',
        ]);
    }
}
