<?php

namespace Tests\Feature;

use App\Models\LogisticsOrder;
use App\Services\ShecomClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShecomReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shecom.key' => 'test-key']);
        Http::preventStrayRequests();
    }

    public function test_an_order_on_several_lists_keeps_its_out_of_stock_row_whatever_order_the_lists_come_in(): void
    {
        $order = ['order_id' => '501', 'customer_name' => 'Gina', 'phone_number' => '9064085393', 'delivered_date' => '2026-11-02'];

        // The lists arrive in the opposite order to the one they rank in.
        Http::fake(['*/management/retention-stockout' => Http::response(json_encode([
            'retention_detail' => [[...$order, 'product' => 'Sinuxyl', 'retained_by_crd' => true]],
            'repeat_detail' => [[...$order, 'product_name' => 'Sinuxyl', 'qty' => 2, 'ordered_again_via_crd' => true]],
            'retention_summary' => ['fb_delivered_total' => 1],
            'stock_outs' => [[...$order, 'product_name' => 'Pterygium Drops', 'qty' => 3]],
        ]))]);

        $report = app(ShecomClient::class)->report();

        $this->assertSame([[
            'order_id' => '501', 'team' => LogisticsOrder::TEAM_CRD, 'customer_name' => 'Gina', 'phone_number' => '9064085393',
            'product' => 'Pterygium Drops', 'qty' => 3, 'delivered_date' => '2026-11-02',
        ]], $report['delivered']);
        $this->assertSame(['2026-11-02' => ['fb_delivered' => 1, 'fb_retained' => 1, 'crd_delivered' => 1, 'crd_again' => 1]], $report['days']);
        $this->assertSame(['fb_delivered_total' => 1], $report['summary']);
    }

    public function test_a_body_that_is_not_json_is_an_empty_report(): void
    {
        Http::fake(['*/management/retention-stockout' => Http::response('{"stock_outs": [{"order_id": "1"}, <html>')]);

        $this->assertSame(['stock_outs' => [], 'fsd_orders' => [], 'delivered' => [], 'summary' => [], 'days' => []],
            app(ShecomClient::class)->report());
    }
}
