<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\LogisticsRetention;
use App\Services\ShecomClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LogisticsRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shecom.key' => 'test-key']);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 18:00', 'Asia/Manila'));
        $this->withoutDefer();

        $fb = fn (string $day, bool $retained) => ['order_id' => uniqid(), 'phone_number' => '9170000000', 'product' => 'Pterygium', 'delivered_date' => $day, 'retained_by_crd' => $retained];
        $crd = fn (string $day, bool $again) => ['order_id' => uniqid(), 'phone_number' => '9170000001', 'product' => 'Sinuxyl', 'delivered_date' => $day, 'ordered_again_via_crd' => $again];

        Http::fake(['*retention-stockout*' => Http::response([
            'count' => 1,
            'stock_outs' => [['order_id' => '1290015', 'customer_name' => 'Gina', 'phone_number' => '9064085393', 'product_name' => 'Pterygium Drops',
                'qty' => 1, 'delivered_date' => '2026-09-05', 'consumption_days_per_unit' => 15, 'estimated_out_of_stock_date' => '2026-09-19']],
            'retention_summary' => ['fb_delivered_total' => 5, 'fb_retained_by_crd' => 2, 'fb_retention_rate_pct' => 40.0,
                'crd_delivered_total' => 3, 'crd_ordered_again' => 1, 'crd_repeat_rate_pct' => 33.33],
            'retention_detail' => [
                $fb('2026-10-02', true), $fb('2026-10-05', false), $fb('2026-10-06', false),
                $fb('2026-09-20', true), $fb('2026-09-21', false),
            ],
            'repeat_detail' => [$crd('2026-10-03', true), $crd('2026-10-04', false), $crd('2026-08-30', false)],
        ])]);
    }

    public function test_lead_sync_keeps_the_stock_outs_and_saves_the_retention_counts(): void
    {
        $rows = app(ShecomClient::class)->retentionStockouts();

        $this->assertSame(['1290015'], array_column($rows, 'order_id'));
        $this->assertNotNull(LogisticsRetention::fetchedAt());
        $this->assertSame(['fb_delivered' => 1, 'fb_retained' => 1, 'crd_delivered' => 0, 'crd_again' => 0], LogisticsRetention::cached()['days']['2026-10-02']);
    }

    public function test_tiles_count_by_delivered_date_for_week_month_and_all_time(): void
    {
        app(LogisticsRetention::class)->refresh();

        $periods = LogisticsRetention::periods(CarbonImmutable::parse('2026-10-06'));

        // Week 1 (Oct 1–7) and October so far: 3 FB delivered, 1 retained; 2 CRD delivered, 1 ordered again.
        foreach (['week', 'month'] as $key) {
            $this->assertSame([3, 1, 2, 1], [$periods[$key]['fb_delivered'], $periods[$key]['fb_retained'], $periods[$key]['crd_delivered'], $periods[$key]['crd_again']]);
            $this->assertEqualsWithDelta(1 / 3, $periods[$key]['retention_rate'], 1e-9);
            $this->assertEqualsWithDelta(1 / 2, $periods[$key]['repeat_rate'], 1e-9);
        }

        $this->assertSame([5, 2, 3, 1], [$periods['all']['fb_delivered'], $periods['all']['fb_retained'], $periods['all']['crd_delivered'], $periods['all']['crd_again']]);
        $this->assertSame('Since Aug 30, 2026', $periods['all']['label']);
    }

    public function test_dashboard_shows_the_tiles_labelled_live_from_logistics(): void
    {
        $owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);

        // The dashboard's shared period switch: Month shows October so far.
        $this->actingAs($owner)->get(route('dashboard', ['period' => 'month']))
            ->assertOk()
            ->assertSee('Live from Logistics')
            ->assertSeeInOrder(['FB delivered', 'Retained by CRD', 'Retention rate', 'CRD delivered', 'Actual Order', 'Repeat rate'])
            ->assertSee('33.33%');
    }
}
