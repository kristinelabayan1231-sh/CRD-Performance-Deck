<?php

namespace Tests\Feature;

use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ConversionBreakdown;
use App\Services\CustomerChurn;
use App\Services\DailyIncomeStatement;
use App\Services\DeliveredRetention;
use App\Services\HighValueCustomers;
use App\Services\LeadGenerator;
use App\Services\PancakePageBackfill;
use App\Services\PresentationBoard;
use App\Services\PresentationBoardCras;
use App\Services\SegmentationStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PresentationBoardTest extends TestCase
{
    use RefreshDatabase;

    private const CRD_SELLER = 'CRD REJ VERGARA';

    private const FSD_SELLER = 'FSD SELLER';

    private const PAGE = '111';

    private User $supervisor;

    private User $cra;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00', 'Asia/Manila'));
        $this->supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $this->cra = User::create([
            'email' => 'regina@example.com', 'display_name' => 'Regina', 'pancake_name' => self::CRD_SELLER,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
        Setting::put('customers.crd_accounts', json_encode([self::CRD_SELLER]));
        Product::create(['name' => 'Sinuxyl', 'keywords' => 'Sinuxyl', 'consumption_days' => 30, 'srp' => 500]);
        PancakePage::create(['name' => 'Sinuxyl Page', 'page_id' => self::PAGE, 'access_token' => 'token', 'is_active' => true]);
    }

    /**
     * A delivered order with its Pancake order; runs out 29 days after $delivered with the default qty.
     */
    private function delivery(string $phone, string $delivered, string $team, ?string $page = self::PAGE, string $product = 'Sinuxyl', string $orderedOn = '', int $qty = 1): string
    {
        $id = 'L'.++$this->seq;
        LogisticsOrder::create([
            'order_id' => $id, 'team' => $team, 'source' => LogisticsOrder::SOURCE_LOGISTICS, 'customer_name' => 'Buyer', 'phone_number' => $phone,
            'phone_key' => $phone, 'product' => $product, 'qty' => $qty, 'delivered_date' => $delivered,
        ]);
        PancakeOrder::create([
            'pancake_order_id' => $id, 'ordered_on' => $orderedOn ?: $delivered, 'seller_name' => $team === LogisticsOrder::TEAM_CRD ? self::CRD_SELLER : self::FSD_SELLER,
            'phone_number' => $phone, 'phone_key' => $phone, 'status' => 3, 'total_price' => 1000, 'page_id' => $page, 'items' => [['name' => $product, 'qty' => $qty]],
        ]);

        return $id;
    }

    private function sale(string $day, float $total, string $type = PancakeOrder::SEGMENTATION, int $status = 3, ?float $shecom = null): PancakeOrder
    {
        $order = PancakeOrder::create([
            'pancake_order_id' => 'S'.++$this->seq, 'ordered_on' => $day, 'seller_name' => self::CRD_SELLER, 'phone_number' => '09'.$this->seq, 'phone_key' => '09'.$this->seq,
            'status' => $status, 'total_price' => $total, 'conversion_type' => $type, 'items' => [['name' => 'Sinuxyl', 'qty' => 2]],
        ]);
        $order->forceFill(['shecom_sales' => $shecom])->save();

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function month(array $snapshot, string $key): array
    {
        return app(PresentationBoard::class)->range($snapshot, $key, $key, 'monthly')['months'][0];
    }

    private function snapshot(): array
    {
        return app(PresentationBoard::class)->build(CarbonImmutable::parse('2026-11-06'));
    }

    public function test_one_customer_is_one_lead_per_month_on_their_first_out_of_stock_day(): void
    {
        $this->delivery('0911', '2026-02-01', LogisticsOrder::TEAM_CRD);
        $this->delivery('0911', '2026-02-10', LogisticsOrder::TEAM_CRD);
        $this->delivery('0911', '2026-04-01', LogisticsOrder::TEAM_CRD);

        $snapshot = $this->snapshot();

        $this->assertSame(1, $this->month($snapshot, '2026-03')['crd_leads']);
        $this->assertSame(1, $snapshot['days']['2026-03-02']['crd_leads']);
        $this->assertSame(0, $snapshot['days']['2026-03-11']['crd_leads']);
        $this->assertSame(1, $this->month($snapshot, '2026-04')['crd_leads'] + $this->month($snapshot, '2026-05')['crd_leads']);
    }

    public function test_a_customer_in_both_teams_in_one_month_counts_once_under_crd(): void
    {
        // FSD runs out Mar 2; the CRD order comes after it, so the customer wasn't handled yet by then.
        $this->delivery('0922', '2026-02-01', LogisticsOrder::TEAM_FSD);
        $this->delivery('0922', '2026-02-20', LogisticsOrder::TEAM_CRD);

        $march = $this->month($this->snapshot(), '2026-03');

        $this->assertSame(1, $march['crd_leads']);
        $this->assertSame(0, $march['fsd_leads']);
    }

    public function test_an_order_in_both_delivery_records_counts_once(): void
    {
        $id = $this->delivery('1701', '2026-02-01', LogisticsOrder::TEAM_FSD);
        DeliveredOrder::create(['order_id' => $id, 'customer_name' => 'Buyer', 'phone_number' => '1701', 'product_raw' => 'Sinuxyl', 'qty' => 1, 'delivered_date' => '2025-12-20', 'source' => DeliveredOrder::SOURCE_PANCAKE]);

        $snapshot = $this->snapshot();

        $this->assertSame(1, $this->month($snapshot, '2026-03')['fsd_leads']);
        $this->assertSame(0, $this->month($snapshot, '2026-01')['fsd_leads']);
        $this->assertSame(1, $this->month($snapshot, '2026-02')['added']);
    }

    public function test_a_delivery_without_its_own_quantity_uses_the_saved_one_over_the_order_items(): void
    {
        // 3 × 30 days from Feb 1 runs out May 1; the order's items say 1, which would be Mar 2.
        $id = $this->delivery('1501', '2026-02-01', LogisticsOrder::TEAM_FSD);
        LogisticsOrder::where('order_id', $id)->update(['qty' => null]);
        DeliveredOrder::create(['order_id' => $id, 'customer_name' => 'Buyer', 'phone_number' => '1501', 'product_raw' => 'Sinuxyl', 'qty' => 3, 'delivered_date' => '2026-02-01', 'source' => DeliveredOrder::SOURCE_PANCAKE]);

        $snapshot = $this->snapshot();

        $this->assertSame(0, $this->month($snapshot, '2026-03')['fsd_leads']);
        $this->assertSame(1, $this->month($snapshot, '2026-05')['fsd_leads']);
    }

    public function test_an_fsd_customer_already_handled_by_a_cra_is_not_an_fsd_lead(): void
    {
        $this->delivery('0933', '2026-02-01', LogisticsOrder::TEAM_FSD);
        PancakeOrder::create(['pancake_order_id' => 'H1', 'ordered_on' => '2026-01-20', 'seller_name' => self::CRD_SELLER, 'phone_key' => '0933', 'status' => 3, 'total_price' => 500]);
        $this->delivery('0944', '2026-02-01', LogisticsOrder::TEAM_FSD);

        $this->assertSame(1, $this->month($this->snapshot(), '2026-03')['fsd_leads']);
    }

    public function test_leads_follow_the_listed_pages_and_products(): void
    {
        $this->delivery('0955', '2026-02-01', LogisticsOrder::TEAM_FSD, page: '999');
        $this->delivery('0956', '2026-02-01', LogisticsOrder::TEAM_FSD, product: 'Not On The List');
        PancakePage::create(['name' => 'New Page', 'page_id' => '222', 'access_token' => 'token', 'is_active' => true, 'counts_from' => '2026-06-01']);
        $this->delivery('0957', '2026-02-01', LogisticsOrder::TEAM_FSD, page: '222');

        $march = $this->month($this->snapshot(), '2026-03');

        $this->assertSame(0, $march['fsd_leads'] + $march['fsd_waiting']);
    }

    public function test_an_order_without_its_page_waits_until_the_backfill_finishes_its_month(): void
    {
        $this->delivery('0966', '2026-02-01', LogisticsOrder::TEAM_FSD, page: null);

        $waiting = $this->month($this->snapshot(), '2026-03');
        $this->assertSame(0, $waiting['fsd_leads']);
        $this->assertSame(1, $waiting['fsd_waiting']);

        Setting::put(PancakePageBackfill::SETTING, json_encode([self::PAGE.'-2026-02']));

        $finished = $this->month($this->snapshot(), '2026-03');
        $this->assertSame(0, $finished['fsd_leads']);
        $this->assertSame(0, $finished['fsd_waiting']);
    }

    public function test_added_is_a_customers_first_delivery_from_fsd(): void
    {
        $this->delivery('0971', '2026-03-05', LogisticsOrder::TEAM_FSD);
        $this->delivery('0972', '2026-01-05', LogisticsOrder::TEAM_FSD);
        $this->delivery('0972', '2026-03-05', LogisticsOrder::TEAM_FSD);
        $this->delivery('0973', '2026-03-05', LogisticsOrder::TEAM_CRD);

        $snapshot = $this->snapshot();

        $this->assertSame(1, $this->month($snapshot, '2026-03')['added']);
        $this->assertSame(1, $this->month($snapshot, '2026-01')['added']);
    }

    public function test_months_before_the_tracker_match_the_dashboards_retained_and_repeat(): void
    {
        $this->delivery('0981', '2026-03-05', LogisticsOrder::TEAM_FSD);
        $this->delivery('0981', '2026-03-20', LogisticsOrder::TEAM_FSD);
        $this->delivery('0982', '2026-03-06', LogisticsOrder::TEAM_FSD);
        $this->delivery('0983', '2026-03-07', LogisticsOrder::TEAM_CRD);
        PancakeOrder::create(['pancake_order_id' => 'R1', 'ordered_on' => '2026-03-25', 'seller_name' => self::CRD_SELLER, 'phone_key' => '0981', 'status' => 3, 'total_price' => 500]);
        PancakeOrder::create(['pancake_order_id' => 'R2', 'ordered_on' => '2026-04-02', 'seller_name' => self::CRD_SELLER, 'phone_key' => '0983', 'status' => 3, 'total_price' => 500]);

        $march = $this->month($this->snapshot(), '2026-03');
        $dashboard = app(DeliveredRetention::class)->customers(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

        $this->assertSame('deliveries', $march['source']);
        $this->assertSame(1, $march['retained']);
        $this->assertSame($dashboard['retained'], $march['retained']);
        $this->assertSame($dashboard['repeat'], $march['repeat']);
        $this->assertSame($dashboard['leads']['retained'], $march['retained_base']);
        $this->assertSame($dashboard['leads']['repeat'], $march['repeat_base']);
    }

    public function test_tracker_months_match_the_dashboards_retained_and_repeat(): void
    {
        foreach ([['0991', Lead::TYPE_FSD, '2026-10-05', '2026-10-05'], ['0992', Lead::TYPE_CRD, '2026-10-06', '2026-10-20'], ['0993', Lead::TYPE_CRD, '2026-10-07', null]] as $i => [$phone, $type, $listed, $bought]) {
            Lead::create([
                'order_id' => "T{$i}", 'customer_name' => 'Buyer', 'phone_number' => $phone, 'product_name' => 'Sinuxyl', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 30,
                'est_out_of_stock_date' => $listed, 'listed_on' => $listed, 'lead_type' => $type, 'assigned_to' => $this->cra->id,
                'repeat_purchase' => $bought ? 'yes' : null, 'repeat_purchase_on' => $bought,
            ]);
        }

        $october = $this->month($this->snapshot(), '2026-10');
        $from = CarbonImmutable::parse('2026-10-01');
        $to = CarbonImmutable::parse('2026-10-31');
        $dashboard = app(SegmentationStats::class)->customers(LeadGenerator::cras(), $from, $to, $from, $to);

        $this->assertSame('tracker', $october['source']);
        $this->assertSame($dashboard['retained'], $october['retained']);
        $this->assertSame($dashboard['repeat'], $october['repeat']);
        $this->assertSame($dashboard['leads']['retained'], $october['retained_base']);
        $this->assertSame($dashboard['leads']['repeat'], $october['repeat_base']);
        $this->assertNotNull($october['conversion']);
    }

    public function test_churn_matches_customer_churn_and_adds_up_from_its_days(): void
    {
        $this->delivery('1001', '2026-05-01', LogisticsOrder::TEAM_CRD);
        $this->delivery('1002', '2026-05-02', LogisticsOrder::TEAM_FSD);
        $this->delivery('1002', '2026-06-10', LogisticsOrder::TEAM_FSD);

        $july = $this->month($this->snapshot(), '2026-07');
        $churn = app(CustomerChurn::class)->for(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));

        $this->assertSame($churn['all']['customers'], $july['all_due']);
        $this->assertSame($churn['all']['lost'], $july['all_lost']);
        $this->assertSame($churn['fsd']['lost'], $july['fsd_lost']);
        $this->assertSame($churn['crd']['lost'], $july['crd_lost']);
        $this->assertSame($churn['all']['reactivation'], $july['all_reactivation']);
    }

    public function test_sales_are_conversion_breakdowns_gross_of_every_cra_split_over_products(): void
    {
        $this->sale('2026-08-03', 1000);
        $this->sale('2026-08-04', 400, status: PancakeOrder::CANCELED);
        $this->sale('2026-08-05', 300, status: 7);
        $this->sale('2026-08-06', 900, shecom: 800);
        PancakeOrder::create(['pancake_order_id' => 'NOTCRA', 'ordered_on' => '2026-08-03', 'seller_name' => self::FSD_SELLER, 'phone_key' => '09', 'status' => 3, 'total_price' => 5000, 'conversion_type' => PancakeOrder::SEGMENTATION]);

        $board = app(PresentationBoard::class);
        $range = $board->range($this->snapshot(), '2026-08', '2026-08', 'monthly');
        $from = CarbonImmutable::parse('2026-08-01');
        $to = CarbonImmutable::parse('2026-08-31');
        $breakdown = ConversionBreakdown::sum(app(ConversionBreakdown::class)->days(LeadGenerator::cras(), $from, $to)[$this->cra->id]);

        $this->assertEquals(2200, $range['totals']['gross']);
        $this->assertEquals($breakdown['gross'], $range['totals']['gross']);
        $this->assertSame($breakdown['orders'], $range['totals']['orders']);
        $this->assertEquals(800, $range['totals']['shecom_gross']);
        $this->assertSame('Sinuxyl', $range['products'][0]['name']);
        $this->assertEquals(2200, $range['products'][0]['sales']);
        $this->assertSame(2, $range['products'][0]['orders']);
    }

    public function test_totals_add_the_months_and_weeks_add_up_to_their_month(): void
    {
        $this->delivery('1101', '2026-02-01', LogisticsOrder::TEAM_FSD);
        $this->delivery('1101', '2026-03-10', LogisticsOrder::TEAM_FSD);
        $this->delivery('1102', '2026-02-20', LogisticsOrder::TEAM_FSD);

        $board = app(PresentationBoard::class);
        $snapshot = $this->snapshot();
        $weekly = $board->range($snapshot, '2026-03', '2026-03', 'weekly');
        $both = $board->range($snapshot, '2026-03', '2026-04', 'daily');

        $this->assertSame(3, $both['totals']['fsd_leads']);
        $this->assertSame($weekly['totals']['fsd_leads'], collect($weekly['periods'])->sum('fsd_leads'));
        $this->assertCount(61, $both['periods']);
    }

    public function test_cra_performance_gross_matches_the_boards_sales_and_capacity_divides_by_days_answered(): void
    {
        $this->sale('2026-06-03', 700)->forceFill(['conversion_type' => null])->save();
        $this->sale('2026-08-03', 1000);
        $this->sale('2026-08-04', 400, status: PancakeOrder::CANCELED);
        $this->sale('2026-08-06', 900, shecom: 800);
        PancakeEngagement::create(['pancake_user_id' => 'U1', 'staff_name' => self::CRD_SELLER, 'date' => '2026-08-03', 'engagements' => 30]);
        PancakeEngagement::create(['pancake_user_id' => 'U1', 'staff_name' => self::CRD_SELLER, 'date' => '2026-08-04', 'engagements' => 10]);
        $this->mock(DailyIncomeStatement::class)->shouldReceive('netIncome')->andReturn([$this->cra->id => 0.0]);

        $cras = app(PresentationBoardCras::class);
        $snapshot = $cras->build(CarbonImmutable::parse('2026-11-06'));
        $row = $cras->range($snapshot, '2026-08', '2026-08')['rows'][0];
        $board = app(PresentationBoard::class)->range($this->snapshot(), '2026-08', '2026-08', 'monthly');

        $this->assertEquals($board['totals']['gross'], $row['gross']);
        $this->assertEquals(2200, $row['gross']);
        $this->assertEquals(20, $row['capacity']);
        $this->assertSame(2, $row['answered_days']);
        $this->assertEquals(2900, $cras->range($snapshot, '2026-01', '2026-11')['gross']);
    }

    public function test_cra_performance_ni_shows_only_with_november_in_the_range_and_ranks_by_gross(): void
    {
        $other = User::create([
            'email' => 'lhea@example.com', 'display_name' => 'Lhea', 'pancake_name' => 'CRD LHEA',
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
        $empty = ['gross' => 0.0, 'answered' => 0, 'answered_days' => 0, 'ni' => null];
        $snapshot = [
            'months' => [
                '2026-10' => [$this->cra->id => [...$empty, 'gross' => 100.0], $other->id => [...$empty, 'gross' => 900.0]],
                '2026-11' => [$this->cra->id => [...$empty, 'gross' => 100.0, 'ni' => -50.0], $other->id => [...$empty, 'gross' => 100.0, 'ni' => 0.0]],
            ],
            'high_value' => [$this->cra->id => ['high_aov' => 3, 'vip' => 1]],
        ];
        $cras = app(PresentationBoardCras::class);

        $october = $cras->range($snapshot, '2026-10', '2026-10');
        $this->assertSame('Lhea', $october['rows'][0]['user']->displayName());
        $this->assertSame(1, $october['rows'][0]['rank']);
        $this->assertEquals(.9, $october['rows'][0]['share']);
        $this->assertNull($october['rows'][0]['ni']);

        $both = $cras->range($snapshot, '2026-10', '2026-11');
        $this->assertEquals(0.0, $both['rows'][0]['ni']);
        $this->assertNotNull($both['rows'][0]['ni']);
        $this->assertEquals(-50.0, $both['rows'][1]['ni']);
        $this->assertSame(3, $both['high_aov']);
    }

    public function test_cra_performance_counts_high_aov_and_vip_under_the_cra_assigned_now(): void
    {
        $this->mock(HighValueCustomers::class, function ($mock) {
            $mock->shouldReceive('all')->andReturn([
                '9171' => ['high_aov' => true, 'vip' => true],
                '9172' => ['high_aov' => true, 'vip' => false],
                '9173' => ['high_aov' => true, 'vip' => false],
            ]);
            $mock->shouldReceive('crasFor')->andReturn(['9171' => $this->cra->id, '9172' => $this->cra->id]);
        });
        $this->mock(DailyIncomeStatement::class)->shouldReceive('netIncome')->andReturn([$this->cra->id => 0.0]);

        $high = app(PresentationBoardCras::class)->build(CarbonImmutable::parse('2026-11-06'))['high_value'][$this->cra->id];

        $this->assertSame(['high_aov' => 2, 'vip' => 1], $high);
    }

    public function test_the_page_shows_each_cras_card_and_says_so_until_the_first_cra_snapshot(): void
    {
        Cache::forever(PresentationBoard::CACHE_KEY, $this->snapshot());

        $this->actingAs($this->supervisor)->get(route('presentation-board.index'))
            ->assertOk()->assertSee('These figures appear after the next scheduled refresh.');

        Cache::forever(PresentationBoardCras::CACHE_KEY, [
            'months' => ['2026-11' => [$this->cra->id => ['gross' => 2000.0, 'answered' => 120, 'answered_days' => 2, 'ni' => -500.0]]],
            'high_value' => [$this->cra->id => ['high_aov' => 7, 'vip' => 2]],
        ]);

        $this->actingAs($this->supervisor)->get(route('presentation-board.index'))
            ->assertOk()
            ->assertSee('CRA performance, year to date')
            ->assertSee('Regina')
            ->assertSee('₱2,000')
            ->assertSee('−₱500')
            ->assertSee('100.0% of team gross')
            ->assertDontSee('These figures appear after the next scheduled refresh.');
    }

    public function test_the_page_needs_its_permission_and_says_so_until_the_first_snapshot(): void
    {
        $this->actingAs($this->cra)->get(route('presentation-board.index'))->assertForbidden();

        $this->actingAs($this->supervisor)->get(route('presentation-board.index'))
            ->assertOk()->assertSee('being prepared');
    }

    public function test_the_page_shows_the_backfill_label_only_until_it_finishes(): void
    {
        $this->sale('2026-08-03', 1000);
        app(PresentationBoard::class)->refresh();

        $this->actingAs($this->supervisor)->get(route('presentation-board.index'))
            ->assertOk()
            ->assertSee('In short')
            ->assertSee('Overall total sales')
            ->assertSee('₱1,000')
            ->assertSee('Older months may still go up or down until it finishes.')
            ->assertSee('0 of 17 page-months loaded', false);

        $done = array_map(fn (int $i) => self::PAGE.'-'.CarbonImmutable::parse('2025-06-01')->addMonths($i)->format('Y-m'), range(0, 16));
        Setting::put(PancakePageBackfill::SETTING, json_encode($done));

        $this->get(route('presentation-board.index'))->assertOk()->assertDontSee('Older months may still');
    }

    public function test_a_figure_opens_the_same_customers_it_counts(): void
    {
        $this->delivery('1301', '2026-02-01', LogisticsOrder::TEAM_FSD);
        $this->delivery('1301', '2026-02-10', LogisticsOrder::TEAM_FSD);
        $this->delivery('1302', '2026-02-03', LogisticsOrder::TEAM_CRD);
        $this->delivery('1303', '2026-02-04', LogisticsOrder::TEAM_FSD, page: null);
        $board = app(PresentationBoard::class);
        $board->refresh();
        $march = $this->month($board->snapshot(), '2026-03');

        foreach (['fsd_leads' => ['1301'], 'crd_leads' => ['1302'], 'fsd_waiting' => ['1303']] as $metric => $customers) {
            $rows = $board->listed($metric, '2026-03', '2026-03');
            $this->assertSame($customers, array_column($rows, 0));
            $this->assertSame($march[$metric], count($rows));
        }

        $this->actingAs($this->supervisor)->get(route('presentation-board.index', ['from' => '2026-03', 'to' => '2026-03']))
            ->assertOk()
            ->assertSee(route('presentation-board.customers', ['metric' => 'fsd_leads', 'from' => '2026-03', 'to' => '2026-03']));

        $this->get(route('presentation-board.index'))->assertOk()
            ->assertSee(route('customers.churn', ['from' => '2026-07-01', 'to' => '2026-11-06', 'status' => 'lost', 'list' => 'fsd']));
        $this->get(route('presentation-board.customers', ['metric' => 'fsd_leads', 'from' => '2026-03', 'to' => '2026-03']))
            ->assertOk()->assertSee('FSD leads')->assertSee('1 customer,')->assertSeeInOrder(['1301', 'Sinuxyl', 'L1', 'Feb 1, 2026', 'Mar 2, 2026']);
    }

    public function test_retained_and_repeat_open_their_customers_from_deliveries_and_the_tracker(): void
    {
        $this->delivery('1401', '2026-03-05', LogisticsOrder::TEAM_FSD);
        PancakeOrder::create(['pancake_order_id' => 'R9', 'ordered_on' => '2026-03-25', 'seller_name' => self::CRD_SELLER, 'phone_key' => '1401', 'status' => 3, 'total_price' => 500]);
        Lead::create([
            'order_id' => 'T9', 'customer_name' => 'Buyer', 'phone_number' => '09171234567', 'product_name' => 'Sinuxyl', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => '2026-10-01', 'listed_on' => '2026-10-01', 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $this->cra->id, 'repeat_purchase' => 'yes', 'repeat_purchase_on' => '2026-10-20',
        ]);
        $board = app(PresentationBoard::class);
        $board->refresh();

        $this->assertSame(['1401'], array_column($board->listed('retained', '2026-03', '2026-03'), 0));
        $this->assertSame([LeadGenerator::normalizePhone('09171234567')], array_column($board->listed('repeat', '2026-10', '2026-10'), 0));
    }

    /**
     * Three customers delivered on $delivered: a High AOV one, a VIP and a plain one, with their names, on team $team.
     *
     * @return array{high_aov: string, vip: string, plain: string} order ids
     */
    private function valuedCustomers(string $delivered): array
    {
        // The ₱500 product's CLTV is ₱5,000 here: Vina reached it, Ana's ₱2,500 is only High AOV.
        config(['customers.high_aov_min' => 2000, 'customers.cltv_units' => 10]);
        $orders = [];
        foreach ([['high_aov', '9171111111', 'Ana Cruz', 2500, 1], ['vip', '9172222222', 'Vina Lim', 15000, 2], ['plain', '9173333333', 'Ben Reyes', 500, 1]] as [$key, $phone, $name, $total, $qty]) {
            $orders[$key] = $this->delivery($phone, $delivered, LogisticsOrder::TEAM_CRD, qty: $qty);
            LogisticsOrder::where('order_id', $orders[$key])->update(['customer_name' => $name]);
            PancakeOrder::where('pancake_order_id', $orders[$key])->update(['total_price' => $total, 'customer_name' => $name, 'conversion_type' => PancakeOrder::SEGMENTATION]);
            DeliveredOrder::create(['order_id' => $orders[$key], 'source' => DeliveredOrder::SOURCE_SHECOM, 'customer_name' => $name, 'phone_number' => $phone, 'product_raw' => 'Sinuxyl', 'qty' => $qty, 'delivered_date' => $delivered]);
        }

        return $orders;
    }

    public function test_a_board_customer_list_can_be_searched_and_narrowed_to_high_aov_or_vip(): void
    {
        $orders = $this->valuedCustomers('2026-02-03');
        // 2 units run out a month later, so the range covers both months.
        $board = app(PresentationBoard::class);
        $board->refresh();
        $query = ['metric' => 'crd_leads', 'from' => '2026-03', 'to' => '2026-08'];
        $list = fn (array $extra = []) => $this->actingAs($this->supervisor)->get(route('presentation-board.customers', [...$query, ...$extra]))->assertOk();

        $list()->assertSee('Ana Cruz')->assertSee('Vina Lim')->assertSee('Ben Reyes')->assertSee('HIGH AOV')->assertSee('VIP');
        $list(['show' => 'high_aov'])->assertSee('Ana Cruz')->assertDontSee('Ben Reyes')->assertSee('2 of the 3 the board counts');
        $list(['show' => 'vip'])->assertSee('Vina Lim')->assertDontSee('Ana Cruz')->assertDontSee('Ben Reyes');

        $list(['search' => 'ana cr'])->assertSee('Ana Cruz')->assertDontSee('Ben Reyes')->assertDontSee('Vina Lim');
        $list(['search' => '0917 333 3333'])->assertSee('Ben Reyes')->assertDontSee('Ana Cruz');
        $list(['search' => $orders['vip']])->assertSee('Vina Lim')->assertDontSee('Ana Cruz');
        $list(['search' => 'sinuxyl'])->assertSee('Ana Cruz')->assertSee('Ben Reyes');
        $list(['search' => 'nobody here'])->assertSee('No customers match.');
        // Together they narrow further.
        $list(['search' => 'ben', 'show' => 'high_aov'])->assertSee('No customers match.');

        $this->get(route('presentation-board.customers', [...$query, 'show' => 'everyone']))->assertSessionHasErrors('show');
    }

    public function test_the_churn_list_is_searched_and_filtered_but_its_tiles_keep_the_totals(): void
    {
        $this->valuedCustomers('2026-09-01');
        $churn = fn (array $extra = []) => $this->actingAs($this->supervisor)->get(route('customers.churn', ['from' => '2026-10-01', 'to' => '2026-11-06', ...$extra]))->assertOk();

        $churn()->assertSee('Ana Cruz')->assertSee('Ben Reyes')->assertSee('HIGH AOV');
        $due = $churn()->viewData('counts')['due'];

        $churn(['show' => 'high_aov'])->assertSee('Ana Cruz')->assertDontSee('Ben Reyes')->assertViewHas('counts', fn (array $counts) => $counts['due'] === $due);
        $churn(['search' => 'ben'])->assertSee('Ben Reyes')->assertDontSee('Ana Cruz');
        // The tiles keep the search on when switching status.
        $churn(['search' => 'ben'])->assertSee('search=ben', false);
    }

    public function test_confirmed_orders_are_searched_and_filtered_and_the_totals_follow(): void
    {
        $orders = $this->valuedCustomers('2026-10-05');
        $list = fn (array $extra = []) => $this->actingAs($this->supervisor)->get(route('conversion.orders', ['from' => '2026-10-01', 'to' => '2026-10-31', ...$extra]))->assertOk();

        $list()->assertViewHas('count', 3)->assertSee('HIGH AOV');
        $list(['show' => 'high_aov'])->assertViewHas('count', 2)->assertSee('Ana Cruz')->assertDontSee('Ben Reyes');
        $list(['show' => 'vip'])->assertViewHas('count', 1)->assertViewHas('gross', 15000.0)->assertSee('Vina Lim')->assertDontSee('Ana Cruz');
        $list(['search' => 'ben'])->assertViewHas('count', 1)->assertSee('Ben Reyes')->assertDontSee('Ana Cruz');
        $list(['search' => '9171111111'])->assertSee('Ana Cruz')->assertDontSee('Ben Reyes');
        $list(['search' => $orders['plain']])->assertSee('Ben Reyes')->assertDontSee('Vina Lim');
    }

    public function test_the_customer_list_needs_the_permission_and_a_known_figure(): void
    {
        $query = ['metric' => 'fsd_leads', 'from' => '2026-03', 'to' => '2026-03'];

        $this->actingAs($this->cra)->get(route('presentation-board.customers', $query))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('presentation-board.customers', [...$query, 'metric' => 'passwords']))->assertSessionHasErrors('metric');
    }

    public function test_every_rate_shows_what_it_is_out_of(): void
    {
        $this->delivery('1601', '2026-03-05', LogisticsOrder::TEAM_FSD);
        $this->delivery('1602', '2026-03-06', LogisticsOrder::TEAM_FSD);
        PancakeOrder::create(['pancake_order_id' => 'R7', 'ordered_on' => '2026-03-25', 'seller_name' => self::CRD_SELLER, 'phone_key' => '1601', 'status' => 3, 'total_price' => 500]);
        app(PresentationBoard::class)->refresh();

        $this->actingAs($this->supervisor)->get(route('presentation-board.index', ['from' => '2026-03', 'to' => '2026-03']))
            ->assertOk()
            ->assertSee('retain 50.0% (1 of 2)')
            ->assertSee('of 2');
    }

    public function test_the_page_filters_by_month_range_and_rejects_a_backwards_one(): void
    {
        $this->sale('2026-02-03', 1000);
        $this->sale('2026-08-03', 7000);
        app(PresentationBoard::class)->refresh();

        $this->actingAs($this->supervisor)->get(route('presentation-board.index', ['from' => '2026-08', 'to' => '2026-08', 'period' => 'daily']))
            ->assertOk()->assertSee('₱7,000')->assertDontSee('₱8,000')->assertSee('Aug 31');

        $this->get(route('presentation-board.index', ['from' => '2026-08', 'to' => '2026-02']))->assertSessionHasErrors('to');
    }

    public function test_the_backfill_fills_only_empty_pages_and_picks_up_where_it_stopped(): void
    {
        Sleep::fake();
        $empty = $this->delivery('1201', '2025-06-10', LogisticsOrder::TEAM_FSD, page: null);
        $kept = $this->delivery('1202', '2025-06-10', LogisticsOrder::TEAM_FSD, page: '999');
        PancakeOrder::where('pancake_order_id', $empty)->update(['updated_at' => '2026-01-01 00:00:00']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'startDateTime='.CarbonImmutable::parse('2025-08-01', 'Asia/Manila')->getTimestamp())) {
                return Http::response(['success' => false, 'message' => 'Too many requests'], 403);
            }

            return Http::response(['success' => true, 'total_pages' => 1, 'data' => [['display_id' => 'L1'], ['display_id' => 'L2']]]);
        });
        config(['services.pancake.shop_id' => '1', 'services.pancake.key' => 'key', 'services.pancake.access_token' => null]);

        $this->artisan('pancake:backfill-pages', ['--minutes' => 5])->assertFailed();

        $this->assertSame(self::PAGE, PancakeOrder::where('pancake_order_id', $empty)->value('page_id'));
        $this->assertSame('999', PancakeOrder::where('pancake_order_id', $kept)->value('page_id'));
        $this->assertSame('2026-01-01 00:00:00', (string) PancakeOrder::where('pancake_order_id', $empty)->value('updated_at'));
        $this->assertSame(['done' => 2, 'total' => 17, 'complete' => false], app(PancakePageBackfill::class)->progress());
    }

    public function test_the_backfill_skips_a_pages_months_before_it_counts(): void
    {
        PancakePage::create(['name' => 'New Page', 'page_id' => '222', 'access_token' => 'token', 'is_active' => true, 'counts_from' => '2026-10-15']);

        $this->assertSame(18, app(PancakePageBackfill::class)->progress()['total']);
    }

    public function test_the_overall_churn_rate_adds_up_complete_months_and_leaves_out_partial_ones(): void
    {
        $snapshot = $this->snapshot();
        $set = function (string $day, int $due, int $lost) use (&$snapshot) {
            $snapshot['days'][$day] = [...$snapshot['days'][$day], 'all_due' => $due, 'all_lost' => $lost];
        };
        $set('2026-05-10', 1000, 1000);
        $set('2026-08-10', 100, 10);
        $set('2026-09-10', 900, 450);
        $set('2026-11-02', 10, 10);

        $totals = app(PresentationBoard::class)->range($snapshot, '2026-01', '2026-11', 'monthly')['totals'];

        // (10 + 450 + 10) ÷ (100 + 900 + 10), not the average of 10%, 50% and 100%, and without May.
        $this->assertEqualsWithDelta(470 / 1010, $totals['churn_rate'], 0.00001);
        $this->assertSame('Jul–Nov', $totals['churn_label']);
        $this->assertTrue($totals['churn_partial']);
    }

    public function test_churn_has_a_rate_for_fsd_crd_and_overall(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['days']['2026-08-10'] = [...$snapshot['days']['2026-08-10'],
            'fsd_due' => 100, 'fsd_lost' => 10, 'fsd_reactivation' => 80, 'crd_due' => 50, 'crd_lost' => 20, 'crd_reactivation' => 25,
            'all_due' => 140, 'all_lost' => 28, 'all_reactivation' => 100];

        $lists = app(PresentationBoard::class)->range($snapshot, '2026-08', '2026-08', 'monthly')['totals']['churn_lists'];

        $this->assertSame(['name' => 'FSD leads', 'due' => 100, 'lost' => 10, 'reactivation' => 80, 'back' => 10, 'rate' => 0.1], $lists['fsd']);
        $this->assertSame(['name' => 'CRD leads', 'due' => 50, 'lost' => 20, 'reactivation' => 25, 'back' => 5, 'rate' => 0.4], $lists['crd']);
        $this->assertSame(['name' => 'Overall', 'due' => 140, 'lost' => 28, 'reactivation' => 100, 'back' => 12, 'rate' => 0.2], $lists['all']);
    }

    public function test_insights_are_short_and_only_warn_when_it_applies(): void
    {
        $board = app(PresentationBoard::class);
        $snapshot = $this->snapshot();
        $snapshot['days']['2026-08-10'] = [...$snapshot['days']['2026-08-10'], 'gross' => 1000, 'shecom_gross' => 1000, 'orders' => 2, 'all_due' => 100, 'all_lost' => 5, 'all_reactivation' => 88, 'fsd_leads' => 40];
        $august = $board->range($snapshot, '2026-08', '2026-08', 'monthly');
        $lines = $board->insights($august['months'], $august['totals'], $august['products']);

        $this->assertStringNotContainsString('waiting', implode(' ', $lines));
        $this->assertStringNotContainsString("Pancake's", implode(' ', $lines));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(90, mb_strlen($line));
        }

        $snapshot['days']['2026-08-11'] = [...$snapshot['days']['2026-08-11'], 'gross' => 1000, 'fsd_waiting' => 7];
        $mixed = $board->range($snapshot, '2026-08', '2026-08', 'monthly');
        $lines = implode(' ', $board->insights($mixed['months'], $mixed['totals'], $mixed['products']));

        $this->assertStringContainsString("the rest at Pancake's", $lines);
        $this->assertStringContainsString('7 leads still waiting', $lines);
    }
}
