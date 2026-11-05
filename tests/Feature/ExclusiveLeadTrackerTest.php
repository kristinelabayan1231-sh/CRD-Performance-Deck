<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\ExclusiveListLead;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\BacklogFill;
use App\Services\ExclusiveLeads;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExclusiveLeadTrackerTest extends TestCase
{
    use RefreshDatabase;

    private User $joanna;

    private User $regina;

    private User $supervisor;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00', 'Asia/Manila'));
        config(['services.shecom.key' => 'test-key']);
        Product::create(['name' => 'Pterygium', 'srp' => 499]);
        $this->joanna = $this->cra('Joanna Rose', 'CRD ANNA PACLIBARE 2', '2026-11-01');
        $this->regina = $this->cra('Regina', 'CRD REJ VERGARA');
        $this->supervisor = User::create(['email' => 'lead@example.com', 'display_name' => 'Lead', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
    }

    private function cra(string $name, string $pancake, ?string $exclusiveFrom = null): User
    {
        return User::create([
            'email' => strtolower(strtok($name, ' ')).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true, 'exclusive_from' => $exclusiveFrom,
        ]);
    }

    private function lead(string $day, ?User $cra = null, array $extra = []): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => (string) (9000 + $this->seq), 'customer_name' => 'C'.$this->seq, 'phone_number' => '09170000'.str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT),
            'product_name' => 'Pterygium', 'qty' => 1, 'delivered_date' => '2026-06-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => $day, 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra?->id, ...$extra,
        ]);
    }

    private function phoneKey(Lead $lead): string
    {
        return LeadGenerator::normalizePhone($lead->phone_number);
    }

    /**
     * A CRA-handled delivered order worth $total: its customer's AOV for the High AOV line.
     */
    private function delivered(string $phoneKey, float $total): void
    {
        $id = 'D'.++$this->seq;
        LogisticsOrder::remember([['order_id' => $id, 'team' => 'crd', 'customer_name' => 'Customer', 'phone_number' => $phoneKey,
            'product' => 'Pterygium', 'qty' => null, 'delivered_date' => '2026-09-01']]);
        PancakeOrder::create(['pancake_order_id' => $id, 'ordered_on' => '2026-08-29', 'phone_key' => $phoneKey, 'total_price' => $total, 'status' => 3, 'items' => []]);
    }

    private function handledTwiceBy(User $cra, string $phoneKey): void
    {
        foreach ([1, 2] as $ignored) {
            PancakeOrder::create(['pancake_order_id' => 'H'.++$this->seq, 'ordered_on' => '2026-08-01', 'ordered_at' => '2026-08-01 02:00:00',
                'seller_name' => $cra->pancake_name, 'phone_key' => $phoneKey, 'status' => 3, 'total_price' => 300]);
        }
    }

    private function sync(string $day): void
    {
        app(ExclusiveLeads::class)->syncCra($this->joanna->fresh(), CarbonImmutable::parse($day));
    }

    /**
     * @return array<int, string> lead id => source
     */
    private function listOn(string $day): array
    {
        return app(ExclusiveLeads::class)->sourcesFor($this->joanna, CarbonImmutable::parse($day))->all();
    }

    private function dayOf(string $date): array
    {
        return collect(app(ExclusiveLeads::class)->month($this->joanna->fresh(), CarbonImmutable::parse($date))['days'])->firstWhere('date', $date);
    }

    public function test_november_plans_6900_against_the_7000_target_and_the_goals(): void
    {
        $month = app(ExclusiveLeads::class)->month($this->joanna, CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(['leads' => 6900, 'target' => 7000, 'short_of_target' => 100], $month['planned']);
        $this->assertSame(['leads' => 7000, 'repeat' => 1400, 'repeat_percent' => 20, 'confirmed_orders' => 54, 'high_aov_min' => 895], $month['targets']);
        $this->assertSame(100, $month['days'][3]['quota']);
        $this->assertSame(20, $month['days'][3]['repeat_goal']);
        $this->assertSame(250, $month['days'][4]['quota']);
        $this->assertSame(50, $month['days'][4]['repeat_goal']);
    }

    public function test_a_cra_transferred_mid_month_plans_only_from_their_day(): void
    {
        $this->joanna->update(['exclusive_from' => '2026-11-29']);

        $this->assertSame(500, app(ExclusiveLeads::class)->month($this->joanna->fresh(), CarbonImmutable::parse('2026-11-01'))['planned']['leads']);
    }

    public function test_days_before_the_build_keep_her_leads_and_are_never_topped_up(): void
    {
        $hers = $this->lead('2026-11-02', $this->joanna);
        $movedOff = $this->lead('2026-11-02', $this->joanna);
        LeadMove::create(['lead_id' => $movedOff->id, 'assigned_to' => $this->joanna->id, 'listed_on' => '2026-11-02', 'moved_to' => '2026-11-03']);
        $movedOff->forceFill(['listed_on' => '2026-11-03'])->save();
        $pool = $this->lead('2026-11-02');

        $this->sync('2026-11-04');

        $this->assertEqualsCanonicalizing([$hers->id, $movedOff->id], array_keys($this->listOn('2026-11-02')));
        $this->assertSame([$movedOff->id], array_keys($this->listOn('2026-11-03')));
        $this->assertNull($pool->fresh()->assigned_to);
    }

    public function test_the_list_fills_leftovers_then_high_aov_then_handled_twice_then_random_up_to_the_quota(): void
    {
        config(['exclusive_leads.daily_quota' => 5]);

        $random = $this->lead('2026-11-06');
        $belowLine = $this->lead('2026-11-06');
        $this->delivered($this->phoneKey($belowLine), 894);
        $handled = $this->lead('2026-11-06');
        $this->handledTwiceBy($this->joanna, $this->phoneKey($handled));
        $atLine = $this->lead('2026-11-06');
        $this->delivered($this->phoneKey($atLine), 895);
        $hersVip = $this->lead('2026-11-06');
        CustomerAccount::create(['phone_key' => $this->phoneKey($hersVip), 'assigned_cra_id' => $this->joanna->id]);
        $leftover = $this->lead('2026-11-05', $this->joanna);

        $this->sync('2026-11-06');

        $list = $this->listOn('2026-11-06');
        $this->assertSame('leftover', $list[$leftover->id]);
        $this->assertSame('high_value', $list[$hersVip->id]);
        $this->assertSame('high_value', $list[$atLine->id]);
        $this->assertSame('handled', $list[$handled->id]);
        $this->assertSame('random', $list[$random->id]);
        $this->assertCount(5, $list);
        // ₱894 is under this module's line, and the last slot went to the earlier lead.
        $this->assertArrayNotHasKey($belowLine->id, $list);
        $this->assertNull($belowLine->fresh()->assigned_to);
        $this->assertSame(Lead::HIGH_VALUE_TAG, $atLine->fresh()->customer_tag);
        $this->assertSame(999, config('customers.high_aov_min'));
    }

    public function test_a_higher_priority_fills_the_slots_before_a_lower_one(): void
    {
        config(['exclusive_leads.daily_quota' => 1]);

        $handled = $this->lead('2026-11-06');
        $this->handledTwiceBy($this->joanna, $this->phoneKey($handled));
        $high = $this->lead('2026-11-06');
        $this->delivered($this->phoneKey($high), 895);

        $this->sync('2026-11-06');

        $this->assertSame([$high->id => 'high_value'], $this->listOn('2026-11-06'));
        $this->assertNull($handled->fresh()->assigned_to);
    }

    public function test_other_cras_customers_and_leads_are_not_taken(): void
    {
        config(['exclusive_leads.daily_quota' => 5]);

        $reginasCustomer = $this->lead('2026-11-06');
        $this->handledTwiceBy($this->regina, $this->phoneKey($reginasCustomer));
        $reginasAccount = $this->lead('2026-11-06');
        CustomerAccount::create(['phone_key' => $this->phoneKey($reginasAccount), 'assigned_cra_id' => $this->regina->id]);
        $reginasBacklog = $this->lead('2026-11-05', $this->regina);

        $this->sync('2026-11-06');

        $this->assertSame([], $this->listOn('2026-11-06'));
        $this->assertSame($this->regina->id, $reginasBacklog->fresh()->assigned_to);
        $this->assertNull($reginasCustomer->fresh()->assigned_to);
    }

    public function test_leftovers_carry_from_her_last_list_day_while_that_day_keeps_its_count(): void
    {
        config(['exclusive_leads.daily_quota' => 2]);

        $done = $this->lead('2026-11-05', $this->joanna, ['status' => 'active']);
        $left = $this->lead('2026-11-05', $this->joanna);
        $this->sync('2026-11-05');
        $this->sync('2026-11-06');

        $this->assertSame([$left->id => 'leftover'], $this->listOn('2026-11-06'));
        $this->assertSame('2026-11-06', $left->fresh()->listed_on->toDateString());
        $this->assertSame('2026-11-05', $left->fresh()->est_out_of_stock_date->toDateString());
        $this->assertSame($this->joanna->id, $left->fresh()->assigned_to);
        $this->assertSame('2026-11-05', $done->fresh()->listed_on->toDateString());
        $this->assertSame(1, LeadMove::where('lead_id', $left->id)->whereDate('listed_on', '2026-11-05')->count());

        $november5 = $this->dayOf('2026-11-05');
        $this->assertSame(2, $november5['listed']);
        $this->assertSame(1, $november5['worked']);
        $this->assertSame(1, $november5['left']);

        // Still not catered: carried again, and catered ones stay behind.
        $this->sync('2026-11-07');
        $this->assertSame([$left->id => 'leftover'], $this->listOn('2026-11-07'));
    }

    public function test_building_again_changes_nothing_and_tops_up_only_when_new_leads_arrive(): void
    {
        config(['exclusive_leads.daily_quota' => 3]);

        $this->lead('2026-11-05', $this->joanna);
        $this->lead('2026-11-06');
        $this->sync('2026-11-06');
        $rows = ExclusiveListLead::count();
        $moves = LeadMove::count();

        $this->artisan('leads:build-exclusive')->assertSuccessful();

        $this->assertSame($rows, ExclusiveListLead::count());
        $this->assertSame($moves, LeadMove::count());
        $this->assertCount(2, $this->listOn('2026-11-06'));

        $this->lead('2026-11-06');
        $this->lead('2026-11-06');
        $this->artisan('leads:build-exclusive')->assertSuccessful();

        $this->assertCount(3, $this->listOn('2026-11-06'));
    }

    public function test_her_pos_sales_show_on_the_day_without_using_the_quota(): void
    {
        config(['exclusive_leads.daily_quota' => 1]);

        $sale = $this->lead('2026-11-06', $this->joanna, ['order_id' => 'POS-1', 'repeat_purchase' => 'yes']);
        $pool = $this->lead('2026-11-06');

        $this->sync('2026-11-06');

        $this->assertSame([$sale->id => 'pos', $pool->id => 'random'], $this->listOn('2026-11-06'));
        $this->assertSame(1, $this->dayOf('2026-11-06')['listed']);
        $this->assertSame(1, $this->dayOf('2026-11-06')['pos']);
    }

    public function test_generating_the_day_gives_her_list_first_and_the_rest_to_the_segmentation_cras(): void
    {
        config(['exclusive_leads.daily_quota' => 1]);
        $rows = collect(['1', '2', '3'])->map(fn (string $id) => [
            'order_id' => 'R'.$id, 'tracking_number' => 'JT'.$id, 'customer_name' => 'Customer '.$id, 'phone_number' => '0918000000'.$id,
            'product_name' => 'Pterygium', 'qty' => 1, 'delivered_date' => '2026-10-23', 'consumption_days_per_unit' => 15, 'estimated_out_of_stock_date' => '2026-11-06',
        ])->all();
        Http::fake(['*/management/retention-stockout' => Http::response(['count' => 3, 'stock_outs' => $rows, 'retention_detail' => []])]);

        app(LeadGenerator::class)->generate(CarbonImmutable::parse('2026-11-06'));

        $this->assertSame(1, Lead::where('assigned_to', $this->joanna->id)->whereDate('listed_on', '2026-11-06')->count());
        $this->assertSame(2, Lead::where('assigned_to', $this->regina->id)->whereDate('listed_on', '2026-11-06')->count());
        $this->assertCount(1, $this->listOn('2026-11-06'));
    }

    public function test_the_segmentation_assignment_and_fill_leave_her_and_her_customers_alone(): void
    {
        config(['segmentation.max_leads_per_cra' => 5]);

        $unowned = $this->lead('2026-11-06');
        $hersWaiting = $this->lead('2026-11-06');
        CustomerAccount::create(['phone_key' => $this->phoneKey($hersWaiting), 'assigned_cra_id' => $this->joanna->id]);
        $hersInPool = $this->lead('2026-11-02');
        $this->handledTwiceBy($this->joanna, $this->phoneKey($hersInPool));
        $herBacklog = $this->lead('2026-11-02', $this->joanna);
        // Regina's own customer, untouched under Joanna: still stays with Joanna.
        $reginasWithHer = $this->lead('2026-11-06', $this->joanna);
        $this->handledTwiceBy($this->regina, $this->phoneKey($reginasWithHer));

        $day = CarbonImmutable::parse('2026-11-06');
        app(LeadGenerator::class)->assign($day);
        app(BacklogFill::class)->fill($day, collect([$this->joanna, $this->regina]));

        $this->assertSame($this->regina->id, $unowned->fresh()->assigned_to);
        $this->assertNull($hersWaiting->fresh()->assigned_to);
        $this->assertNull($hersInPool->fresh()->assigned_to);
        $this->assertSame($this->joanna->id, $herBacklog->fresh()->assigned_to);
        $this->assertSame('2026-11-02', $herBacklog->fresh()->listed_on->toDateString());
        $this->assertSame($this->joanna->id, $reginasWithHer->fresh()->assigned_to);
    }

    public function test_the_segmentation_tracker_hides_her_from_her_transfer_day_on(): void
    {
        $this->lead('2026-11-06', $this->joanna, ['customer_name' => 'Her November Customer']);
        $this->lead('2026-11-06', $this->regina, ['customer_name' => 'Reginas Customer']);
        $this->lead('2026-10-20', $this->joanna, ['customer_name' => 'Her October Customer']);

        $this->actingAs($this->supervisor)->get(route('segmentation.index', ['date' => '2026-11-06']))->assertOk()
            ->assertSee('Reginas Customer')
            ->assertDontSee('Her November Customer')
            ->assertViewHas('cras', fn ($cras) => $cras->pluck('id')->all() === [$this->regina->id]);

        $this->get(route('segmentation.index', ['date' => '2026-10-20']))->assertOk()
            ->assertSee('Her October Customer')
            ->assertViewHas('cras', fn ($cras) => $cras->contains('id', $this->joanna->id));

        $this->assertNotContains($this->joanna->id, array_column(LeadGenerator::workload(), 'id'));
    }

    public function test_her_listed_lead_cannot_be_reassigned_or_handed_away(): void
    {
        $lead = $this->lead('2026-11-06', $this->joanna);
        $admin = User::create(['email' => 'admin@example.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);

        $this->actingAs($admin)->patchJson(route('segmentation.update', $lead), ['assigned_to' => $this->regina->id])->assertStatus(422);
        $this->assertSame($this->joanna->id, $lead->fresh()->assigned_to);

        $old = $this->lead('2026-11-02', $this->joanna);
        $this->post(route('segmentation.transfer'), ['lead_ids' => [$old->id], 'to' => $this->regina->id])->assertSessionHasErrors('transfer');
        $this->assertSame($this->joanna->id, $old->fresh()->assigned_to);

        $this->handledTwiceBy($this->regina, $this->phoneKey($lead));
        $this->postJson(route('segmentation.handover.store', $lead))->assertStatus(422);
    }

    public function test_a_supervisor_transfers_a_cra_in_and_back_out_and_their_leads_stay_with_them(): void
    {
        $leads = [$this->lead('2026-11-06', $this->regina), $this->lead('2026-11-05', $this->regina, ['status' => 'active'])];

        $this->actingAs($this->supervisor)->post(route('settings.exclusive-leads.store', $this->regina), ['exclusive_from' => '2026-11-05'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame('2026-11-05', $this->regina->fresh()->exclusive_from->toDateString());
        $this->assertNotContains($this->regina->id, LeadGenerator::segmentationCras()->pluck('id'));
        $this->assertArrayHasKey($leads[0]->id, app(ExclusiveLeads::class)->sourcesFor($this->regina, CarbonImmutable::parse('2026-11-06')));
        $this->assertArrayHasKey($leads[1]->id, app(ExclusiveLeads::class)->sourcesFor($this->regina, CarbonImmutable::parse('2026-11-05')));

        $this->post(route('settings.exclusive-leads.store', $this->regina))->assertStatus(422);

        $unassigned = $this->lead('2026-11-06');
        $this->delete(route('settings.exclusive-leads.destroy', $this->regina))->assertRedirect()->assertSessionHas('status');

        $this->assertNull($this->regina->fresh()->exclusive_from);
        $this->assertContains($this->regina->id, LeadGenerator::segmentationCras()->pluck('id'));
        $this->assertSame($this->regina->id, $leads[0]->fresh()->assigned_to);
        $this->assertSame($this->regina->id, $leads[1]->fresh()->assigned_to);
        // Back in the even split, and earlier lists are kept.
        $this->assertSame($this->regina->id, $unassigned->fresh()->assigned_to);
        $this->assertArrayHasKey($leads[0]->id, app(ExclusiveLeads::class)->sourcesFor($this->regina, CarbonImmutable::parse('2026-11-06')));

        $this->delete(route('settings.exclusive-leads.destroy', $this->regina))->assertStatus(422);
    }

    public function test_the_transfer_defaults_to_today_and_rejects_bad_input_and_non_cras(): void
    {
        $this->actingAs($this->supervisor);

        $this->post(route('settings.exclusive-leads.store', $this->regina), ['exclusive_from' => 'soon'])->assertSessionHasErrors('exclusive_from');
        $this->assertNull($this->regina->fresh()->exclusive_from);

        $this->post(route('settings.exclusive-leads.store', $this->supervisor))->assertStatus(422);

        $this->post(route('settings.exclusive-leads.store', $this->regina))->assertRedirect();
        $this->assertSame('2026-11-06', $this->regina->fresh()->exclusive_from->toDateString());
    }

    public function test_only_those_allowed_can_transfer(): void
    {
        $this->post(route('settings.exclusive-leads.store', $this->regina))->assertRedirect(route('login'));

        $this->actingAs($this->joanna)->post(route('settings.exclusive-leads.store', $this->regina))->assertForbidden();
        $this->delete(route('settings.exclusive-leads.destroy', $this->joanna))->assertForbidden();

        $this->assertNull($this->regina->fresh()->exclusive_from);
        $this->assertNotNull($this->joanna->fresh()->exclusive_from);
    }

    public function test_repeat_purchases_count_on_the_day_bought_once_per_customer(): void
    {
        $bought = ['repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-11-03'];
        // Same customer in two number formats on two leads, bought the same day and again later.
        $this->lead('2026-11-01', $this->joanna, [...$bought, 'phone_number' => '09171112222']);
        $this->lead('2026-11-02', $this->joanna, [...$bought, 'phone_number' => '+639171112222']);
        $this->lead('2026-10-20', $this->joanna, ['repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-11-04', 'phone_number' => '639171112222']);
        $this->lead('2026-11-01', $this->joanna, [...$bought, 'phone_number' => '09173334444']);
        // Not counted: another CRA's, another month's, and a Yes-less lead.
        $this->lead('2026-11-01', $this->regina, [...$bought, 'phone_number' => '09175556666']);
        $this->lead('2026-10-01', $this->joanna, ['repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-10-30', 'phone_number' => '09177778888']);
        $this->lead('2026-11-01', $this->joanna, ['repeat_purchase' => 'no', 'phone_number' => '09179990000']);

        $month = app(ExclusiveLeads::class)->month($this->joanna, CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(2, $month['actual']['repeat']);
        $this->assertSame(2, collect($month['days'])->firstWhere('date', '2026-11-03')['repeat']);
        $this->assertSame(1, collect($month['days'])->firstWhere('date', '2026-11-04')['repeat']);
    }

    public function test_confirmed_orders_gross_and_aov_follow_segmentation_productivity(): void
    {
        foreach ([[800, 3], [1000, 2], [5000, PancakeOrder::NOT_COUNTED_STATUSES[0]]] as [$total, $status]) {
            PancakeOrder::create(['pancake_order_id' => 'S'.++$this->seq, 'ordered_on' => '2026-11-03', 'ordered_at' => '2026-11-03 02:00:00',
                'seller_name' => $this->joanna->pancake_name, 'phone_key' => '91700'.$this->seq, 'status' => $status, 'total_price' => $total,
                'conversion_type' => PancakeOrder::SEGMENTATION]);
        }

        $month = app(ExclusiveLeads::class)->month($this->joanna, CarbonImmutable::parse('2026-11-01'));
        $day = collect($month['days'])->firstWhere('date', '2026-11-03');

        $this->assertSame(2, $day['confirmed']);
        $this->assertSame(2, $month['actual']['confirmed']);
        $this->assertSame($day['gross'], $month['actual']['gross']);
        $this->assertSame($day['gross'] / 2, $month['actual']['aov']);
    }

    public function test_the_month_counts_a_carried_lead_on_each_list_and_once_as_a_unique_lead(): void
    {
        config(['exclusive_leads.daily_quota' => 1]);

        $this->lead('2026-11-05', $this->joanna);
        $this->sync('2026-11-05');
        $this->sync('2026-11-06');

        $actual = app(ExclusiveLeads::class)->month($this->joanna->fresh(), CarbonImmutable::parse('2026-11-01'))['actual'];

        $this->assertSame(2, $actual['listed']);
        $this->assertSame(1, $actual['unique_leads']);
        $this->assertSame(1, $actual['mix']['leftover']);
    }

    public function test_the_transfer_migration_moves_only_a_single_matching_active_cra(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'exclusive_from'));
        $migration = require database_path('migrations/2026_11_05_100100_transfer_joanna_paclibare_to_exclusive_leads.php');
        $this->joanna->update(['exclusive_from' => null]);

        $migration->up();
        $this->assertSame('2026-11-01', $this->joanna->fresh()->exclusive_from->toDateString());
        $this->assertNull($this->regina->fresh()->exclusive_from);

        $this->joanna->fresh()->update(['exclusive_from' => null]);
        $this->cra('Anna Paclibare', 'CRD Other');

        $migration->up();
        $this->assertNull($this->joanna->fresh()->exclusive_from);
    }
}
