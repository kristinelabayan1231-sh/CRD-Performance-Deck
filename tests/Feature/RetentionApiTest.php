<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\CvrOrderEntry;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\PcReportEntry;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RetentionApiTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-03';

    private int $seq = 0;

    private User $regina;

    private User $lhea;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Asia/Manila'));
        $this->regina = $this->user('Regina', Role::CRA, 'Regina Cruz');
        $this->lhea = $this->user('Lhea', Role::CRA, 'Lhea Gatdula');
    }

    private function user(string $name, string $role, ?string $pancake = null): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true,
        ]);
    }

    private function order(string $seller, float $amount, ?int $status, array $extra = []): PancakeOrder
    {
        $this->seq++;

        return PancakeOrder::create([
            'pancake_order_id' => (string) (5000 + $this->seq), 'ordered_on' => self::DAY, 'ordered_at' => self::DAY.' 02:00:00',
            'seller_name' => $seller, 'customer_name' => 'Buyer', 'phone_key' => '91700000'.$this->seq, 'status' => $status,
            'total_price' => $amount, 'tags' => [], 'non_crd' => false, ...$extra,
        ]);
    }

    private function lead(User $cra, string $listedOn, array $extra = []): void
    {
        $this->seq++;
        Lead::create([
            'order_id' => "ORD-{$this->seq}", 'customer_name' => 'Customer', 'phone_number' => '0918000000'.$this->seq, 'product_name' => 'CanPro',
            'qty' => 1, 'delivered_date' => '2026-09-20', 'consumption_days' => 30, 'est_out_of_stock_date' => $listedOn,
            'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra->id, ...$extra,
        ]);
    }

    private function fetch(string $from = self::DAY, string $to = self::DAY, ?string $key = null): TestResponse
    {
        $key ??= ApiKey::issue('Finance')[1];

        return $this->withToken($key)->getJson("/api/external/v1/retention/daily?from={$from}&to={$to}");
    }

    public function test_a_missing_unknown_or_revoked_key_is_refused(): void
    {
        [$revoked, $revokedToken] = ApiKey::issue('Old report');
        $revoked->forceFill(['revoked_at' => now()])->save();

        $this->getJson('/api/external/v1/retention/daily?from='.self::DAY.'&to='.self::DAY)->assertUnauthorized();
        $this->withToken('crd_not-a-key')->getJson('/api/external/v1/retention/daily?from='.self::DAY.'&to='.self::DAY)->assertUnauthorized();
        $this->withToken($revokedToken)->getJson('/api/external/v1/retention/daily?from='.self::DAY.'&to='.self::DAY)->assertUnauthorized();
    }

    public function test_the_key_also_works_as_an_x_api_key_header_and_its_use_is_saved(): void
    {
        [$key, $token] = ApiKey::issue('Finance');

        $this->getJson('/api/external/v1/retention/daily?from='.self::DAY.'&to='.self::DAY, ['X-Api-Key' => $token])->assertOk();

        $this->assertNotNull($key->fresh()->last_used_at);
    }

    public function test_the_range_must_be_in_order_and_at_most_92_days(): void
    {
        $this->fetch('2026-11-03', '2026-11-01')->assertJsonValidationErrors(['to' => 'The to field must be a date after or equal to from.']);
        $this->fetch('2026-08-01', '2026-11-01')->assertJsonValidationErrors(['to' => 'The range may be at most 92 days.']);
        $this->fetch('2026-08-02', '2026-11-01')->assertOk()->assertJsonCount(92, 'data');
    }

    public function test_a_day_counts_the_cras_orders_sales_and_statuses_as_the_daily_is_does(): void
    {
        $this->order('REGINA CRUZ', 1000, 3)->forceFill(['shecom_sales' => 950])->save();
        $this->order('REGINA CRUZ', 800, 5);
        $this->order('REGINA CRUZ', 600, 6);
        $this->order('REGINA CRUZ', 9999, 7);
        $this->order('REGINA CRUZ', 9999, 3, ['non_crd' => true]);
        $this->order('FSD SELLER', 9999, 3);
        $claimed = $this->order('FSD SELLER', 400, 2);
        CvrOrderEntry::create(['pancake_order_id' => $claimed->pancake_order_id, 'user_id' => $this->lhea->id]);

        $response = $this->fetch();

        $response->assertOk()
            ->assertJsonPath('meta.timezone', 'Asia/Manila')
            ->assertJsonPath('data.0.date', self::DAY)
            ->assertJsonPath('data.0.orders', 3)
            ->assertJsonPath('data.0.cancelledOrders', 1)
            ->assertJsonPath('data.0.grossSales', 2750)
            ->assertJsonPath('data.0.cancelledSales', 600)
            ->assertJsonPath('data.0.deliveredSales', 950)
            ->assertJsonPath('data.0.returnedSales', 800)
            ->assertJsonPath('data.0.reactivatedCustomers', null)
            ->assertJsonPath('data.0.byStatus', [
                ['status' => 2, 'name' => 'Shipped', 'orders' => 1, 'sales' => 400],
                ['status' => 3, 'name' => 'Delivered', 'orders' => 1, 'sales' => 950],
                ['status' => 5, 'name' => 'Returned', 'orders' => 1, 'sales' => 800],
                ['status' => 6, 'name' => 'Canceled', 'orders' => 1, 'sales' => 600],
            ]);
        $response->assertJsonPath('data.0.agents.0.name', 'Regina')
            ->assertJsonPath('data.0.agents.0.orders', 2)
            ->assertJsonPath('data.0.agents.0.grossSales', 2350)
            ->assertJsonPath('data.0.agents.1.name', 'Lhea')
            ->assertJsonPath('data.0.agents.1.orders', 1)
            ->assertJsonPath('data.0.agents.1.grossSales', 400);
    }

    public function test_a_day_has_the_daily_is_product_cost_and_the_pc_reports_inquiries_and_engagements(): void
    {
        Product::create(['name' => 'Sinuxyl']);
        // Shecom's product cost of the order, once synced.
        $this->order('REGINA CRUZ', 1000, 3, ['page_name' => 'Sinuxyl Nasal Spray', 'items' => [['qty' => 1, 'pieces' => 2, 'name' => 'Sinuxyl']]])
            ->forceFill(['shecom_product_cost' => 70])->save();
        PcReportEntry::create(['user_id' => $this->regina->id, 'page' => 'Sinuxyl Nasal Spray', 'category' => '', 'date' => self::DAY, 'inquiries' => 1200, 'engagements' => 300]);

        $this->fetch()->assertOk()
            ->assertJsonPath('data.0.productCost', 70)
            ->assertJsonPath('data.0.inquiries', 1200)
            ->assertJsonPath('data.0.engagements', 300)
            ->assertJsonPath('data.0.agents.0.name', 'Regina')
            ->assertJsonPath('data.0.agents.0.productCost', 70)
            ->assertJsonPath('data.0.agents.0.inquiries', 1200)
            ->assertJsonPath('data.0.agents.0.engagements', 300);
    }

    public function test_repeat_customer_orders_are_from_customers_delivered_before_that_day(): void
    {
        $returning = $this->order('REGINA CRUZ', 500, 1);
        $this->order('REGINA CRUZ', 500, 1);
        LogisticsOrder::create([
            'order_id' => 'L-1', 'team' => LogisticsOrder::TEAM_FSD, 'source' => LogisticsOrder::SOURCE_LOGISTICS, 'customer_name' => 'Buyer',
            'phone_number' => '0'.$returning->phone_key, 'phone_key' => $returning->phone_key, 'product' => 'CanPro', 'qty' => 1, 'delivered_date' => '2026-10-01',
        ]);

        $this->fetch()->assertJsonPath('data.0.orders', 2)->assertJsonPath('data.0.repeatCustomerOrders', 1);
    }

    public function test_leads_are_the_assigned_leads_on_the_days_list_by_its_paired_lead_day(): void
    {
        WorkingDate::startTomorrow(CarbonImmutable::parse('2026-10-07'));
        $this->lead($this->regina, '2026-10-04', ['contact_date' => '2026-11-03']);
        $this->lead($this->regina, '2026-10-04');
        $this->lead($this->lhea, '2026-10-04', ['order_id' => Lead::POS_PREFIX.'77']);
        $this->lead($this->lhea, '2026-11-03');

        $this->fetch()
            ->assertJsonPath('data.0.leads', 2)
            ->assertJsonPath('data.0.leadsContacted', 1)
            ->assertJsonPath('data.0.agents.0.name', 'Regina')
            ->assertJsonPath('data.0.agents.0.leads', 2)
            ->assertJsonCount(1, 'data.0.agents');
    }

    public function test_settings_makes_a_key_shown_once_and_revokes_it(): void
    {
        $admin = $this->user('Admin', Role::SUPER_ADMIN);

        $made = $this->actingAs($admin)->post(route('settings.api-keys.store'), ['name' => 'Finance report', 'scope' => ApiKey::RETENTION]);

        $token = session('new_key')['token'];
        $made->assertRedirect(route('settings.api-keys.index'));
        $key = ApiKey::sole();
        $this->assertSame(hash('sha256', $token), $key->key_hash);
        $this->assertSame('Finance report', $key->name);
        $this->actingAs($admin)->get(route('settings.api-keys.index'))->assertSee('Finance report')->assertSee($key->prefix.'…');

        $this->actingAs($admin)->delete(route('settings.api-keys.destroy', $key))->assertRedirect(route('settings.api-keys.index'));

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->withToken($token)->getJson('/api/external/v1/retention/daily?from='.self::DAY.'&to='.self::DAY)->assertUnauthorized();
    }

    public function test_a_user_without_the_api_keys_permission_cannot_make_keys(): void
    {
        $this->actingAs($this->regina)->post(route('settings.api-keys.store'), ['name' => 'Mine'])->assertForbidden();

        $this->assertDatabaseCount('api_keys', 0);
    }
}
