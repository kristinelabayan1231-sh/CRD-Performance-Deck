<?php

namespace Tests\Feature;

use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\LeadAttempt;
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

    public function test_churn_leaves_out_cold_leads_still_being_retried(): void
    {
        // Ran out Aug 15, so their 30 days ended Sep 14 with no reorder: lost.
        Product::create(['name' => 'Crdol']);
        $delivered = fn (string $phone) => DeliveredOrder::create(['order_id' => uniqid(), 'customer_name' => 'C', 'phone_number' => $phone, 'product_raw' => 'Crdol',
            'qty' => 1, 'delivered_date' => '2026-08-01', 'consumption_days_per_unit' => 15, 'source' => DeliveredOrder::SOURCE_SHECOM]);
        $churn = fn () => app(CustomerChurn::class)->for(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))['all'];

        $delivered('9171111111');
        $retrying = $this->lead('Retrying', ['phone_number' => '09171111111']);
        $this->attempt($retrying, '2026-10-01');
        $delivered('9172222222');
        $this->lead('Went Active', ['phone_number' => '09172222222', 'status' => 'active']);
        $delivered('9173333333');

        $this->assertSame([2, 2], [$churn()['customers'], $churn()['lost']]);

        // All three attempts made: counted again.
        $this->attempt($retrying, '2026-10-02');
        $this->attempt($retrying, '2026-10-03');
        $this->assertSame([3, 3], [$churn()['customers'], $churn()['lost']]);
    }
}
