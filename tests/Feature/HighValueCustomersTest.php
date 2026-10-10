<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\CustomerLink;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HighValueCustomersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $lhea;

    private User $regina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Manila'));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        $this->lhea = $this->cra('lhea');
        $this->regina = $this->cra('regina');
        Product::create(['name' => 'Pterygium', 'srp' => 499]);
    }

    private function cra(string $name): User
    {
        return User::create(['email' => "{$name}@example.com", 'display_name' => ucfirst($name), 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
    }

    /**
     * A delivered order (CRD = handled by a CRA) with its Pancake POS total and items.
     */
    private function delivered(string $id, string $team, string $name, string $phone, string $date, float $total, array $items = []): void
    {
        LogisticsOrder::remember([['order_id' => $id, 'team' => $team, 'customer_name' => $name, 'phone_number' => $phone,
            'product' => 'Pterygium', 'qty' => null, 'delivered_date' => $date]]);
        PancakeOrder::create(['pancake_order_id' => $id, 'ordered_on' => CarbonImmutable::parse($date)->subDays(3), 'phone_key' => substr($phone, -10),
            'total_price' => $total, 'status' => 3, 'items' => $items]);
    }

    /**
     * Ana: CRA-handled, AOV ₱1,200 → High AOV. Ben: CRA-handled, AOV ₱500 → not listed. Cara: FSD only, AOV ₱2,000 → not listed.
     * Dina: 30 Pterygium (₱499 × 30 = CLTV) → VIP. Rose: two merged numbers, ₱800 + ₱1,400 → AOV ₱1,100 → High AOV once.
     */
    private function customers(): void
    {
        $this->delivered('A1', 'crd', 'Ana Cruz', '9171111111', '2026-09-01', 1200);
        $this->delivered('B1', 'crd', 'Ben Reyes', '9172222222', '2026-09-01', 500);
        $this->delivered('C1', 'fsd', 'Cara Diaz', '9173333333', '2026-09-01', 2000);
        $this->delivered('D1', 'crd', 'Dina Lim', '9174444444', '2026-08-01', 7485, [['name' => 'Pterygium', 'qty' => 15]]);
        $this->delivered('D2', 'fsd', 'Dina Lim', '9174444444', '2026-09-20', 7485, [['name' => 'Pterygium', 'qty' => 15]]);
        $this->delivered('R1', 'crd', 'Rose Ramirez', '9175555555', '2026-08-15', 800);
        $this->delivered('R2', 'fsd', 'Rose Ramirez', '9176666666', '2026-09-15', 1400);
        CustomerLink::create(['phone_key' => '9176666666', 'primary_phone_key' => '9175555555']);
    }

    public function test_lists_cra_handled_high_aov_and_vip_customers_with_merged_numbers_as_one(): void
    {
        $this->customers();

        $this->actingAs($this->owner)->get(route('customers.high-value'))->assertOk()
            ->assertViewHas('counts', ['high_aov' => 3, 'vip' => 1, 'unassigned' => 3])
            ->assertSee('Ana Cruz')->assertSee('Rose Ramirez')->assertSee('9175555555 / 9176666666')->assertSee('₱1,100.00')
            ->assertSeeInOrder(['Dina Lim', 'VIP'])
            ->assertDontSee('Ben Reyes')->assertDontSee('Cara Diaz');

        // VIP only, and per order: each delivered order with its own amount.
        $this->get(route('customers.high-value', ['list' => 'vip', 'view' => 'orders']))->assertOk()
            ->assertSeeInOrder(['D2', 'Dina Lim', '₱7,485.00', 'D1', 'Dina Lim'])
            ->assertDontSee('Ana Cruz');
    }

    public function test_a_cra_claims_customers_nobody_has_and_a_supervisor_sets_anyone(): void
    {
        $this->customers();
        $update = fn (User $user, string $phone, array $data) => $this->actingAs($user)->patchJson(route('customers.high-value.update', $phone), $data);

        // CRAs see the list without the rest of the Customer Database.
        $this->actingAs($this->lhea)->get(route('customers.high-value'))->assertOk()->assertSee('Ana Cruz');
        $this->actingAs($this->lhea)->get(route('customers.index'))->assertForbidden();

        $update($this->lhea, '9171111111', ['assigned_cra_id' => $this->lhea->id])->assertOk();
        $update($this->regina, '9171111111', ['assigned_cra_id' => $this->regina->id])->assertForbidden();
        $update($this->lhea, '9171111111', ['point_of_contact' => 'call', 'buyer_type' => 'reseller'])->assertOk();
        // A merged customer's other number saves on the customer.
        $update($this->regina, '9176666666', ['assigned_cra_id' => $this->regina->id])->assertOk();
        // Not on the list.
        $update($this->lhea, '9172222222', ['assigned_cra_id' => $this->lhea->id])->assertNotFound();

        $ana = CustomerAccount::firstWhere('phone_key', '9171111111');
        $this->assertSame([$this->lhea->id, 'call', 'reseller'], [$ana->assigned_cra_id, $ana->point_of_contact, $ana->buyer_type]);
        $this->assertSame($this->regina->id, CustomerAccount::firstWhere('phone_key', '9175555555')->assigned_cra_id);

        $supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $update($supervisor, '9171111111', ['assigned_cra_id' => $this->regina->id])->assertOk();
        $this->assertSame($this->regina->id, $ana->fresh()->assigned_cra_id);

        $this->actingAs($this->regina)->get(route('customers.high-value', ['cra' => 'mine']))
            ->assertSee('Ana Cruz')->assertSee('Rose Ramirez')->assertDontSee('Dina Lim');
    }

    public function test_leads_go_to_the_customers_cra_and_new_high_value_customers_keep_the_cra_they_land_on(): void
    {
        $this->customers();
        CustomerAccount::create(['phone_key' => '9175555555', 'assigned_cra_id' => $this->regina->id]);
        $lead = fn (string $phone, string $name) => Lead::create([
            'order_id' => 'L'.$phone, 'customer_name' => $name, 'phone_number' => '0'.$phone, 'product_name' => 'Pterygium', 'qty' => 1,
            'delivered_date' => '2026-09-20', 'consumption_days' => 20, 'est_out_of_stock_date' => '2026-10-10', 'lead_type' => Lead::TYPE_CRD,
        ]);
        // Rose's lead comes in on her merged number; Ana and Ben have no CRA yet.
        $rose = $lead('9176666666', 'Rose Ramirez');
        $ana = $lead('9171111111', 'Ana Cruz');
        $ben = $lead('9172222222', 'Ben Reyes');

        app(LeadGenerator::class)->assign(CarbonImmutable::parse('2026-10-10'));

        $this->assertSame($this->regina->id, $rose->fresh()->assigned_to);
        // Ana is High AOV and new to the list: she keeps the CRA her lead landed on. Ben isn't listed.
        $this->assertSame($ana->fresh()->assigned_to, CustomerAccount::firstWhere('phone_key', '9171111111')->assigned_cra_id);
        $this->assertNull(CustomerAccount::firstWhere('phone_key', '9172222222'));
        $this->assertNotNull($ben->fresh()->assigned_to);
    }
}
