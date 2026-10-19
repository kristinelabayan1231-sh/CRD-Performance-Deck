<?php

namespace Tests\Feature;

use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportAssignedLeadsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "Facebook Sales Department LEADS,Customer Name,Qty,Product,Contact #,Delivered Date,Year,Days since Delivered,Est. Out of Stock,Recommended Replenishment Day,Assigned to,Status,Repeat Purchase?,Customer Tagging,Date of Contact,Time of contact,Customer's Feedback,Note from associate,Callback date,Call Recording Link";

    private User $rose;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rose = User::create(['email' => 'rose@example.com', 'display_name' => 'wfh rose', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        Product::updateOrCreate(['name' => 'Pterygium'], ['consumption_days' => 15]);
    }

    private function delivered(string $orderId, string $phone, string $date, string $product = 'Pterygium'): void
    {
        DeliveredOrder::create(['order_id' => $orderId, 'customer_name' => 'Pancake name', 'phone_number' => $phone, 'product_raw' => $product,
            'qty' => 1, 'delivered_date' => $date, 'source' => DeliveredOrder::SOURCE_PANCAKE]);
    }

    private function import(string ...$rows): void
    {
        $this->importWithHeader(self::HEADER, ...$rows);
    }

    private function importWithHeader(string $header, string ...$rows): void
    {
        $path = tempnam(sys_get_temp_dir(), 'assigned');
        file_put_contents($path, implode("\n", [$header, ...$rows]));

        $this->artisan('leads:import-assigned-leads', ['paths' => [$path], '--cra' => ['Rose=wfh rose']])->assertSuccessful();
    }

    public function test_rows_get_their_order_from_contact_number_delivered_date_and_product(): void
    {
        $this->delivered('1350001', '09537589314', '2026-08-08');
        $this->delivered('1350009', '09537589314', '2026-07-01');
        // Stored as Pancake had it, with a stray +0 prefix.
        $this->delivered('1350002', '+09214466878', '2026-04-10');

        $this->import(
            ',Jane Zamora,1,Pterygium Drops,9537589314,August 8,2026,72,August 22,August 15,Rose,Active,YES,Hot Leads / Recent Buyers (0 to 15 days),08/26/2025,7:00PM-8:00PM,MAY STOCKS PA,MAY STOCK PA,,',
            'CRD LEADS,Riza Santos,9,Pterygium Drops,9214466878,April 10,2026,192,August 22,August 15,Rose,,,,,,,,,',
            ',No Order,1,Pterygium Drops,9170000000,August 8,2026,72,August 22,August 15,Rose,,,,,,,,,',
        );

        $worked = Lead::firstWhere('order_id', '1350001');
        $this->assertSame($this->rose->id, $worked->assigned_to);
        $this->assertSame('fsd', $worked->lead_type);
        // Listed on the sheet's Est. Out of Stock.
        $this->assertSame('2026-08-22', $worked->est_out_of_stock_date->toDateString());
        $this->assertSame('2026-08-22', $worked->listed_on->toDateString());
        $this->assertSame('still_have_stocks', $worked->feedback);
        // 2025 typo read as 2026; bought on the day contacted.
        $this->assertSame('2026-08-26', $worked->contact_date->toDateString());
        $this->assertSame('yes', $worked->repeat_purchase);
        $this->assertSame('2026-08-26', $worked->repeat_purchase_on->toDateString());

        $untouched = Lead::firstWhere('order_id', '1350002');
        $this->assertSame('crd', $untouched->lead_type);
        $this->assertNull($untouched->status);

        $this->assertSame(2, Lead::count());
    }

    public function test_a_pool_lead_gets_the_sheet_cra_and_an_assigned_lead_is_skipped(): void
    {
        $this->delivered('1325209', '09104855101', '2026-07-04', 'Sinuxyl');
        $this->delivered('1325210', '09976746861', '2026-07-04', 'Sinuxyl');
        $base = ['customer_name' => 'C', 'product_name' => 'Sinuxyl', 'qty' => 3, 'delivered_date' => '2026-07-04', 'consumption_days' => 15, 'est_out_of_stock_date' => '2026-08-17', 'lead_type' => 'crd'];
        $pool = Lead::create(['order_id' => '1325209', 'phone_number' => '9104855101', 'est_out_of_stock_date' => '2026-07-18'] + $base);
        $other = User::create(['email' => 'anna@example.com', 'display_name' => 'Anna', 'role_id' => $this->rose->role_id, 'is_active' => true]);
        $assigned = Lead::create(['order_id' => '1325210', 'phone_number' => '9976746861', 'assigned_to' => $other->id] + $base);

        // No section label: the pool lead keeps its type.
        $this->importWithHeader(str_replace('Facebook Sales Department LEADS', 'Order ID', self::HEADER),
            ',Zaldy Cuba Jr,3,Sinuxyl,9104855101,July 4,2026,107,August 17,August 10,Rose,Active,NO,,08/19/2026,8:00PM-9:00PM,NOT INTERESTED,Not interested,,',
            ',Britanico Samonte,3,Sinuxyl,9976746861,July 4,2026,107,August 17,August 10,Rose,Active,NO,,08/19/2026,8:00PM-9:00PM,MAY STOCKS PA,stock pa,,',
        );

        $pool->refresh();
        $this->assertSame($this->rose->id, $pool->assigned_to);
        $this->assertSame('2026-07-18', $pool->est_out_of_stock_date->toDateString());
        $this->assertSame('2026-08-17', $pool->listed_on->toDateString());
        $this->assertSame('not_interested', $pool->feedback);
        $this->assertSame('crd', $pool->lead_type);

        $this->assertSame($other->id, $assigned->fresh()->assigned_to);
        $this->assertNull($assigned->fresh()->feedback);
    }
}
