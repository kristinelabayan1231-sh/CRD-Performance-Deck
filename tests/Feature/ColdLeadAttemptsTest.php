<?php

namespace Tests\Feature;

use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\LeadAttempt;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerChurn;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ColdLeadAttemptsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Manila'));
        $this->alice = $this->cra('alice@example.com');
        $this->bob = $this->cra('bob@example.com');
    }

    private function cra(string $email): User
    {
        return User::create(['email' => $email, 'name' => ucfirst(strtok($email, '@')), 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
    }

    private function lead(string $name, array $extra = []): Lead
    {
        return Lead::create([
            'order_id' => $name, 'customer_name' => $name, 'phone_number' => '0917'.(crc32($name) % 10000000), 'product_name' => 'Pterygium', 'qty' => 1,
            'delivered_date' => '2026-08-01', 'consumption_days' => 15, 'est_out_of_stock_date' => '2026-09-20', 'lead_type' => 'fsd',
            'assigned_to' => $this->alice->id, 'status' => 'pjr_drop_call', 'contact_date' => '2026-09-21', 'customer_tag' => 'cold', ...$extra,
        ]);
    }

    private function attempt(Lead $lead, string $day, ?User $by = null): void
    {
        LeadAttempt::create(['lead_id' => $lead->id, 'user_id' => ($by ?? $this->alice)->id, 'attempted_on' => $day]);
    }

    public function test_a_cra_logs_one_attempt_a_day_on_a_cold_lead_and_can_take_back_todays(): void
    {
        $lead = $this->lead('Cold Customer');

        $this->actingAs($this->alice)->postJson(route('segmentation.attempts.store', $lead))
            ->assertOk()->assertJson(['left' => 2, 'today' => true, 'history' => 'Alice · Oct 5']);
        // A second click the same day doesn't use another attempt.
        $this->actingAs($this->alice)->postJson(route('segmentation.attempts.store', $lead))->assertOk()->assertJson(['left' => 2]);

        $this->actingAs($this->alice)->deleteJson(route('segmentation.attempts.destroy', $lead))
            ->assertOk()->assertJson(['left' => 3, 'today' => false]);
        $this->assertSame(0, $lead->attempts()->count());
    }

    public function test_attempts_stay_with_the_lead_when_it_moves_to_another_cra(): void
    {
        $lead = $this->lead('Moved Customer');
        $this->attempt($lead, '2026-10-01');
        $this->attempt($lead, '2026-10-03');
        $lead->update(['assigned_to' => $this->bob->id]);

        $this->actingAs($this->bob)->postJson(route('segmentation.attempts.store', $lead))
            ->assertOk()->assertJson(['left' => 0, 'history' => 'Alice · Oct 1, Alice · Oct 3, Bob · Oct 5']);
        // Used up: no fourth attempt, and Alice can't log on a lead that's no longer hers.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Manila'));
        $this->actingAs($this->bob)->postJson(route('segmentation.attempts.store', $lead))->assertUnprocessable();
        $this->actingAs($this->alice)->postJson(route('segmentation.attempts.store', $lead))->assertForbidden();
    }

    public function test_only_cold_leads_take_attempts(): void
    {
        $lead = $this->lead('Warm Customer', ['customer_tag' => 'warm']);

        $this->actingAs($this->alice)->postJson(route('segmentation.attempts.store', $lead))->assertUnprocessable();
    }

    public function test_a_cold_lead_leaves_reactivation_once_its_attempts_are_used_up(): void
    {
        $this->attempt($retrying = $this->lead('Still Retrying'), '2026-10-01');
        $usedUp = $this->lead('Used Up');
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $this->attempt($usedUp, $day);
        }

        $this->actingAs($this->alice)->get('/segmentation?show=unprocessed&full=1&list=reactivation')->assertOk()
            ->assertSee($retrying->customer_name)->assertSeeText('2 left')->assertDontSee('Used Up');
    }

    public function test_a_customer_is_churned_once_a_cra_has_reached_them_with_no_order_since(): void
    {
        // Ran out Aug 15, so their 30 days ended Sep 14 with no reorder: due in September.
        Product::create(['name' => 'Crdol']);
        $delivered = fn (string $phone) => DeliveredOrder::create(['order_id' => uniqid(), 'customer_name' => 'C', 'phone_number' => $phone, 'product_raw' => 'Crdol',
            'qty' => 1, 'delivered_date' => '2026-08-01', 'consumption_days_per_unit' => 15, 'source' => DeliveredOrder::SOURCE_SHECOM]);
        $from = CarbonImmutable::parse('2026-09-01');
        $to = CarbonImmutable::parse('2026-09-30');
        $untouched = ['status' => null, 'contact_date' => null, 'customer_tag' => null];

        // Churned: reached by a contact date, by a status a CRA set, and by a logged attempt.
        $delivered('9171111111');
        $this->lead('Contacted', ['phone_number' => '09171111111', 'status' => null]);
        $delivered('9172222222');
        $this->lead('Status Set', ['phone_number' => '09172222222', 'contact_date' => null, 'status' => 'active', 'status_updated_by' => $this->alice->id]);
        $delivered('9173333333');
        $this->attempt($this->lead('Attempted', ['phone_number' => '09173333333', ...$untouched]), '2026-10-01');

        // For reactivation: no lead, an import's default status, reserved, bought, ordered late, or only an older delivery's lead.
        $delivered('9174444444');
        $delivered('9175555555');
        $this->lead('Import Default', ['phone_number' => '09175555555', 'contact_date' => null, 'status' => 'active']);
        $delivered('9176666666');
        $this->lead('Reserved', ['phone_number' => '09176666666', 'repeat_purchase' => 'reserve']);
        $delivered('9177777777');
        $this->lead('Bought', ['phone_number' => '09177777777', 'repeat_purchase' => 'yes']);
        $delivered('9178888888');
        $this->lead('Ordered Late', ['phone_number' => '09178888888']);
        PancakeOrder::create(['pancake_order_id' => 'LATE', 'ordered_on' => '2026-10-04', 'phone_key' => '9178888888', 'status' => 3, 'total_price' => 500]);
        $delivered('9179999999');
        $this->lead('Older Delivery', ['phone_number' => '09179999999', 'delivered_date' => '2026-07-01']);

        $all = app(CustomerChurn::class)->for($from, $to)['all'];
        $this->assertSame(['customers' => 9, 'back' => 0, 'reactivation' => 6, 'lost' => 3], array_intersect_key($all, array_flip(['customers', 'back', 'reactivation', 'lost'])));
        $this->assertSame(3 / 9, $all['rate']);

        $rows = collect(app(CustomerChurn::class)->customers($from, $to))->map(fn (array $row) => CustomerChurn::row($row));
        $this->assertSame(['9171111111', '9172222222', '9173333333'], $rows->where('churned', true)->pluck('phone_key')->map(fn ($phone) => (string) $phone)->sort()->values()->all());

        $days = app(CustomerChurn::class)->perDeadline($from, $to)['all'];
        $this->assertSame(3, collect($days)->sum('lost'));
        $this->assertSame(6, collect($days)->sum('reactivation'));
    }
}
