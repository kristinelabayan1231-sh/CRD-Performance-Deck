<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\Role;
use App\Models\User;
use App\Services\ConversionBreakdown;
use App\Services\LeadGenerator;
use App\Services\PancakeSync;
use App\Services\SalesGoalProgress;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shecom.key' => 'test-key',
            'services.pancake.key' => 'pos-key',
            'services.pancake.shop_id' => '1',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 18:00', 'Asia/Manila'));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        PancakePage::create(['name' => 'Trusted Eye Care', 'page_id' => '1001', 'access_token' => 'page-token']);

        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), 'pos.pages.fm') => Http::response(['success' => true, 'total_pages' => 1, 'data' => $this->orders]),
                str_contains($request->url(), 'customer_engagements') => Http::response(['success' => true, 'users_engagements' => $this->engagements]),
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
            // Not counted: canceled, untagged, and another CRA's tagged order.
            $this->order('CRD Lhei', [self::SEGMENTATION], 819, status: 6),
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
        $this->assertEqualsWithDelta(2000.0, $day['sc_gross'], 0.001);
        $this->assertEqualsWithDelta(3799.0, $day['gross'], 0.001);
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

        // The dashboard shows results by the real date, labelled with the lead days they come from.
        $periods = app(ConversionBreakdown::class)->periods(collect([$lhea]), CarbonImmutable::parse('2026-10-01'));
        $this->assertSame('Thu, Oct 1', $periods['today']['label']);
        $this->assertSame('Sep 1', $periods['today']['leads_from']);
        // Weeks are whole 7-day buckets from the 1st, whatever the day.
        $this->assertSame('Week 1 · Oct 1–7', $periods['week']['label']);
        $goals = app(SalesGoalProgress::class)->for(collect([$lhea]), CarbonImmutable::parse('2026-10-01'), 'week');
        $this->assertSame(['Week 1 · Oct 1–7', 7], [$goals['range']['label'], $goals['range']['days']]);
        $this->actingAs($this->owner)->get(route('dashboard'))->assertSee('leads from Sep 1');

        // One date picker moves every section: results on Sep 30, leads on its paired lead day Aug 31.
        $this->actingAs($this->owner)->get(route('dashboard', ['date' => '2026-09-30']))->assertOk()
            ->assertSee('Total conv % per CRA · Wed, Sep 30')
            ->assertSee('Goal per CRA · Wed, Sep 30')
            ->assertSee('lead day Mon, Aug 31');
        $this->actingAs($this->owner)->get(route('dashboard', ['date' => '2026-10-02']))->assertSessionHasErrors('date');
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
