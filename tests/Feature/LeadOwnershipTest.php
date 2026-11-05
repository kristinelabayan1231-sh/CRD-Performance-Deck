<?php

namespace Tests\Feature;

use App\Models\CustomerLink;
use App\Models\CvrOrderEntry;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\LeadTransfer;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\BacklogFill;
use App\Services\CustomerOwners;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private User $ben;

    private CarbonImmutable $day;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00', 'Asia/Manila'));
        $this->day = CarbonImmutable::parse('2026-10-09');
        $this->anna = $this->cra('Anna', 'CRD ANNA');
        $this->ben = $this->cra('Ben', 'CRD BEN');
    }

    private function cra(string $name, string $pancake, bool $active = true): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => $active,
        ]);
    }

    private function sale(string $phone, string $seller, string $on, int $status = 3): PancakeOrder
    {
        $this->seq++;

        return PancakeOrder::create([
            'pancake_order_id' => (string) (5000 + $this->seq), 'ordered_on' => $on, 'ordered_at' => $on.' 02:00:00',
            'seller_name' => $seller, 'phone_key' => $phone, 'status' => $status, 'total_price' => 800,
        ]);
    }

    private function lead(string $phone, string $outOfStock, ?User $cra = null, array $extra = []): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => (string) (9000 + $this->seq), 'customer_name' => 'C'.$this->seq, 'phone_number' => '0'.$phone,
            'product_name' => 'Pterygium', 'qty' => 1, 'delivered_date' => '2026-06-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => $outOfStock, 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra?->id, ...$extra,
        ]);
    }

    public function test_a_cra_owns_a_customer_after_handling_two_of_their_orders_the_latest_such_cra_winning(): void
    {
        // 9171111111: Anna twice. 9172222222: Anna once (not yet owned). 9173333333: Anna twice in June, Ben twice in August.
        $this->sale('9171111111', 'CRD ANNA', '2026-05-01');
        $this->sale('9171111111', 'CRD ANNA', '2026-06-01');
        $this->sale('9172222222', 'CRD ANNA', '2026-06-01');
        $this->sale('9173333333', 'CRD ANNA', '2026-06-01');
        $this->sale('9173333333', 'CRD ANNA', '2026-06-10');
        $this->sale('9173333333', 'CRD BEN', '2026-08-01');
        $this->sale('9173333333', 'CRD BEN', '2026-08-10');
        // 9174444444: one canceled order doesn't count; one order claimed for Ben in the CVR Tracker counts for him.
        $this->sale('9174444444', 'CRD ANNA', '2026-07-01', status: 6);
        $this->sale('9174444444', 'CRD ANNA', '2026-07-02');
        $claimed = $this->sale('9174444444', 'CRD ANNA', '2026-07-03');
        CvrOrderEntry::create(['pancake_order_id' => $claimed->pancake_order_id, 'user_id' => $this->ben->id]);
        // 9175555555 merged into 9176666666: one customer, two orders by Ben.
        CustomerLink::create(['phone_key' => '9175555555', 'primary_phone_key' => '9176666666']);
        $this->sale('9175555555', 'CRD BEN', '2026-07-01');
        $this->sale('9176666666', 'CRD BEN', '2026-07-05');

        $owners = app(CustomerOwners::class)->for(['9171111111', '9172222222', '9173333333', '9174444444', '9175555555']);

        $this->assertSame([9171111111 => $this->anna->id, 9173333333 => $this->ben->id, 9175555555 => $this->ben->id], $owners);
    }

    public function test_a_deactivated_cra_owns_no_customers(): void
    {
        $former = $this->cra('Lea', 'CRD LEA', active: false);
        $this->sale('9171111111', 'CRD LEA', '2026-05-01');
        $this->sale('9171111111', 'CRD LEA', '2026-06-01');

        $this->assertSame([], app(CustomerOwners::class)->for(['9171111111']));
        $this->assertFalse($former->is_active);
    }

    public function test_customers_with_a_cra_go_to_them_first_and_the_rest_are_split_evenly(): void
    {
        foreach (['9171111111', '9172222222', '9173333333'] as $phone) {
            $this->sale($phone, 'CRD ANNA', '2026-05-01');
            $this->sale($phone, 'CRD ANNA', '2026-06-01');
            $this->lead($phone, '2026-10-09');
        }
        $unowned = collect(['9178000001', '9178000002', '9178000003', '9178000004', '9178000005'])
            ->map(fn (string $phone) => $this->lead($phone, '2026-10-09'));

        app(LeadGenerator::class)->assign($this->day);

        // Anna's 3 customers go to her; with 3 already, the next unowned ones go to Ben until they're level, then alternate.
        $counts = Lead::whereDate('listed_on', $this->day)->get()->countBy('assigned_to');
        $this->assertSame([$this->anna->id => 4, $this->ben->id => 4], $counts->sortKeys()->all());
        $this->assertSame(1, $unowned->filter(fn (Lead $lead) => $lead->fresh()->assigned_to === $this->anna->id)->count());
    }

    public function test_todays_untouched_leads_move_to_their_customers_cra_but_worked_or_transferred_ones_stay(): void
    {
        foreach (['9171111111', '9172222222', '9173333333'] as $phone) {
            $this->sale($phone, 'CRD ANNA', '2026-05-01');
            $this->sale($phone, 'CRD ANNA', '2026-06-01');
        }
        $untouched = $this->lead('9171111111', '2026-10-09', $this->ben);
        $processed = $this->lead('9172222222', '2026-10-09', $this->ben, ['status' => 'pjr_drop_call']);
        $transferred = $this->lead('9173333333', '2026-10-09', $this->ben);
        LeadTransfer::create(['lead_id' => $transferred->id, 'from_user_id' => $this->anna->id, 'to_user_id' => $this->ben->id, 'transferred_by' => $this->anna->id]);

        app(LeadGenerator::class)->assign($this->day);

        $this->assertSame($this->anna->id, $untouched->fresh()->assigned_to);
        $this->assertSame($this->ben->id, $processed->fresh()->assigned_to);
        $this->assertSame($this->ben->id, $transferred->fresh()->assigned_to);
    }

    public function test_the_fill_gives_a_customers_waiting_leads_to_their_cra_only(): void
    {
        config(['segmentation.max_leads_per_cra' => 3]);
        foreach (['9171111111', '9172222222'] as $phone) {
            $this->sale($phone, 'CRD ANNA', '2026-05-01');
            $this->sale($phone, 'CRD ANNA', '2026-06-01');
        }
        // Anna's customers: one in the pool, one in Ben's untouched backlog.
        $fromPool = $this->lead('9171111111', '2026-09-01', null, ['listed_on' => '2026-09-01']);
        $fromBen = $this->lead('9172222222', '2026-09-02', $this->ben, ['listed_on' => '2026-09-02']);
        $poolLeads = collect(range(1, 4))->map(fn (int $i) => $this->lead('917900000'.$i, '2026-09-0'.$i, null, ['listed_on' => '2026-09-0'.$i]));
        $processedPool = $this->lead('9179999999', '2026-08-01', null, ['listed_on' => '2026-08-01', 'status' => 'pjr_drop_call']);

        // Only Ben is on duty: Anna's customers wait for her.
        app(BacklogFill::class)->fill($this->day, collect([$this->ben]));
        $this->assertNull($fromPool->fresh()->assigned_to);
        $this->assertSame('2026-09-02', $fromBen->fresh()->listed_on->toDateString());
        $this->assertNull($processedPool->fresh()->assigned_to);

        app(BacklogFill::class)->fill($this->day, collect([$this->anna]));

        $this->assertSame([$this->anna->id, '2026-10-09'], [$fromPool->fresh()->assigned_to, $fromPool->fresh()->listed_on->toDateString()]);
        $this->assertSame([$this->anna->id, '2026-10-09'], [$fromBen->fresh()->assigned_to, $fromBen->fresh()->listed_on->toDateString()]);
        // Ben's Sep 2 still counts the lead that left him.
        $this->assertTrue(LeadMove::where('lead_id', $fromBen->id)->where('assigned_to', $this->ben->id)->whereDate('listed_on', '2026-09-02')->exists());
        $this->assertNull($processedPool->fresh()->assigned_to);
        $this->assertSame(3, Lead::where('assigned_to', $this->anna->id)->whereDate('listed_on', $this->day)->count());
        $this->assertSame(3, Lead::where('assigned_to', $this->ben->id)->whereDate('listed_on', $this->day)->count());
        // Ben took 3 of the 4 pool leads; Anna her 2 customers, then the last pool lead.
        $this->assertSame(0, $poolLeads->filter(fn (Lead $lead) => $lead->fresh()->assigned_to === null)->count());
    }
}
