<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\DutySchedule;
use App\Models\ExclusiveListLead;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\PcReportEntry;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\BacklogFill;
use App\Services\CvrTracker;
use App\Services\DailyIncomeStatement;
use App\Services\ExclusiveLeads;
use App\Services\LeadGenerator;
use App\Services\SegmentationProductivity;
use App\Services\SegmentationStats;
use App\Support\WorkingDate;
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
        config([
            'services.shecom.key' => 'test-key',
            'exclusive_leads.quota_overrides' => ['2026-11-01' => 100, '2026-11-02' => 100, '2026-11-03' => 100, '2026-11-04' => 100],
            'exclusive_leads.build_from' => '2026-11-06',
        ]);
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
        $this->assertSame(['leads' => 7000, 'repeat' => 1400, 'repeat_percent' => 20, 'confirmed_orders' => 1620, 'confirmed_orders_per_day' => 54, 'working_days' => 30, 'high_aov_min' => 895], $month['targets']);
        $this->assertSame(100, $month['days'][3]['quota']);
        $this->assertSame(20, $month['days'][3]['repeat_goal']);
        $this->assertSame(250, $month['days'][4]['quota']);
        $this->assertSame(50, $month['days'][4]['repeat_goal']);
    }

    public function test_confirmed_orders_target_is_54_per_working_day_and_the_month_adds_them_up(): void
    {
        $this->joanna->update(['exclusive_from' => '2026-11-03']);
        DutySchedule::create(['user_id' => $this->joanna->id, 'date' => '2026-11-08', 'type' => DutySchedule::REST]);

        $month = app(ExclusiveLeads::class)->month($this->joanna->fresh(), CarbonImmutable::parse('2026-11-01'));
        $days = collect($month['days'])->keyBy('date');

        $this->assertSame(0, $days['2026-11-02']['confirmed_goal']);
        $this->assertSame(54, $days['2026-11-03']['confirmed_goal']);
        $this->assertSame(54, $days['2026-11-07']['confirmed_goal']);
        $this->assertSame(0, $days['2026-11-08']['confirmed_goal']);
        $this->assertTrue($days['2026-11-08']['is_day_off']);
        $this->assertSame(250, $days['2026-11-08']['quota']);
        $this->assertSame(27, $month['targets']['working_days']);
        $this->assertSame(54 * 27, $month['targets']['confirmed_orders']);
        $this->assertSame($days->sum('confirmed_goal'), $month['targets']['confirmed_orders']);
        $this->assertSame(1400, $month['targets']['repeat']);
        $this->assertSame(7000, $month['targets']['leads']);
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

        $random = $this->lead('2026-11-06', null, ['lead_type' => Lead::TYPE_FSD]);
        $belowLine = $this->lead('2026-11-06', null, ['lead_type' => Lead::TYPE_FSD]);
        $this->delivered($this->phoneKey($belowLine), 894);
        $handled = $this->lead('2026-11-06');
        $this->handledTwiceBy($this->joanna, $this->phoneKey($handled));
        $atLine = $this->lead('2026-11-06');
        $this->delivered($this->phoneKey($atLine), 895);
        $hersVip = $this->lead('2026-11-06');
        CustomerAccount::create(['phone_key' => $this->phoneKey($hersVip), 'assigned_cra_id' => $this->joanna->id]);
        $leftover = $this->lead('2026-11-05', $this->joanna, ['lead_type' => Lead::TYPE_FSD]);

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

    public function test_her_list_follows_who_handled_the_customer_and_leaves_processed_leads_alone(): void
    {
        config(['exclusive_leads.daily_quota' => 5]);

        $sale = fn (User $cra, Lead $lead, string $on) => PancakeOrder::create(['pancake_order_id' => 'H'.++$this->seq, 'ordered_on' => $on, 'ordered_at' => $on.' 02:00:00',
            'seller_name' => $cra->pancake_name, 'phone_key' => $this->phoneKey($lead), 'status' => 3, 'total_price' => 300]);
        // One order is enough to make a customer hers, and one with Regina first stays Regina's.
        $hers = $this->lead('2026-11-06');
        $sale($this->joanna, $hers, '2026-08-01');
        $reginasFirst = $this->lead('2026-11-06');
        $sale($this->regina, $reginasFirst, '2026-07-01');
        $sale($this->joanna, $reginasFirst, '2026-08-01');
        // Regina has processed this one, so even though the customer is Joanna's it stays with her.
        $processed = $this->lead('2026-11-05', $this->regina, ['status' => 'pjr_drop_call']);
        $sale($this->joanna, $processed, '2026-08-01');

        $this->sync('2026-11-06');

        $list = $this->listOn('2026-11-06');
        $this->assertSame('handled', $list[$hers->id]);
        $this->assertArrayNotHasKey($reginasFirst->id, $list);
        $this->assertArrayNotHasKey($processed->id, $list);
        $this->assertSame($this->regina->id, $processed->fresh()->assigned_to);
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
        $left = $this->lead('2026-11-05', $this->joanna, ['lead_type' => Lead::TYPE_FSD]);
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

        $this->lead('2026-11-05', $this->joanna, ['lead_type' => Lead::TYPE_FSD]);
        $this->lead('2026-11-06', null, ['lead_type' => Lead::TYPE_FSD]);
        $this->sync('2026-11-06');
        $rows = ExclusiveListLead::count();
        $moves = LeadMove::count();

        $this->artisan('leads:build-exclusive')->assertSuccessful();

        $this->assertSame($rows, ExclusiveListLead::count());
        $this->assertSame($moves, LeadMove::count());
        $this->assertCount(2, $this->listOn('2026-11-06'));

        $this->lead('2026-11-06', null, ['lead_type' => Lead::TYPE_FSD]);
        $this->lead('2026-11-06', null, ['lead_type' => Lead::TYPE_FSD]);
        $this->artisan('leads:build-exclusive')->assertSuccessful();

        $this->assertCount(3, $this->listOn('2026-11-06'));
    }

    public function test_random_leads_are_fsd_leads_only(): void
    {
        config(['exclusive_leads.daily_quota' => 3]);

        $crdLead = $this->lead('2026-11-06');
        $crdInPool = $this->lead('2026-11-02');
        $fsdLead = $this->lead('2026-11-06', null, ['lead_type' => Lead::TYPE_FSD]);

        $this->sync('2026-11-06');

        $this->assertSame([$fsdLead->id => 'random'], $this->listOn('2026-11-06'));
        $this->assertNull($crdLead->fresh()->assigned_to);
        $this->assertNull($crdInPool->fresh()->assigned_to);
    }

    public function test_an_untouched_leftover_of_another_cras_customer_goes_to_that_cra(): void
    {
        $leftover = $this->lead('2026-11-05', $this->joanna, ['lead_type' => Lead::TYPE_FSD, 'listed_on' => '2026-11-05']);
        $this->handledTwiceBy($this->regina, $this->phoneKey($leftover));

        $this->sync('2026-11-06');

        $this->assertArrayNotHasKey($leftover->id, $this->listOn('2026-11-06'));
        $this->assertSame($this->regina->id, $leftover->fresh()->assigned_to);
        $this->assertSame('2026-11-06', $leftover->fresh()->listed_on->toDateString());
        // Her old day keeps counting it, and the move is logged with nobody as its author.
        $this->assertSame($this->joanna->id, LeadMove::where('lead_id', $leftover->id)->value('assigned_to'));
        $this->assertNull($leftover->transfers()->first()->transferred_by);
    }

    public function test_a_crd_leftover_with_no_reason_to_be_hers_goes_back_to_the_pool_and_is_not_picked_again(): void
    {
        $leftover = $this->lead('2026-11-05', $this->joanna, ['listed_on' => '2026-11-05']);

        $this->sync('2026-11-06');

        $this->assertSame([], $this->listOn('2026-11-06'));
        $this->assertNull($leftover->fresh()->assigned_to);
        $this->assertSame($this->joanna->id, LeadMove::where('lead_id', $leftover->id)->value('assigned_to'));
    }

    public function test_leftovers_that_are_hers_by_the_rules_stay(): void
    {
        $fsd = $this->lead('2026-11-05', $this->joanna, ['lead_type' => Lead::TYPE_FSD, 'listed_on' => '2026-11-05']);
        $handled = $this->lead('2026-11-05', $this->joanna, ['listed_on' => '2026-11-05']);
        $this->handledTwiceBy($this->joanna, $this->phoneKey($handled));
        $written = $this->lead('2026-11-05', $this->joanna, ['listed_on' => '2026-11-05', 'notes' => 'Call after lunch']);
        $this->handledTwiceBy($this->regina, $this->phoneKey($written));

        $this->sync('2026-11-06');

        $list = $this->listOn('2026-11-06');
        foreach ([$fsd, $handled, $written] as $lead) {
            $this->assertSame('leftover', $list[$lead->id]);
            $this->assertSame($this->joanna->id, $lead->fresh()->assigned_to);
        }
    }

    public function test_the_recheck_only_reports_until_applied_then_moves_what_is_not_hers(): void
    {
        $untouched = $this->lead('2026-11-06', $this->joanna, ['listed_on' => '2026-11-06']);
        $this->handledTwiceBy($this->regina, $this->phoneKey($untouched));
        $contacted = $this->lead('2026-09-01', $this->joanna, ['listed_on' => '2026-09-01', 'status' => 'pjr_drop_call', 'contact_date' => '2026-10-10']);
        $this->handledTwiceBy($this->regina, $this->phoneKey($contacted));
        $bought = $this->lead('2026-09-01', $this->joanna, ['listed_on' => '2026-09-01', 'status' => 'pjr_drop_call', 'repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-10-10']);
        $this->handledTwiceBy($this->regina, $this->phoneKey($bought));
        $crdOnList = $this->lead('2026-11-06', $this->joanna, ['listed_on' => '2026-11-06']);
        $crdOlder = $this->lead('2026-09-01', $this->joanna, ['listed_on' => '2026-09-01']);
        $hers = $this->lead('2026-11-06', $this->joanna, ['lead_type' => Lead::TYPE_FSD, 'listed_on' => '2026-11-06']);
        foreach ([$untouched, $crdOnList, $hers] as $lead) {
            ExclusiveListLead::create(['user_id' => $this->joanna->id, 'list_date' => '2026-11-06', 'lead_id' => $lead->id, 'source' => 'random']);
        }

        $this->artisan('leads:recheck-exclusive')->assertSuccessful();

        $this->assertSame($this->joanna->id, $untouched->fresh()->assigned_to);
        $this->assertCount(3, $this->listOn('2026-11-06'));

        $this->artisan('leads:recheck-exclusive', ['--apply' => true])->assertSuccessful();

        $this->assertSame($this->regina->id, $untouched->fresh()->assigned_to);
        $this->assertSame($this->regina->id, $contacted->fresh()->assigned_to);
        $this->assertSame('pjr_drop_call', $contacted->fresh()->status);
        $this->assertNull($crdOnList->fresh()->assigned_to);
        $this->assertSame([$hers->id => 'random'], $this->listOn('2026-11-06'));
        // A purchase and older CRD leads stay; her list keeps nothing it no longer holds, so the same day isn't counted twice.
        $this->assertSame($this->joanna->id, $bought->fresh()->assigned_to);
        $this->assertSame($this->joanna->id, $crdOlder->fresh()->assigned_to);
        $this->assertFalse(LeadMove::where('lead_id', $untouched->id)->exists());
    }

    public function test_her_pos_sales_show_on_the_day_without_using_the_quota(): void
    {
        config(['exclusive_leads.daily_quota' => 1]);

        $sale = $this->lead('2026-11-06', $this->joanna, ['order_id' => 'POS-1', 'repeat_purchase' => 'yes']);
        $pool = $this->lead('2026-11-06', null, ['lead_type' => Lead::TYPE_FSD]);

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
        $fsdLead = $this->lead('2026-11-06', null, ['lead_type' => Lead::TYPE_FSD]);

        app(LeadGenerator::class)->generate(CarbonImmutable::parse('2026-11-06'));

        $this->assertSame([$fsdLead->id => 'random'], $this->listOn('2026-11-06'));
        $this->assertSame(1, Lead::where('assigned_to', $this->joanna->id)->whereDate('listed_on', '2026-11-06')->count());
        $this->assertSame(3, Lead::where('assigned_to', $this->regina->id)->whereDate('listed_on', '2026-11-06')->count());
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

    public function test_a_real_day_shows_its_results_with_the_paired_lead_days_list(): void
    {
        WorkingDate::startTomorrow(CarbonImmutable::parse('2026-10-08'));
        $this->joanna->update(['exclusive_from' => '2026-10-02']);
        config(['exclusive_leads' => require config_path('exclusive_leads.php')]);
        $listed = $this->lead('2026-10-06', $this->joanna, ['repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-11-05']);
        ExclusiveListLead::create(['user_id' => $this->joanna->id, 'list_date' => '2026-10-06', 'lead_id' => $listed->id, 'source' => ExclusiveLeads::HIGH_VALUE]);
        PancakeOrder::create(['pancake_order_id' => 'S'.++$this->seq, 'ordered_on' => '2026-11-05', 'ordered_at' => '2026-11-05 02:00:00',
            'seller_name' => $this->joanna->pancake_name, 'phone_key' => '91700'.$this->seq, 'status' => 3, 'total_price' => 800,
            'conversion_type' => PancakeOrder::SEGMENTATION]);
        DutySchedule::create(['user_id' => $this->joanna->id, 'date' => '2026-11-05', 'type' => DutySchedule::REST]);

        $days = collect(app(ExclusiveLeads::class)->month($this->joanna, CarbonImmutable::parse('2026-11-01'))['days'])->keyBy('date');

        $this->assertSame('2026-10-06', $days['2026-11-05']['lead_date']);
        $this->assertSame(1, $days['2026-11-05']['listed']);
        $this->assertSame(1, $days['2026-11-05']['repeat']);
        $this->assertSame(1, $days['2026-11-05']['confirmed']);
        $this->assertTrue($days['2026-11-05']['is_day_off']);
        $this->assertSame(0, $days['2026-11-05']['confirmed_goal']);
        $this->assertSame(0, $days['2026-11-04']['listed']);
        $this->assertSame(100, $days['2026-11-01']['quota']);

        $this->actingAs($this->supervisor)->get(route('exclusive-leads.index', ['date' => '2026-11-05']))
            ->assertOk()
            ->assertSee('Thursday, Nov 5')
            ->assertSee($listed->customer_name);
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

        $this->lead('2026-11-05', $this->joanna, ['lead_type' => Lead::TYPE_FSD]);
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

    public function test_the_other_modules_read_her_exclusive_list(): void
    {
        config(['exclusive_leads.daily_quota' => 3]);
        $leftover = $this->lead('2026-11-05', $this->joanna, ['listed_on' => '2026-11-05', 'lead_type' => Lead::TYPE_FSD]);
        $worked = $this->lead('2026-11-05', $this->joanna, ['listed_on' => '2026-11-05', 'status' => 'pjr_drop_call', 'contact_date' => '2026-11-05']);
        $picked = $this->lead('2026-11-06', null, ['listed_on' => '2026-11-06', 'lead_type' => Lead::TYPE_FSD]);
        $this->lead('2026-11-06', $this->regina, ['listed_on' => '2026-11-06']);

        $this->sync('2026-11-06');
        $this->assertSame([$leftover->id, $picked->id], array_keys($this->listOn('2026-11-06')));
        $picked->refresh()->forceFill(['contact_time' => '09:00', 'contact_date' => '2026-11-06', 'repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-11-06'])->save();
        $order = ['seller_name' => $this->joanna->pancake_name, 'status' => 3, 'total_price' => 1000, 'conversion_type' => PancakeOrder::SEGMENTATION, 'tags' => ['CRD - SEGMENTATION']];
        PancakeOrder::create(['pancake_order_id' => 'S1', 'ordered_on' => '2026-11-06', 'ordered_at' => '2026-11-06 02:00:00', 'phone_key' => $this->phoneKey($picked), ...$order]);

        $month = app(ExclusiveLeads::class)->month($this->joanna->fresh(), CarbonImmutable::parse('2026-11-01'));
        $days = collect($month['days'])->keyBy('date');
        $joanna = $this->joanna->fresh();

        // Segmentation Productivity: each day's assigned is her list that day, the carried lead counted on both.
        $productivity = app(SegmentationProductivity::class)->days(collect([$joanna]), CarbonImmutable::parse('2026-11-05'), CarbonImmutable::parse('2026-11-06'))[$joanna->id];
        $this->assertSame($days['2026-11-05']['listed'], $productivity['2026-11-05']['assigned']);
        $this->assertSame($days['2026-11-06']['listed'], $productivity['2026-11-06']['assigned']);
        $this->assertSame($days['2026-11-06']['confirmed'], $productivity['2026-11-06']['confirmed']);

        // CVR Tracker: the order is an assigned lead's, and the statuses come from each day's list.
        $today = app(CvrTracker::class)->day($joanna, CarbonImmutable::parse('2026-11-06'));
        $this->assertSame(1, $today['summary']['orders']);
        $this->assertSame(1, $today['summary']['assigned_leads']);
        $this->assertSame(1, $days['2026-11-06']['confirmed']);
        $this->assertSame(1, $today['summary']['engaged']);
        $this->assertSame(1, app(CvrTracker::class)->day($joanna, CarbonImmutable::parse('2026-11-05'))['summary']['not_answering']);

        // Repeat Purchases: the same rows as her page's goal; an FSD lead's purchase is on the Retained tile.
        $this->assertSame(1, $month['actual']['repeat']);
        $this->assertSame([$picked->id], app(SegmentationStats::class)->repeatPurchases(collect([$joanna]), 'retained', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))->pluck('id')->all());

        // The page's pickers still list her everywhere but the Segmentation Tracker.
        $this->actingAs($this->supervisor)->get(route('pc-report.index', ['date' => '2026-11-06']))->assertOk()->assertSee('Joanna Rose');
        $this->actingAs($this->supervisor)->get(route('cvr-tracker.index', ['date' => '2026-11-06', 'cra' => $joanna->id]))->assertOk()->assertViewHas('data', fn (array $data) => $data['summary']['orders'] === 1);
    }

    public function test_daily_is_counts_her_orders_and_typed_entries(): void
    {
        Product::create(['name' => 'Sinuxyl', 'unit_cost' => 35]);
        $pageName = 'Sinuxyl Nasal Spray';

        PcReportEntry::create(['user_id' => $this->joanna->id, 'page' => $pageName, 'category' => '', 'date' => '2026-11-05', 'inquiries' => 40, 'engagements' => 20]);
        PancakeOrder::create(['pancake_order_id' => 'S2', 'ordered_on' => '2026-11-05', 'ordered_at' => '2026-11-05 02:00:00', 'seller_name' => strtoupper($this->joanna->pancake_name),
            'customer_name' => 'Buyer', 'status' => 3, 'total_price' => 1000, 'page_name' => $pageName, 'tags' => [], 'non_crd' => false]);

        $statement = app(DailyIncomeStatement::class)->day(LeadGenerator::cras(), CarbonImmutable::parse('2026-11-05'));

        $this->assertArrayHasKey($this->joanna->id, $statement['cras']);
        $this->assertSame(40, $statement['cras'][$this->joanna->id]['total']['leads']);
        $this->assertEquals(1000, $statement['cras'][$this->joanna->id]['total']['gross']);
    }

    public function test_weekly_segmentation_drops_her_from_her_transfer_day_on(): void
    {
        $this->lead('2026-11-02', $this->joanna, ['listed_on' => '2026-11-02']);
        $this->lead('2026-11-02', $this->regina, ['listed_on' => '2026-11-02']);

        $november = $this->actingAs($this->supervisor)->get(route('segmentation.weekly', ['month' => '2026-11', 'week' => 1]))->assertOk();
        $this->assertSame(['Regina'], $november->viewData('rows')->map(fn (array $row) => $row['cra']->displayName())->all());
        $this->assertSame(1, $november->viewData('totals')['assigned']);

        $this->lead('2026-10-15', $this->joanna, ['listed_on' => '2026-10-15']);
        $october = $this->actingAs($this->supervisor)->get(route('segmentation.weekly', ['month' => '2026-10', 'week' => 3]))->assertOk();
        $this->assertContains('Joanna Rose', $october->viewData('rows')->map(fn (array $row) => $row['cra']->displayName())->all());
    }

    public function test_opening_the_segmentation_tracker_takes_her_to_her_exclusive_page(): void
    {
        $this->actingAs($this->joanna)->get(route('segmentation.index'))->assertRedirect(route('exclusive-leads.index'));
        $this->actingAs($this->joanna)->get(route('segmentation.index', ['date' => '2026-10-31']))->assertOk();
        $this->actingAs($this->regina)->get(route('segmentation.index'))->assertOk();
    }
}
