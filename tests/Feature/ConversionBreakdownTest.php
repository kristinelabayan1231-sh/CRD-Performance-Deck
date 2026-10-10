<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\Role;
use App\Models\User;
use App\Services\ConversionBreakdown;
use App\Services\LeadGenerator;
use App\Services\PancakeSync;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ConversionBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private const BROADCAST = 397;

    private const SEGMENTATION = 398;

    private User $owner;

    private int $seq = 0;

    /** Staff rows the fake engagement API returns. */
    private array $engagements = [];

    /** Orders the fake POS API returns. */
    private array $orders = [];

    /** Orders the fake POS API returns for "changed on this day" (updateStatus=updated_at). */
    private array $changed = [];

    /** Orders the fake POS order search returns (search=<order number>), as Pancake has them now. */
    private array $current = [];

    /** Whether the fake engagement API fails. */
    private bool $engagementsDown = false;

    /** Whether the fake POS order search refuses every lookup. */
    private bool $searchDown = false;

    /** Orders the fake Shecom sales API returns; null makes it fail. */
    private ?array $sales = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shecom.key' => 'test-key',
            'services.shecom.sales_key' => 'sales-key',
            'services.pancake.key' => 'pos-key',
            'services.pancake.shop_id' => '1',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 18:00', 'Asia/Manila'));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        PancakePage::create(['name' => 'Trusted Eye Care', 'page_id' => '1001', 'access_token' => 'page-token']);

        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), 'updateStatus=updated_at') => Http::response(['success' => true, 'total_pages' => 1, 'data' => $this->changed]),
                str_contains($request->url(), 'search=') => $this->searchDown
                    ? Http::response(['success' => false, 'message' => 'Unauthorized'], 401)
                    : Http::response(['success' => true, 'total_pages' => 1, 'data' => array_values(array_filter(
                        $this->current, fn (array $order) => str_contains($request->url(), 'search='.($order['display_id'] ?? $order['id']).'&')
                    ))]),
                str_contains($request->url(), 'pos.pages.fm') => Http::response(['success' => true, 'total_pages' => 1, 'data' => $this->orders]),
                str_contains($request->url(), 'customer_engagements') => $this->engagementsDown
                    ? Http::response(['success' => false], 500)
                    : Http::response(['success' => true, 'users_engagements' => $this->engagements]),
                str_contains($request->url(), 'management/sales') => $this->sales === null
                    ? Http::response(['error' => 'down'], 500)
                    : Http::response(['count' => count($this->sales), 'orders' => $this->sales]),
                default => Http::response(['count' => 0, 'stock_outs' => []]),
            };
        });
        app(LeadGenerator::class)->generate(Lead::today());
    }

    private function cra(string $name, ?string $pancake): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    private function lead(User $cra, string $day = '2026-10-01'): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => "o{$this->seq}", 'customer_name' => "Customer {$this->seq}", 'phone_number' => '0917000'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'product_name' => 'Pterygium Drops', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => $day, 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra->id, 'assigned_at' => now(),
        ]);
    }

    private function order(string $seller, array $tags, float $total, int $status = 2): array
    {
        $this->seq++;

        // 2026-10-01 10:00 UTC = 6 PM in Manila.
        return [
            'id' => 360301022569000 + $this->seq, 'display_id' => 9000 + $this->seq, 'inserted_at' => '2026-10-01T10:00:00.000000',
            'status' => $status, 'status_name' => 'confirmed', 'bill_phone_number' => '0999000'.$this->seq, 'bill_full_name' => 'Buyer',
            'total_price' => $total, 'account_name' => 'Trusted Eye Care', 'tags' => $tags,
            'assigning_seller' => ['id' => 'seller-'.md5($seller), 'name' => $seller],
        ];
    }

    public function test_numbers_follow_the_report_rules(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        $other = $this->cra('Regina', 'CRD Rej Vergara');

        $this->lead($lhea);
        $this->lead($lhea);
        $this->lead($lhea);
        $this->lead($lhea);
        $this->lead($lhea, '2026-09-30');
        $this->lead($other);

        $this->engagements = [
            ['user_id' => 'u-lhea', 'name' => 'CRD  LHEI', 'total_engagement' => 20],
            ['user_id' => 'u-rej', 'name' => 'CRD Rej Vergara', 'total_engagement' => 9],
        ];
        $this->orders = [
            // Broadcast: two orders; Pancake sends the seller name with its own spacing and casing.
            $this->order('CRD Lhei', [self::BROADCAST, 17], 1000),
            $this->order('CRD  LHEI', [self::BROADCAST], 799),
            // Segmentation: one order, plus one with both tags (counts as segmentation).
            $this->order('CRD Lhei', [self::SEGMENTATION, 431], 1500),
            $this->order('CRD Lhei', [self::BROADCAST, self::SEGMENTATION], 500),
            // Canceled: in gross sales (every status), not in the order counts.
            $this->order('CRD Lhei', [self::SEGMENTATION], 819, status: 6),
            // Not counted at all: untagged, and another CRA's tagged order.
            $this->order('CRD Lhei', [17], 999),
            $this->order('CRD Rej Vergara', [self::BROADCAST], 2000),
        ];

        app(PancakeSync::class)->sync(Lead::today());

        $day = app(ConversionBreakdown::class)->days(collect([$lhea]), Lead::today(), Lead::today())[$lhea->id]['2026-10-01'];

        $this->assertSame(2, $day['bc_orders']);
        $this->assertSame(2, $day['sc_orders']);
        $this->assertSame(20, $day['engagements']);
        $this->assertSame(4, $day['leads']);
        $this->assertEqualsWithDelta(1799.0, $day['bc_gross'], 0.001);
        $this->assertEqualsWithDelta(2819.0, $day['sc_gross'], 0.001);
        $this->assertEqualsWithDelta(4618.0, $day['gross'], 0.001);
        $this->assertEqualsWithDelta(2 / 20, $day['bc_rate'], 1e-9);
        $this->assertEqualsWithDelta(2 / 4, $day['sc_rate'], 1e-9);
        $this->assertEqualsWithDelta(4 / 24, $day['total_rate'], 1e-9);
    }

    public function test_with_a_working_date_leads_are_the_paired_lead_day_and_results_stay_on_the_real_day(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        // Saved on Oct 1 with start Sept 2: the tracker is on Sept 1 today, a 30-day gap.
        WorkingDate::startTomorrow(CarbonImmutable::parse('2026-09-02'));
        $this->lead($lhea, '2026-09-01');
        $this->lead($lhea, '2026-09-01');
        $this->lead($lhea, '2026-09-01');
        $this->lead($lhea, '2026-10-01');

        $this->assertTrue(Lead::today()->isSameDay('2026-09-01'));
        $day = app(ConversionBreakdown::class)->days(collect([$lhea]), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-01'))[$lhea->id]['2026-10-01'];
        $this->assertSame(3, $day['leads']);

        // Conversion opens on the real date; the working-date banner is only on the tracker.
        $this->actingAs($this->owner)->get(route('conversion.index'))->assertOk()->assertSee('Oct 1')->assertDontSee('Working date:');

        // The dashboard shows results by the real date (October to date: Oct 1), labelled with the lead days they come from.
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('leads from Sep 1')
            ->assertSee('lead day Tue, Sep 1');

        // One month or range moves every section: results on Sep 29–30, leads on the paired lead days Aug 30–31.
        $this->actingAs($this->owner)->get(route('dashboard', ['from' => '2026-09-29', 'to' => '2026-09-30']))->assertOk()
            ->assertSee('leads from Aug 30–31')
            ->assertSee('lead days Aug 30–31');
        // September picked: the whole month.
        $this->actingAs($this->owner)->get(route('dashboard', ['month' => '2026-09']))->assertOk()
            ->assertSee('CRD monthly goal (Gross Sales) · September 2026');
        // No future months or days.
        $this->actingAs($this->owner)->get(route('dashboard', ['month' => '2026-11']))->assertSessionHasErrors('month');
        $this->actingAs($this->owner)->get(route('dashboard', ['from' => '2026-10-02']))->assertSessionHasErrors('from');
    }

    public function test_a_cras_gross_sales_open_the_orders_that_add_up_to_it(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        $regina = $this->cra('Regina', 'CRD Rej Vergara');
        $make = fn (string $id, string $seller, ?string $type, float $total, int $status = 2, string $at = '2026-10-01 02:00:00') => PancakeOrder::create([
            'pancake_order_id' => $id, 'ordered_on' => '2026-10-01', 'ordered_at' => $at, 'seller_name' => $seller, 'customer_name' => "Buyer {$id}",
            'page_name' => 'Trusted Eye Care', 'status' => $status, 'total_price' => $total, 'conversion_type' => $type,
        ]);
        // S1 was created first (9:15 AM Manila), B1 later (2:30 PM): listed in that order.
        $make('B1', 'CRD LHEI', PancakeOrder::BROADCAST, 1000, at: '2026-10-01 06:30:00');
        $make('S1', 'CRD LHEI', PancakeOrder::SEGMENTATION, 2000, at: '2026-10-01 01:15:00');
        $make('S2', 'CRD LHEI', PancakeOrder::SEGMENTATION, 5000, status: 6);       // canceled: in gross sales too (10 AM)
        $make('U1', 'CRD LHEI', null, 7000);                                         // untagged
        $make('R1', 'CRD REJ VERGARA', PancakeOrder::SEGMENTATION, 3000);            // another CRA

        $url = route('conversion.cra-orders', ['cra' => $lhea, 'from' => '2026-10-01', 'to' => '2026-10-01']);
        $this->actingAs($this->owner)->get(route('conversion.index'))->assertOk()
            ->assertSee('data-cra-orders="'.e($url).'"', false)
            ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('cra.id', $lhea->id)['now']['gross'] == 8000);

        $this->actingAs($this->owner)->get($url)->assertOk()
            // Adds up to the ₱8,000 gross sales shown for Lhea, the canceled order included.
            ->assertSeeInOrder(['Lhea', '3 orders', 'Gross BC', '₱1,000.00', 'Gross SC', '₱7,000.00', 'Gross sales', '₱8,000.00'])
            ->assertSeeInOrder(['Order ID', 'Customer name', 'Page name', 'Tagging', 'Status', 'Amount'])
            ->assertSeeInOrder(['Oct 1, 9:15 AM', 'S1', 'Buyer S1', 'Trusted Eye Care', 'CRD - SEGMENTATION', 'Shipped', '₱2,000.00',
                'Oct 1, 10:00 AM', 'S2', 'CRD - SEGMENTATION', 'Canceled', '₱5,000.00',
                'Oct 1, 2:30 PM', 'B1', 'Buyer B1', 'CRD - BROADCAST', 'Shipped', '₱1,000.00'])
            ->assertDontSee('U1')->assertDontSee('R1');

        // A CRA can open only their own orders.
        $this->actingAs($regina)->get($url)->assertForbidden();
        $this->actingAs($lhea)->get($url)->assertOk();
    }

    public function test_sync_saves_the_order_tags(): void
    {
        $this->orders = [$this->order('CRD Lhei', [self::BROADCAST, 17], 1000)];

        app(PancakeSync::class)->sync(Lead::today());

        $order = PancakeOrder::sole();
        $this->assertSame([self::BROADCAST, 17], $order->tags);
        $this->assertSame(PancakeOrder::BROADCAST, $order->conversion_type);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pos.pages.fm') && str_contains(urldecode($request->url()), 'fields[]=tags'));
    }

    public function test_gross_uses_shecom_sales_which_leave_out_the_child_row(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        $this->orders = [
            // Pancake's 1999 includes a 1000 child (TSD) row; Shecom's 999 doesn't.
            $withChild = $this->order('CRD Lhei', [self::SEGMENTATION], 1999),
            // Not in Shecom yet: Pancake's total stands.
            $this->order('CRD Lhei', [self::BROADCAST], 799),
        ];
        $this->sales = [['order_id' => (string) $withChild['display_id'], 'date' => '2026-10-01', 'sales' => '999', 'assigned_seller' => 'CRD Lhei']];

        app(PancakeSync::class)->sync(Lead::today());

        $day = app(ConversionBreakdown::class)->days(collect([$lhea]), Lead::today(), Lead::today())[$lhea->id]['2026-10-01'];
        $this->assertEqualsWithDelta(999.0, $day['sc_gross'], 0.001);
        $this->assertEqualsWithDelta(799.0, $day['bc_gross'], 0.001);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'management/sales')
            && $request['date_from'] === '2026-10-01' && $request['date_to'] === '2026-10-01'
            && $request->hasHeader('Authorization', 'Bearer sales-key'));
    }

    public function test_pancake_totals_stay_when_shecom_sales_are_down(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        $this->orders = [$this->order('CRD Lhei', [self::SEGMENTATION], 1999)];
        $this->sales = null;

        app(PancakeSync::class)->sync(Lead::today());

        $day = app(ConversionBreakdown::class)->days(collect([$lhea]), Lead::today(), Lead::today())[$lhea->id]['2026-10-01'];
        $this->assertSame(1, $day['sc_orders']);
        $this->assertEqualsWithDelta(1999.0, $day['sc_gross'], 0.001);
    }

    public function test_a_tag_added_days_later_is_picked_up_on_the_next_sync(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        $this->orders = [$order = $this->order('CRD Lhei', [17], 999)];
        app(PancakeSync::class)->sync(Lead::today());
        $this->assertSame(0, app(ConversionBreakdown::class)->days(collect([$lhea]), Lead::today(), Lead::today())[$lhea->id]['2026-10-01']['orders']);

        // Two days later the CRA tags the Oct 1 order CRD - SEGMENTATION; Pancake lists it as changed that day.
        $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00', 'Asia/Manila'));
        $this->orders = [];
        $this->changed = [['id' => $order['id'], 'display_id' => $order['display_id'], 'tags' => [17, self::SEGMENTATION], 'status' => 2, 'status_name' => 'confirmed']];
        app(PancakeSync::class)->sync(CarbonImmutable::parse('2026-10-03'));

        $day = app(ConversionBreakdown::class)->days(collect([$lhea]), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-01'))[$lhea->id]['2026-10-01'];
        $this->assertSame(1, $day['sc_orders']);
        $this->assertEqualsWithDelta(999.0, $day['sc_gross'], 0.001);
    }

    public function test_header_lists_order_issues_and_a_cra_sees_only_their_own(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        $this->cra('Regina', 'CRD Rej Vergara');
        $this->cra('Newbie', null);
        $this->orders = [
            $this->order('CRD Lhei', [17], 999),                                      // no CRD tag
            $this->order('CRD Lhei', [self::BROADCAST, self::SEGMENTATION], 500),     // both CRD tags
            $this->order('CRD Lhei', [self::SEGMENTATION], 800),                      // fine
            $this->order('CRD Lhei', [17], 700, status: 6),                           // canceled: not an issue
            $this->order('CRD Rej Vergara', [], 1200),                                // Regina: no CRD tag
        ];
        app(PancakeSync::class)->sync(Lead::today());

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSeeText('4 issues')
            ->assertSeeText('2 no crd tag, 1 both crd tags, 1 no pancake account')
            ->assertSee('id="issues-dialog"', false)
            ->assertSeeInOrder(['Lhea', 'No CRD tag', 'Both CRD tags', 'Newbie', 'No Pancake account', 'Regina', 'No CRD tag']);

        $this->actingAs($lhea)->get(route('conversion.index'))->assertOk()
            ->assertSeeText('2 issues')->assertDontSee('Regina');
    }

    public function test_a_tag_fixed_in_pancake_clears_from_the_header_and_the_dashboard_reloads(): void
    {
        $this->cra('Lhea', 'CRD Lhei');
        $this->orders = [$order = $this->order('CRD Lhei', [17], 999)];
        app(PancakeSync::class)->sync(Lead::today());
        $this->actingAs($this->owner)->get(route('dashboard'))->assertSeeText('1 no crd tag');
        $before = $this->getJson(route('dashboard.live'))->assertOk()->json('version');

        // The CRA tags it in Pancake, but it isn't in Pancake's changed-orders list: the flagged order is looked up itself.
        $this->travel(11)->minutes();
        $this->current = [['id' => $order['id'], 'display_id' => $order['display_id'], 'tags' => [17, self::SEGMENTATION], 'status' => 2, 'status_name' => 'confirmed']];
        app(PancakeSync::class)->sync(Lead::today());

        $this->assertSame(PancakeOrder::SEGMENTATION, PancakeOrder::sole()->conversion_type);
        $this->actingAs($this->owner)->get(route('dashboard'))->assertSeeText('No order issues');
        $this->assertNotSame($before, $this->getJson(route('dashboard.live'))->json('version'));
    }

    public function test_the_quick_tag_refresh_clears_a_fixed_order_without_a_full_sync(): void
    {
        $this->cra('Lhea', 'CRD Lhei');
        $this->orders = [$order = $this->order('CRD Lhei', [17], 999)];
        app(PancakeSync::class)->sync(Lead::today());

        $this->current = [['id' => $order['id'], 'display_id' => $order['display_id'], 'tags' => [self::BROADCAST], 'status' => 2, 'status_name' => 'confirmed']];
        $this->artisan('pancake:sync --tags')->assertSuccessful();

        $this->assertSame(PancakeOrder::BROADCAST, PancakeOrder::sole()->conversion_type);
    }

    public function test_opening_a_page_rechecks_the_flagged_orders_without_the_scheduler(): void
    {
        $this->cra('Lhea', 'CRD Lhei');
        $this->orders = [$order = $this->order('CRD Lhei', [17], 999)];
        app(PancakeSync::class)->sync(Lead::today());

        // Tagged in Pancake; no sync runs. The page that shows the issue looks it up again after it is sent.
        $this->current = [['id' => $order['id'], 'display_id' => $order['display_id'], 'tags' => [self::BROADCAST], 'status' => 2, 'status_name' => 'confirmed']];
        $this->actingAs($this->owner)->get(route('dashboard'))->assertSeeText('1 no crd tag');

        $this->assertSame(PancakeOrder::BROADCAST, PancakeOrder::sole()->conversion_type);
        $this->actingAs($this->owner)->get(route('dashboard'))->assertSeeText('No order issues');
    }

    public function test_check_again_rechecks_the_flagged_orders_now(): void
    {
        $this->cra('Lhea', 'CRD Lhei');
        $this->orders = [$order = $this->order('CRD Lhei', [17], 999)];
        app(PancakeSync::class)->sync(Lead::today());
        $this->actingAs($this->owner)->get(route('dashboard'))->assertSee(route('order-issues.recheck'));

        $this->current = [['id' => $order['id'], 'display_id' => $order['display_id'], 'tags' => [self::SEGMENTATION], 'status' => 2, 'status_name' => 'confirmed']];
        $this->actingAs($this->owner)->from(route('dashboard'))->post(route('order-issues.recheck'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Re-checked 1 flagged order in Pancake: 1 updated.');

        $this->assertSame(PancakeOrder::SEGMENTATION, PancakeOrder::sole()->conversion_type);
    }

    public function test_check_again_finds_orders_in_the_api_key_shape(): void
    {
        $this->cra('Lhea', 'CRD Lhei');
        $this->orders = [$order = $this->order('CRD Lhei', [17], 999)];
        app(PancakeSync::class)->sync(Lead::today());

        // With the API key Pancake sends no display_id: id is the order number, and tags come as {id, name}.
        $this->current = [['id' => $order['display_id'], 'tags' => [['id' => self::BROADCAST, 'name' => 'CRD - BROADCAST']], 'status' => 2, 'status_name' => 'confirmed']];
        $this->actingAs($this->owner)->from(route('dashboard'))->post(route('order-issues.recheck'))
            ->assertSessionHas('status', 'Re-checked 1 flagged order in Pancake: 1 updated.');

        $this->assertSame(PancakeOrder::BROADCAST, PancakeOrder::sole()->conversion_type);
    }

    public function test_check_again_says_when_pancake_refuses_the_lookup(): void
    {
        $this->cra('Lhea', 'CRD Lhei');
        $this->orders = [$this->order('CRD Lhei', [17], 999)];
        app(PancakeSync::class)->sync(Lead::today());

        $this->searchDown = true;
        Log::spy();
        $this->actingAs($this->owner)->from(route('dashboard'))->post(route('order-issues.recheck'))
            ->assertSessionHas('status', fn (string $status) => str_starts_with($status, "Pancake didn't answer the lookup for any of the 1 flagged orders"));

        Log::shouldHaveReceived('warning')->with('Pancake order lookup failed', \Mockery::on(fn (array $context) => str_starts_with($context['error'], 'HTTP 401')));
        $this->assertNull(PancakeOrder::sole()->conversion_type);
    }

    public function test_orders_still_sync_when_a_page_engagements_fail(): void
    {
        $this->engagements = [['user_id' => 'u1', 'name' => 'CRD Lhei', 'total_engagement' => 40]];
        app(PancakeSync::class)->sync(Lead::today());

        $this->engagementsDown = true;
        $this->orders = [$this->order('CRD Lhei', [self::SEGMENTATION], 800)];
        $result = app(PancakeSync::class)->sync(Lead::today());

        $this->assertNotNull($result['engagement_error']);
        $this->assertSame(1, PancakeOrder::count());
        $this->assertSame(40, PancakeEngagement::sole()->engagements);
        $this->assertFalse(PancakeSync::isStale(Lead::today()));
    }

    public function test_header_says_so_when_there_are_no_issues(): void
    {
        $this->cra('Lhea', 'CRD Lhei');
        $this->orders = [$this->order('CRD Lhei', [self::SEGMENTATION], 800)];
        app(PancakeSync::class)->sync(Lead::today());

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSeeText('No order issues')->assertDontSee('id="issues-dialog"', false);
    }

    public function test_rates_are_blank_without_engagements_or_leads(): void
    {
        $day = ConversionBreakdown::sum([]);

        $this->assertSame(0, $day['orders']);
        $this->assertNull($day['bc_rate']);
        $this->assertNull($day['sc_rate']);
        $this->assertNull($day['total_rate']);
    }

    public function test_period_totals_recalculate_rates_from_counts(): void
    {
        $week = ConversionBreakdown::sum([
            ['bc_orders' => 3, 'sc_orders' => 7, 'engagements' => 60, 'leads' => 70, 'bc_gross' => 2997.0, 'sc_gross' => 7000.0],
            ['bc_orders' => 1, 'sc_orders' => 9, 'engagements' => 40, 'leads' => 70, 'bc_gross' => 999.0, 'sc_gross' => 9500.0],
        ]);

        $this->assertEqualsWithDelta(4 / 100, $week['bc_rate'], 1e-9);
        $this->assertEqualsWithDelta(16 / 140, $week['sc_rate'], 1e-9);
        $this->assertEqualsWithDelta(20 / 240, $week['total_rate'], 1e-9);
        $this->assertEqualsWithDelta(20496.0, $week['gross'], 0.001);
    }

    public function test_supervisor_sees_every_cra_by_day_week_and_month(): void
    {
        $this->cra('Lhea', 'CRD Lhei');
        $regina = $this->cra('Regina', 'CRD Rej Vergara');

        $this->actingAs($this->owner)->get(route('conversion.index'))
            ->assertOk()
            ->assertSee('Conversion Breakdown per CRA · Thu, Oct 1')
            ->assertSee('Lhea')
            ->assertSee('Regina');

        $this->actingAs($this->owner)->get(route('conversion.index', ['view' => 'week']))
            ->assertOk()
            ->assertSee('1st week · Oct 1–7');

        $this->actingAs($this->owner)->get(route('conversion.index', ['view' => 'month', 'cras' => [$regina->id]]))
            ->assertOk()
            ->assertSee('October 2026')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('cra.id')->all() === [$regina->id]);
    }

    public function test_cra_sees_only_their_own_numbers(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        $this->cra('Regina', 'CRD Rej Vergara');

        $this->actingAs($lhea)->get(route('conversion.index', ['cras' => [$lhea->id + 1]]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('cra.id')->all() === [$lhea->id]);

        $this->actingAs($lhea)->post(route('conversion.sync'), ['date' => '2026-10-01'])->assertForbidden();
    }

    public function test_supervisor_syncs_a_day_from_pancake(): void
    {
        $this->orders = [$this->order('CRD Lhei', [self::SEGMENTATION], 1000)];

        $this->actingAs($this->owner)->post(route('conversion.sync'), ['date' => '2026-10-01'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('pancake_orders', ['seller_name' => 'CRD LHEI', 'conversion_type' => PancakeOrder::SEGMENTATION]);
    }

    public function test_it_is_its_own_module_in_the_sidebar(): void
    {
        $this->actingAs($this->owner)->get(route('conversion.index'))
            ->assertOk()
            ->assertSee(route('conversion.index'))
            ->assertDontSee('Segmentation Productivity sections');
    }

    public function test_users_without_the_permission_cannot_open_it(): void
    {
        $user = User::create(['email' => 'plain@example.com', 'role_id' => Role::defaultUser()->id, 'is_active' => true]);

        $this->actingAs($user)->get(route('conversion.index'))->assertForbidden();
    }
}
