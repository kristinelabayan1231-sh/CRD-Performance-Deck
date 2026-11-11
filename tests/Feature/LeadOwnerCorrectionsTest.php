<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\CustomerLink;
use App\Models\ExclusiveListLead;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadOwnerCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $joanna;

    private User $july;

    private User $regina;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00', 'Asia/Manila'));
        $this->joanna = $this->cra('Joanna', 'CRD ANNA', '2026-11-01');
        $this->july = $this->cra('July', 'CRD JULY ANN');
        $this->regina = $this->cra('Regina', 'CRD REJ');
    }

    private function cra(string $name, string $pancake, ?string $exclusiveFrom = null): User
    {
        return User::create(['email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true, 'exclusive_from' => $exclusiveFrom]);
    }

    /**
     * @param  array<int, int>  $ordersBy  CRA id => orders they handled, oldest CRA first
     * @param  array<string, mixed>  $extra
     */
    private function lead(User $holder, array $ordersBy, array $extra = []): Lead
    {
        $this->seq++;
        $phone = '09170000'.str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT);
        $day = 1;
        foreach ($ordersBy as $craId => $orders) {
            foreach (range(1, $orders) as $ignored) {
                PancakeOrder::create(['pancake_order_id' => 'O'.$this->seq.'-'.$day, 'ordered_on' => '2026-08-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT),
                    'seller_name' => User::find($craId)->pancake_name, 'phone_key' => LeadGenerator::normalizePhone($phone), 'status' => 3, 'total_price' => 300]);
                $day++;
            }
        }

        return Lead::create(['order_id' => 'L'.$this->seq, 'customer_name' => 'Customer '.$this->seq, 'phone_number' => $phone, 'product_name' => 'Pterygium', 'qty' => 1,
            'delivered_date' => '2026-06-01', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-09-01', 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $holder->id, ...$extra]);
    }

    private function savedCra(Lead $lead, User $cra): void
    {
        CustomerAccount::create(['phone_key' => LeadGenerator::normalizePhone($lead->phone_number), 'assigned_cra_id' => $cra->id]);
    }

    public function test_without_apply_nothing_moves(): void
    {
        $lead = $this->lead($this->regina, [$this->july->id => 2]);

        $this->artisan('leads:correct-owners')->expectsOutputToContain('Would move 1 leads')->assertSuccessful();

        $this->assertSame($this->regina->id, $lead->fresh()->assigned_to);
    }

    public function test_an_untouched_lead_goes_to_the_cra_with_the_most_orders_but_a_worked_one_stays(): void
    {
        $untouched = $this->lead($this->regina, [$this->regina->id => 1, $this->july->id => 2]);
        $worked = $this->lead($this->regina, [$this->july->id => 2], ['status' => 'active']);
        $noted = $this->lead($this->regina, [$this->july->id => 2], ['notes' => 'Called']);
        $own = $this->lead($this->july, [$this->july->id => 1]);

        $this->artisan('leads:correct-owners --apply')->assertSuccessful();

        $this->assertSame($this->july->id, $untouched->fresh()->assigned_to);
        $this->assertSame($this->regina->id, $worked->fresh()->assigned_to);
        $this->assertSame($this->regina->id, $noted->fresh()->assigned_to);
        $this->assertSame($this->july->id, $own->fresh()->assigned_to);
    }

    public function test_a_level_customer_goes_to_who_handled_them_last_unless_the_holder_is_their_saved_cra(): void
    {
        $level = $this->lead($this->regina, [$this->regina->id => 1, $this->july->id => 1]);
        $savedLevel = $this->lead($this->regina, [$this->regina->id => 1, $this->july->id => 1]);
        $this->savedCra($savedLevel, $this->regina);

        $this->artisan('leads:correct-owners --apply')->assertSuccessful();

        $this->assertSame($this->july->id, $level->fresh()->assigned_to);
        $this->assertSame($this->regina->id, $savedLevel->fresh()->assigned_to);
    }

    public function test_most_orders_beats_the_holders_saved_high_value_cra_and_the_saved_cra_moves_too_and_leaves_todays_list(): void
    {
        $lead = $this->lead($this->joanna, [$this->joanna->id => 1, $this->july->id => 2], ['listed_on' => Lead::today()->toDateString()]);
        $this->savedCra($lead, $this->joanna);
        ExclusiveListLead::create(['user_id' => $this->joanna->id, 'list_date' => Lead::today(), 'lead_id' => $lead->id, 'source' => ExclusiveLeads::HIGH_VALUE]);

        $this->artisan('leads:correct-owners --apply')->assertSuccessful();

        $this->assertSame($this->july->id, $lead->fresh()->assigned_to);
        $this->assertSame($this->july->id, CustomerAccount::first()->assigned_cra_id);
        $this->assertSame(0, ExclusiveListLead::count());
    }

    public function test_a_merged_numbers_saved_cra_moves_on_the_main_number(): void
    {
        $lead = $this->lead($this->joanna, [$this->joanna->id => 1, $this->july->id => 2]);
        CustomerLink::create(['phone_key' => LeadGenerator::normalizePhone($lead->phone_number), 'primary_phone_key' => '9179999999']);
        CustomerAccount::create(['phone_key' => '9179999999', 'assigned_cra_id' => $this->joanna->id]);

        $this->artisan('leads:correct-owners --apply')->assertSuccessful();

        $this->assertSame($this->july->id, $lead->fresh()->assigned_to);
        $this->assertSame($this->july->id, CustomerAccount::firstWhere('phone_key', '9179999999')->assigned_cra_id);
    }

    public function test_a_third_cras_saved_customer_is_left_for_their_own_rule(): void
    {
        $lead = $this->lead($this->regina, [$this->july->id => 2]);
        $this->savedCra($lead, $this->joanna);

        $this->artisan('leads:correct-owners --apply')->assertSuccessful();

        $this->assertSame($this->regina->id, $lead->fresh()->assigned_to);
    }

    public function test_a_lead_is_not_pulled_to_an_exclusive_cra(): void
    {
        $lead = $this->lead($this->regina, [$this->joanna->id => 2]);

        $this->artisan('leads:correct-owners --apply')->expectsOutputToContain('rightful CRA is exclusive: 1')->assertSuccessful();

        $this->assertSame($this->regina->id, $lead->fresh()->assigned_to);
    }

    public function test_a_named_lead_moves_even_if_worked_with_its_saved_cra(): void
    {
        $alma = $this->lead($this->joanna, [$this->joanna->id => 1, $this->july->id => 4], ['repeat_purchase' => 'yes']);
        $this->savedCra($alma, $this->joanna);
        $other = $this->lead($this->joanna, [$this->july->id => 2], ['status' => 'active']);

        $this->artisan('leads:correct-owners --apply --lead='.$alma->id)->assertSuccessful();

        $this->assertSame($this->july->id, $alma->fresh()->assigned_to);
        $this->assertSame($this->july->id, CustomerAccount::first()->assigned_cra_id);
        $this->assertSame($this->joanna->id, $other->fresh()->assigned_to);
    }
}
