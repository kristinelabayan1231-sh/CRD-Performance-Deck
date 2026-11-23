<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\CustomerClaim;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\LeadTransfer;
use App\Models\MniDepartment;
use App\Models\PancakeOrder;
use App\Models\RestockReminder;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Support\DutyGoals;
use App\Support\SalesGoals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisabledCraTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', 'Asia/Manila'));
    }

    private function user(string $name, string $role = Role::CRA): User
    {
        return User::create([
            'email' => str_replace(' ', '', strtolower($name)).'@example.com', 'display_name' => $name,
            'pancake_name' => $role === Role::CRA ? 'CRD '.strtoupper($name) : null,
            'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true,
        ]);
    }

    private function order(User $cra, string $phoneKey): void
    {
        $this->seq++;
        PancakeOrder::create(['pancake_order_id' => 'P'.$this->seq, 'ordered_on' => '2026-10-01', 'ordered_at' => '2026-10-01 02:00:00',
            'seller_name' => $cra->pancake_name, 'phone_key' => $phoneKey, 'status' => 3, 'total_price' => 800]);
    }

    private function reminder(User $cra, string $phoneKey, string $status): RestockReminder
    {
        return RestockReminder::create(['phone_key' => $phoneKey, 'phone_number' => '0'.$phoneKey, 'customer_name' => 'C', 'products' => 'Sinuxyl',
            'customer_type' => 'first_reorder', 'remind_on' => '2026-10-20', 'run_out_on' => '2026-10-27', 'user_id' => $cra->id, 'status' => $status]);
    }

    private function lead(User $cra, string $listedOn, array $extra = []): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => (string) (7000 + $this->seq), 'customer_name' => 'C'.$this->seq, 'phone_number' => '0917700'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'product_name' => 'Sinuxyl', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => $listedOn, 'listed_on' => $listedOn, 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra->id, ...$extra,
        ]);
    }

    private function disable(User $cra): void
    {
        $this->actingAs($this->user('Owner', Role::SUPER_ADMIN))
            ->patch(route('user-access.update', $cra), ['is_active' => 0])->assertSessionHasNoErrors();
    }

    public function test_disabling_a_cra_hands_their_open_leads_to_the_others_and_keeps_past_days(): void
    {
        $regina = $this->user('Regina');
        $roseAn = $this->user('Rose-An');
        $today = Lead::today()->toDateString();

        $backlog = $this->lead($roseAn, '2026-10-15');
        $todays = $this->lead($roseAn, $today);
        $followUp = $this->lead($roseAn, '2026-10-16', ['status' => 'pjr_drop_call', 'contact_date' => '2026-10-16', 'notes' => 'PJR twice']);
        $callback = $this->lead($roseAn, '2026-10-17', ['status' => 'active', 'contact_date' => '2026-10-17', 'callback_date' => '2026-10-22']);
        $closed = $this->lead($roseAn, '2026-10-17', ['status' => 'active', 'contact_date' => '2026-10-17', 'repeat_purchase' => 'no', 'feedback' => 'no_budget']);
        $converted = $this->lead($roseAn, '2026-10-18', ['status' => 'active', 'contact_date' => '2026-10-18', 'repeat_purchase' => 'yes']);
        $account = CustomerAccount::create(['phone_key' => '9170000001', 'assigned_cra_id' => $roseAn->id, 'cra_set_by_hand' => true]);

        $this->disable($roseAn);

        // Untouched backlog goes back to the pool for the backlog fill; today's untouched lead is handed out right away.
        $this->assertNull($backlog->fresh()->assigned_to);
        $this->assertSame($regina->id, $todays->fresh()->assigned_to);
        // Leads still being worked move to Regina today, with their notes.
        foreach ([$followUp, $callback] as $lead) {
            $this->assertSame($regina->id, $lead->fresh()->assigned_to);
            $this->assertSame($today, $lead->fresh()->listed_on->toDateString());
            $this->assertTrue(LeadTransfer::where('lead_id', $lead->id)->where('from_user_id', $roseAn->id)->exists());
        }
        $this->assertSame('PJR twice', $followUp->fresh()->notes);
        // Closed and converted leads stay hers as history.
        $this->assertSame($roseAn->id, $closed->fresh()->assigned_to);
        $this->assertSame($roseAn->id, $converted->fresh()->assigned_to);
        // Past lead days keep counting every moved lead for her.
        $this->assertEqualsCanonicalizing(
            [$backlog->id, $followUp->id, $callback->id],
            LeadMove::where('assigned_to', $roseAn->id)->pluck('lead_id')->all(),
        );
        // Her High AOV / VIP customer is free for the next CRA.
        $this->assertNull($account->fresh()->assigned_cra_id);
        $this->assertFalse($account->fresh()->cra_set_by_hand);
    }

    public function test_the_monthly_mni_shares_costs_among_the_months_cras_and_past_months_keep_a_disabled_cra(): void
    {
        $regina = $this->user('Regina');
        $roseAn = $this->user('Rose-An');
        foreach (['2026-09-01', '2026-10-01'] as $month) {
            MniDepartment::create(['month' => $month, 'plan' => '1m', 'hmo_expense' => 8000]);
        }

        $roseAn->update(['is_active' => false]);
        $sup = $this->user('Sup', Role::CRA_SUPERVISOR);
        $mni = fn (string $month) => $this->actingAs($sup)->get(route('mni.index', ['month' => $month, 'plan' => '1m']))->assertOk()->viewData('data');

        // September: she was there all month, so ÷ 2 and her card stays.
        $september = $mni('2026-09');
        $this->assertEqualsWithDelta(4000, $september['cras'][$regina->id]['hmo_expense'], 0.001);
        $this->assertArrayHasKey($roseAn->id, $september['cras']);
        // October, the month she was disabled in: Regina alone.
        $october = $mni('2026-10');
        $this->assertEqualsWithDelta(8000, $october['cras'][$regina->id]['hmo_expense'], 0.001);
        $this->assertArrayNotHasKey($roseAn->id, $october['cras']);
    }

    public function test_a_disabled_cra_has_a_daily_goal_only_on_the_days_before_they_were_disabled(): void
    {
        $regina = $this->user('Regina');
        $roseAn = $this->user('Rose-An');
        $roseAn->update(['is_active' => false]);

        $goals = DutyGoals::for(collect([$regina, $roseAn]), CarbonImmutable::parse('2026-10-18'), CarbonImmutable::parse('2026-10-21'));

        // Oct 18–19 count for her; from Oct 20 (the day she was disabled) she has none and Regina keeps only her own.
        $this->assertEqualsWithDelta(SalesGoals::craDaily() * 2, $goals[$roseAn->id]['goal'], 0.001);
        $this->assertEqualsWithDelta(SalesGoals::craDaily() * 4, $goals[$regina->id]['goal'], 0.001);
    }

    public function test_reports_keep_a_disabled_cra_on_dates_before_they_were_disabled(): void
    {
        $this->user('Regina');
        $roseAn = $this->user('Rose-An');
        $roseAn->update(['is_active' => false]);
        $sup = $this->user('Sup', Role::CRA_SUPERVISOR);
        $listed = fn (array $query) => $this->actingAs($sup)->get(route('product-performance.segmentation-summary', $query))
            ->assertOk()->viewData('cras')->pluck('id');

        $this->assertContains($roseAn->id, $listed(['month' => '2026-09']));
        $this->assertContains($roseAn->id, $listed(['date' => '2026-10-19']));
        $this->assertNotContains($roseAn->id, $listed(['date' => '2026-10-20']));
    }

    public function test_disabling_a_cra_shares_out_their_customers_and_their_texts_not_sent_yet(): void
    {
        $regina = $this->user('Regina');
        $lhea = $this->user('Lhea');
        $roseAn = $this->user('Rose-An');
        // Only Rose-An handled these two; Lhea also handled the third.
        foreach (['9171111111', '9172222222', '9173333333'] as $phoneKey) {
            $this->order($roseAn, $phoneKey);
        }
        $this->order($lhea, '9173333333');
        $pending = $this->reminder($roseAn, '9171111111', RestockReminder::PENDING);
        $queued = $this->reminder($roseAn, '9173333333', RestockReminder::QUEUED);
        $sent = $this->reminder($roseAn, '9172222222', RestockReminder::SENT);

        $this->disable($roseAn);

        // Her own customers are shared out evenly and stay with that CRA; the one Lhea handled goes to Lhea.
        $claims = CustomerClaim::where('previous_cra_id', $roseAn->id)->pluck('user_id', 'phone_key')->all();
        $this->assertEqualsCanonicalizing(['9171111111', '9172222222'], array_keys($claims));
        $this->assertEqualsCanonicalizing([$regina->id, $lhea->id], array_values($claims));
        // Texts not sent yet follow the customer to their new CRA, back to "to send"; sent ones stay hers.
        $this->assertSame($claims['9171111111'], $pending->fresh()->user_id);
        $this->assertSame([$lhea->id, RestockReminder::PENDING], [$queued->fresh()->user_id, $queued->fresh()->status]);
        $this->assertSame($roseAn->id, $sent->fresh()->user_id);

        // Once per disabling: a later hand-out doesn't share them out again.
        CustomerClaim::where('previous_cra_id', $roseAn->id)->delete();
        app(LeadGenerator::class)->assign(Lead::today());
        $this->assertSame(0, CustomerClaim::count());
    }
}
