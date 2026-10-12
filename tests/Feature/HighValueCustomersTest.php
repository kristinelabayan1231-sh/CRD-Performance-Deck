<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\CustomerHistory;
use App\Models\CustomerLink;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerDatabase;
use App\Services\HighValueCustomers;
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
     * A delivered order with its Pancake POS total, items and seller. Handled by a CRA when CRD-delivered or sold by a CRD account.
     */
    private function delivered(string $id, string $team, string $name, string $phone, string $date, float $total, array $items = [], ?string $seller = null): void
    {
        LogisticsOrder::remember([['order_id' => $id, 'team' => $team, 'customer_name' => $name, 'phone_number' => $phone,
            'product' => 'Pterygium', 'qty' => null, 'delivered_date' => $date]]);
        PancakeOrder::create(['pancake_order_id' => $id, 'ordered_on' => CarbonImmutable::parse($date)->subDays(3), 'phone_key' => substr($phone, -10),
            'total_price' => $total, 'status' => 3, 'items' => $items, 'seller_name' => $seller]);
    }

    /**
     * Ana: CRA-handled, AOV ₱1,200 → High AOV. Ben: CRA-handled, AOV ₱500 → not listed. Cara: FSD only, AOV ₱2,000 → not listed.
     * Dina: ₱14,970 on Pterygium (₱499 × 30 = CLTV) in CRA-handled orders, bought as packages → VIP; her FSD agent order doesn't count. Rose: two merged numbers,
     * ₱800 + ₱1,400 → AOV ₱1,100 → High AOV once. Edgar: ₱4,000 plus two deliveries Pancake has no amount for → AOV ₱4,000.
     */
    private function customers(): void
    {
        $this->delivered('A1', 'crd', 'Ana Cruz', '9171111111', '2026-09-01', 1200);
        $this->delivered('B1', 'crd', 'Ben Reyes', '9172222222', '2026-09-01', 500);
        $this->delivered('C1', 'fsd', 'Cara Diaz', '9173333333', '2026-09-01', 2000);
        $this->delivered('D1', 'crd', 'Dina Lim', '9174444444', '2026-08-01', 7485, [['name' => 'Pterygium', 'qty' => 1]]);
        $this->delivered('D2', 'fsd', 'Dina Lim', '9174444444', '2026-09-20', 7485, [['name' => 'Pterygium', 'qty' => 1]], 'CRD LHEI');
        // Not handled by a CRA: counts toward nothing (not her orders, spend, AOV or VIP).
        $this->delivered('D3', 'fsd', 'Dina Lim', '9174444444', '2026-09-25', 100, [['name' => 'Pterygium', 'qty' => 1]], 'FSD AGENT');
        $this->delivered('E1', 'crd', 'Edgar Recamara', '9108803020', '2026-09-01', 4000);
        LogisticsOrder::remember([
            ['order_id' => 'E2', 'team' => 'crd', 'customer_name' => 'Edgar Recamara', 'phone_number' => '9108803020', 'product' => 'CanPro', 'qty' => 1, 'delivered_date' => '2026-09-10'],
            ['order_id' => 'E3', 'team' => 'crd', 'customer_name' => 'Edgar Recamara', 'phone_number' => '9108803020', 'product' => 'CanPro', 'qty' => 1, 'delivered_date' => '2026-09-20'],
        ]);
        $this->delivered('R1', 'crd', 'Rose Ramirez', '9175555555', '2026-08-15', 800);
        $this->delivered('R2', 'fsd', 'Rose Ramirez', '9176666666', '2026-09-15', 1400, seller: 'CRD LHEI');
        CustomerLink::create(['phone_key' => '9176666666', 'primary_phone_key' => '9175555555']);
    }

    public function test_lists_cra_handled_high_aov_and_vip_customers_with_merged_numbers_as_one(): void
    {
        $this->customers();

        $this->actingAs($this->owner)->get(route('customers.high-value'))->assertOk()
            ->assertViewHas('counts', ['high_aov' => 4, 'vip' => 1, 'unassigned' => 4])
            ->assertSeeInOrder(['Edgar Recamara', '3', '₱4,000.00', '₱4,000.00'])
            ->assertSee('Ana Cruz')->assertSee('Rose Ramirez')->assertSee('9175555555 / 9176666666')->assertSee('₱1,100.00')
            ->assertSeeInOrder(['Dina Lim', 'VIP', 'Pterygium'])
            ->assertDontSee('Ben Reyes')->assertDontSee('Cara Diaz');

        // The Customer Database list shows the same pills next to their names.
        $this->get(route('customers.index'))->assertOk()
            ->assertSeeInOrder(['Dina Lim', 'VIP'])
            ->assertSeeInOrder(['Ana Cruz', 'HIGH AOV']);

        // VIP only, and per order: each delivered order with its own amount.
        $this->get(route('customers.high-value', ['list' => 'vip', 'view' => 'orders']))->assertOk()
            ->assertSeeInOrder(['D2', 'Dina Lim', '₱7,485.00', 'D1', 'Dina Lim'])
            ->assertDontSeeText('D3')
            ->assertDontSee('Ana Cruz');
    }

    public function test_a_cra_claims_customers_nobody_has_and_a_supervisor_sets_anyone(): void
    {
        $this->customers();
        $update = fn (User $user, string $phone, array $data) => $this->actingAs($user)->patchJson(route('customers.high-value.update', $phone), $data);

        // CRAs see the list without the rest of the Customer Database.
        $this->actingAs($this->lhea)->get(route('customers.high-value'))->assertOk()->assertSee('Ana Cruz');
        $this->actingAs($this->lhea)->get(route('customers.index'))->assertForbidden();
        // A row opens the customer's details and order history, for listed customers only.
        $this->get(route('customers.high-value'))->assertSee(route('customers.high-value.show', '9171111111'));
        $this->get(route('customers.high-value.show', '9171111111'))->assertOk()->assertSee('Ana Cruz')->assertSee('A1');
        $this->get(route('customers.high-value.show', '9172222222'))->assertNotFound();

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

    /**
     * CRAs with their own Pancake accounts; Anna's other account counts as hers (config alias).
     *
     * @return array{0: User, 1: User}
     */
    private function sellers(): array
    {
        $this->lhea->update(['pancake_name' => 'CRD Lhei']);
        $anna = $this->cra('anna');
        $anna->update(['pancake_name' => 'CRD ANNA PACLIBARE 2']);
        config(['customers.cra_account_aliases' => ['CRD JOANNA PACLIBARE' => 'CRD ANNA PACLIBARE 2']]);

        return [$this->lhea, $anna];
    }

    public function test_a_high_aov_customer_goes_to_the_cra_whose_order_brought_their_cra_running_aov_to_the_minimum(): void
    {
        [$lhea, $anna] = $this->sellers();
        $this->regina->update(['pancake_name' => 'CRD Regina']);
        // FSD orders don't count: Lhea ₱700 (AOV ₱700), Anna ₱1,200 (₱950), Regina ₱2,000 (₱1,300) → Regina.
        $this->delivered('M1', 'fsd', 'Mila Santos', '9177777777', '2026-08-01', 5000, seller: 'FSD AGENT');
        $this->delivered('M2', 'crd', 'Mila Santos', '9177777777', '2026-08-10', 700, seller: 'CRD LHEI');
        $this->delivered('M3', 'crd', 'Mila Santos', '9177777777', '2026-08-20', 1200, seller: 'CRD ANNA PACLIBARE 2');
        $this->delivered('M4', 'crd', 'Mila Santos', '9177777777', '2026-09-01', 2000, seller: 'CRD REGINA');
        // Nora's first CRA order already reaches it, sold on Anna's other account → Anna; Lhea's later order doesn't change that.
        $this->delivered('N1', 'crd', 'Nora Uy', '9178888888', '2026-08-01', 1500, seller: 'CRD JOANNA PACLIBARE');
        $this->delivered('N2', 'crd', 'Nora Uy', '9178888888', '2026-09-01', 1500, seller: 'CRD LHEI');
        // Olga's qualifying order was sold by a former CRA (inactive), so it goes to the next one: Lhea.
        $former = $this->cra('former');
        $former->update(['pancake_name' => 'CRD Former', 'is_active' => false]);
        $this->delivered('O1', 'crd', 'Olga Tan', '9179999999', '2026-08-01', 1500, seller: 'CRD FORMER');
        $this->delivered('O2', 'crd', 'Olga Tan', '9179999999', '2026-09-01', 1500, seller: 'CRD LHEI');

        $this->assertSame(
            ['9177777777' => $this->regina->id, '9178888888' => $anna->id, '9179999999' => $lhea->id],
            collect(app(HighValueCustomers::class)->firstReorderCras())->sortKeys()->all(),
        );
    }

    public function test_the_rule_replaces_a_customers_cra_but_an_admin_or_manager_change_always_wins(): void
    {
        [$lhea, $anna] = $this->sellers();
        $this->delivered('P1', 'crd', 'Pia Lopez', '9170000001', '2026-08-01', 1500, seller: 'CRD LHEI');
        CustomerAccount::create(['phone_key' => '9170000001', 'assigned_cra_id' => $this->regina->id]);

        $this->artisan('customers:assign-high-aov')->assertSuccessful();
        $this->assertSame($lhea->id, CustomerAccount::firstWhere('phone_key', '9170000001')->assigned_cra_id);

        // Lhea letting go of her own customer doesn't lock it: the rule gives Pia back to her.
        $this->actingAs($lhea)->patchJson(route('customers.high-value.update', '9170000001'), ['assigned_cra_id' => null])->assertOk();
        $this->artisan('customers:assign-high-aov')->assertSuccessful();
        $this->assertSame($lhea->id, CustomerAccount::firstWhere('phone_key', '9170000001')->assigned_cra_id);

        $this->actingAs($this->owner)->patchJson(route('customers.high-value.update', '9170000001'), ['assigned_cra_id' => $this->regina->id])->assertOk();
        // An earlier order by Anna now makes her the rule's answer; the edit still wins.
        $this->delivered('P0', 'crd', 'Pia Lopez', '9170000001', '2026-07-01', 1500, seller: 'CRD ANNA PACLIBARE 2');
        CustomerDatabase::flushCache();
        $this->assertSame($anna->id, app(HighValueCustomers::class)->firstReorderCras()['9170000001']);
        $this->artisan('customers:assign-high-aov')->assertSuccessful();
        $this->assertSame($this->regina->id, CustomerAccount::firstWhere('phone_key', '9170000001')->assigned_cra_id);
    }

    public function test_orders_from_before_the_first_day_come_first(): void
    {
        [$lhea, $anna] = $this->sellers();
        // Qualifies on Lhea's covered order, but an earlier ₱1,200 order sold on Anna's other account came first → Anna.
        $this->delivered('Q1', 'crd', 'Queenie Go', '9170000002', '2026-08-01', 1500, seller: 'CRD LHEI');
        CustomerHistory::create(['phone_key' => '9170000002', 'prior_cra_orders' => 2, 'prior_cra_sales' => [
            ['at' => '2025-10-01 02:00:00', 'amount' => 600, 'seller' => 'CRD LHEI'],
            ['at' => '2025-11-01 02:00:00', 'amount' => 1500, 'seller' => 'CRD JOANNA PACLIBARE'],
        ], 'checked_at' => now()]);

        // Lhea ₱600 (AOV ₱600), Anna ₱1,500 (₱1,050) → Anna.
        $this->assertSame($anna->id, app(HighValueCustomers::class)->firstReorderCras()['9170000002']);
    }

    public function test_the_customer_pop_up_shows_how_far_they_are_from_high_aov_and_vip(): void
    {
        $this->customers();
        $this->delivered('B2', 'crd', 'Ben Reyes', '9172222222', '2026-09-10', 2000, [['name' => 'Pterygium', 'qty' => 1]]);

        // Ben: ₱2,500 over 2 orders (AOV ₱1,250) is High AOV; ₱2,000 of Pterygium's ₱14,970 CLTV → ₱12,970 to VIP.
        $this->actingAs($this->owner)->get(route('customers.show', '9172222222'))->assertOk()
            ->assertSee('Customer CLTV · Total spent')
            ->assertSeeInOrder(['High AOV', 'AOV ₱1,250.00'])->assertDontSee('Potential High AOV')
            ->assertSeeInOrder(['To become VIP', '₱12,970.00', 'remaining on Pterygium']);

        $this->delivered('A2', 'crd', 'Ana Cruz', '9171111111', '2026-09-20', 300);
        // Ana: ₱1,500 over 2 orders (AOV ₱750) → a next order of ₱1,497 (₱999 × 3 − ₱1,500) makes her High AOV.
        $this->get(route('customers.show', '9171111111'))->assertOk()
            ->assertSeeInOrder(['Potential High AOV', 'AOV ₱750.00', '₱1,497.00', 'remaining']);
        // Cara has no CRA-handled order: no High AOV line.
        $this->get(route('customers.show', '9173333333'))->assertOk()->assertDontSee('High AOV');
    }
}
