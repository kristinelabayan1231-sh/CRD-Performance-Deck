<?php

namespace Tests\Feature;

use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ConversionBreakdown;
use App\Services\CustomerChurn;
use App\Services\DeliveredRetention;
use App\Services\LeadGenerator;
use App\Services\PancakePageBackfill;
use App\Services\PresentationBoard;
use App\Services\SegmentationStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
