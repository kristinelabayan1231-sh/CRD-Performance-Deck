<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PancakePage;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\PancakeSync;
use App\Services\SegmentationProductivity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SegmentationProductivityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $seq = 0;

    /** Staff rows the fake engagement API returns. */
    private array $engagements = [];

    /** Orders the fake POS API returns. */
    private array $orders = [];

    /** When set, the fake POS API refuses every request with this body. */
    private ?array $posRefusal = null;

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
                str_contains($request->url(), 'pos.pages.fm') => $this->posRefusal
                    ? Http::response($this->posRefusal, 403)
                    : Http::response(['success' => true, 'total_pages' => 1, 'total_entries' => count($this->orders), 'data' => $this->orders]),
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

    private function lead(User $cra, string $phone, array $extra = []): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => "o{$this->seq}", 'customer_name' => "Customer {$this->seq}", 'phone_number' => $phone,
            'product_name' => 'Pterygium Drops', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => '2026-10-01', 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra->id, 'assigned_at' => now(),
            ...$extra,
        ]);
    }

    private function order(string $phone, string $seller, int $status = 3, float $total = 800): array
    {
        $this->seq++;

        // 2026-10-01 10:00 UTC = 6 PM in Manila.
        return [
            'id' => 360301022569000 + $this->seq, 'display_id' => $this->seq === 1 ? 1374947 : 9000 + $this->seq, 'inserted_at' => '2026-10-01T10:00:00.000000', 'status' => $status, 'status_name' => 'delivered',
            'bill_phone_number' => $phone, 'bill_full_name' => 'Buyer', 'total_price' => $total, 'account_name' => 'Trusted Eye Care',
            'assigning_seller' => ['id' => 'seller-'.md5($seller), 'name' => $seller],
        ];
    }

    public function test_numbers_follow_the_report_rules(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        $other = $this->cra('Regina', 'CRD Rej Vergara');

        $this->lead($lhea, '09170000001', ['repeat_purchase' => 'yes', 'contact_date' => '2026-10-01']);
        $this->lead($lhea, '09170000002', ['contact_date' => '2026-10-01']);
        $this->lead($lhea, '09170000003');
        $this->lead($other, '09170000004');
        $this->lead($lhea, '09170000005', ['contact_date' => '2026-09-30']);

        // Pancake sends names with stray spaces and its own casing.
        $this->engagements = [
            ['user_id' => 'u-lhea', 'name' => 'CRD  LHEI', 'total_engagement' => 5],
            ['user_id' => 'u-x', 'name' => 'Someone Else', 'total_engagement' => 40],
        ];
        $this->orders = [
            // Repeat Purchase = Yes lead buys, even through another seller: assigned lead conversion.
            $this->order('+63 917 000 0001', 'Someone Else'),
            // Lhea's own orders for a customer outside every leads list, twice: one Pancake conversion.
            $this->order('09998887777', 'CRD  Lhei'),
            $this->order('639998887777', 'CRD Lhei'),
            // Another CRA's lead and Lhea's lead without Repeat Purchase = Yes: neither conversion.
            $this->order('09170000004', 'CRD Lhei'),
            $this->order('09170000002', 'CRD Lhei'),
            // Canceled orders don't count.
            $this->order('09111111111', 'CRD Lhei', status: 6),
        ];

        app(PancakeSync::class)->sync(Lead::today());

        $day = app(SegmentationProductivity::class)->days(collect([$lhea]), Lead::today(), Lead::today())[$lhea->id]['2026-10-01'];

        $this->assertSame(4, $day['assigned']);
        $this->assertSame(2, $day['calls']);
        $this->assertSame(5, $day['chat']);
        $this->assertSame(7, $day['answered']);
        $this->assertSame(1, $day['alc']);
        $this->assertSame(1, $day['pc']);
        $this->assertSame(2, $day['confirmed']);
        $this->assertEqualsWithDelta(2 / 7, $day['conversion_rate'], 1e-9);
        $this->assertEqualsWithDelta(7 / 4, $day['pickup_rate'], 1e-9);
        // Sales: the Repeat Purchase customer's order + Lhea's two Pancake-conversion orders, 800 each; canceled and unmatched orders add nothing.
        $this->assertEqualsWithDelta(2400.0, $day['gross'], 0.001);
        $this->assertEqualsWithDelta(1200.0, $day['aov'], 0.001);
    }

    public function test_rates_are_blank_without_assigned_or_answered(): void
    {
        $rose = $this->cra('Rose-An', 'CRD Rose-An Orbaneja');

        $day = app(SegmentationProductivity::class)->days(collect([$rose]), Lead::today(), Lead::today())[$rose->id]['2026-10-01'];

        $this->assertSame(0, $day['confirmed']);
        $this->assertNull($day['conversion_rate']);
        $this->assertNull($day['pickup_rate']);
    }

    public function test_week_totals_recalculate_rates_from_counts(): void
    {
        $week = SegmentationProductivity::sum([
            ['assigned' => 70, 'calls' => 10, 'chat' => 51, 'alc' => 2, 'pc' => 4, 'gross' => 10497.0],
            ['assigned' => 70, 'calls' => 20, 'chat' => 40, 'alc' => 10, 'pc' => 9, 'gross' => 21782.0],
        ]);

        $this->assertSame(140, $week['assigned']);
        $this->assertSame(121, $week['answered']);
        $this->assertSame(25, $week['confirmed']);
        $this->assertEqualsWithDelta(25 / 121, $week['conversion_rate'], 1e-9);
        $this->assertEqualsWithDelta(121 / 140, $week['pickup_rate'], 1e-9);
        $this->assertEqualsWithDelta(32279.0, $week['gross'], 0.001);
        $this->assertEqualsWithDelta(32279 / 25, $week['aov'], 1e-9);
    }

    public function test_supervisor_compares_every_cra_and_can_focus_one(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        $regina = $this->cra('Regina', 'CRD Rej Vergara');
        $this->lead($lhea, '09170000001');

        $this->actingAs($this->owner)->get(route('productivity.index'))
            ->assertOk()
            ->assertSee('Segmentation Productivity Report')
            ->assertSee('Assigned base 70 per CRA per day')
            ->assertSee('Sales per CRA')
            ->assertSee('Lhea')
            ->assertSee('Regina');

        $this->actingAs($this->owner)->get(route('productivity.index', ['cras' => [$regina->id], 'view' => 'week']))
            ->assertOk()
            ->assertSee('1st week · Oct 1–7')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('cra.id')->all() === [$regina->id]);
    }

    public function test_sales_chart_names_the_top_seller_by_day_week_and_month(): void
    {
        $this->cra('Lhea', 'CRD Lhei');
        $this->cra('Regina', 'CRD Rej Vergara');
        $this->orders = [
            $this->order('09990000001', 'CRD Lhei', total: 1500),
            $this->order('09990000002', 'CRD Rej Vergara', total: 900),
            $this->order('09990000003', 'CRD Rej Vergara', total: 900),
        ];
        app(PancakeSync::class)->sync(Lead::today());

        foreach (['day', 'week', 'month'] as $by) {
            $this->actingAs($this->owner)->get(route('productivity.index', ['sales' => $by]))
                ->assertOk()
                ->assertViewHas('sales', function ($sales) {
                    $selected = $sales->firstWhere('selected', true);

                    return $selected['top']['name'] === 'Regina' && $selected['top']['value'] === 1800.0 && $selected['total'] === 3300.0;
                });
        }
    }

    public function test_supervisor_syncs_a_day_from_pancake(): void
    {
        $this->orders = [$this->order('09998887777', 'CRD Lhei')];
        $this->engagements = [['user_id' => 'u-lhea', 'name' => 'CRD Lhei', 'total_engagement' => 12]];

        $this->actingAs($this->owner)->post(route('productivity.sync'), ['date' => '2026-10-01'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('pancake_orders', ['phone_key' => '9998887777', 'seller_name' => 'CRD LHEI', 'ordered_on' => '2026-10-01']);
        // Stored under the shop's order number, not Pancake's internal id.
        $this->assertDatabaseHas('pancake_orders', ['pancake_order_id' => '1374947']);
        $this->assertDatabaseHas('pancake_engagements', ['staff_name' => 'CRD LHEI', 'engagements' => 12]);
        $this->assertNotNull(PancakeSync::lastSync(Lead::today()));
    }

    public function test_orders_use_the_access_token_when_set_and_ask_only_for_needed_fields(): void
    {
        config(['services.pancake.access_token' => 'user-token']);

        app(PancakeSync::class)->sync(Lead::today());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pos.pages.fm')
            && str_contains($request->url(), 'access_token=user-token')
            && ! str_contains($request->url(), 'api_key=')
            && str_contains(urldecode($request->url()), 'fields[]=total_price'));
    }

    public function test_connection_check_reports_a_refused_pancake_credential(): void
    {
        $this->artisan('connections:check')->assertSuccessful();

        $this->posRefusal = ['success' => false, 'message' => 'api_key is invalid', 'error_code' => 105];

        $this->artisan('connections:check')
            ->expectsOutputToContain('api_key is invalid')
            ->assertFailed();
    }

    public function test_cra_sees_only_their_own_numbers(): void
    {
        $lhea = $this->cra('Lhea', 'CRD Lhei');
        $this->cra('Regina', 'CRD Rej Vergara');

        $this->actingAs($lhea)->get(route('productivity.index', ['cras' => [$lhea->id + 1]]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('cra.id')->all() === [$lhea->id]);

        $this->actingAs($lhea)->post(route('productivity.sync'), ['date' => '2026-10-01'])->assertForbidden();
    }

    public function test_users_without_the_permission_cannot_open_it(): void
    {
        $user = User::create(['email' => 'plain@example.com', 'role_id' => Role::defaultUser()->id, 'is_active' => true]);

        $this->actingAs($user)->get(route('productivity.index'))->assertForbidden();
    }

    public function test_manager_sets_a_cras_pancake_account(): void
    {
        $lhea = $this->cra('Lhea', null);

        $this->actingAs($this->owner)
            ->patchJson(route('user-access.pancake-account', $lhea), ['pancake_name' => '  CRD   Lhei '])
            ->assertOk();

        $this->assertSame('CRD Lhei', $lhea->fresh()->pancake_name);
    }
}
