<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTrackerExportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'Order ID,Assigning seller,Customer,Phone number,Variation ID,Product name,Last updated status at,Status,Unit price,By region,Province/City,"Commune/Village, District",District,Assigned to,Status,Customer Tagging,Date of Contact,Time of contact,Note from associate,Callback date,Call Recording Link';

    private User $july;

    protected function setUp(): void
    {
        parent::setUp();

        $this->july = User::create(['email' => 'july@example.com', 'display_name' => 'July Ann', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        Product::updateOrCreate(['name' => 'CanPro'], ['consumption_days' => 10]);
        Product::updateOrCreate(['name' => 'Pterygium'], ['consumption_days' => 15]);
    }

    private function import(string ...$rows): void
    {
        $path = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($path, implode("\n", [self::HEADER, ...$rows]));

        $this->artisan('leads:import-tracker-export', ['path' => $path, '--keep' => ['July=July Ann']])->assertSuccessful();
    }

    public function test_kept_cra_keeps_rows_and_others_go_unassigned_with_their_data(): void
    {
        $this->import(
            '1001,CRD JULY DE LOS SANTOS,Rose Ramirez,9543081031,2 CanPro,CanPro Guyabano Herbal Drink,14:30 20/06/2026,Delivered,1,a,b,c,d,July,PJR/Inactive/CBR/Drop call,Hot Leads / Recent Buyers (0 to 15 days),07/11/2026,,pjr,https://drive.example/rec,',
            '1002,Mari Posa,Janet B,639929509773,3 Pterygium Drops,Pterygium,12:52 14/06/2026,Delivered,1,a,b,c,d,Sharie,Inactive,Hot Leads / Recent Buyers (0 to 15 days),7/14/2026,9:00PM-10:00PM,inactive,,',
            '1003,CRD JULY DE LOS SANTOS,Larry C,9560347669,1 Ptery Clear,Ptery Clear,07:31 26/06/2026,Delivered,1,a,b,c,d,July,Active,Hot Leads / Recent Buyers (0 to 15 days),,10:00AM-11:00AM,,,',
        );

        $kept = Lead::firstWhere('order_id', '1001');
        $this->assertSame($this->july->id, $kept->assigned_to);
        $this->assertSame('crd', $kept->lead_type);
        $this->assertSame('2026-07-09', $kept->est_out_of_stock_date->toDateString());
        $this->assertSame('pjr_drop_call', $kept->status);
        $this->assertSame('2026-07-11', $kept->contact_date->toDateString());
        // The recording link in the Callback column moves to the recording field.
        $this->assertSame('https://drive.example/rec', $kept->call_recording_url);
        $this->assertNull($kept->callback_date);

        $pooled = Lead::firstWhere('order_id', '1002');
        $this->assertNull($pooled->assigned_to);
        $this->assertSame('fsd', $pooled->lead_type);
        $this->assertSame('09929509773', $pooled->phone_number);
        $this->assertSame('2026-07-28', $pooled->est_out_of_stock_date->toDateString());
        $this->assertSame('inactive', $pooled->status);
        $this->assertSame('2026-07-14', $pooled->contact_date->toDateString());
        $this->assertSame('inactive', $pooled->notes);

        // Only the sheet's default Active, no contact date or note: never worked. Unknown product: 15 days.
        $untouched = Lead::firstWhere('order_id', '1003');
        $this->assertNull($untouched->status);
        $this->assertNull($untouched->contact_time);
        $this->assertSame('2026-07-10', $untouched->est_out_of_stock_date->toDateString());
    }

    public function test_crd_type_follows_the_crd_pancake_accounts(): void
    {
        $this->import(
            '2001,Lhea Gatdula,A,9171111111,3 AudiCure,AudiCure,10:00 05/06/2026,Delivered,1,a,b,c,d,,,,,,,,',
            '2002,Mari Posa,B,9172222222,3 AudiCure,AudiCure,10:00 05/06/2026,Delivered,1,a,b,c,d,,,,,,,,',
            '2003,crd  july de los santos,C,9173333333,3 AudiCure,AudiCure,10:00 05/06/2026,Delivered,1,a,b,c,d,,,,,,,,',
        );

        $this->assertSame('crd', Lead::firstWhere('order_id', '2001')->lead_type);
        // A seller not in Settings → Pancake Accounts.
        $this->assertSame('fsd', Lead::firstWhere('order_id', '2002')->lead_type);
        $this->assertSame('crd', Lead::firstWhere('order_id', '2003')->lead_type);
    }

    public function test_orders_already_leads_are_skipped(): void
    {
        Lead::create(['order_id' => '1001', 'customer_name' => 'Old', 'phone_number' => '1', 'product_name' => 'CanPro', 'qty' => 1,
            'delivered_date' => '2026-06-20', 'consumption_days' => 10, 'est_out_of_stock_date' => '2026-06-29', 'lead_type' => 'crd']);

        $this->import('1001,CRD JULY DE LOS SANTOS,Rose Ramirez,9543081031,2 CanPro,CanPro,14:30 20/06/2026,Delivered,1,a,b,c,d,July,,,,,,,');

        $this->assertSame('Old', Lead::firstWhere('order_id', '1001')->customer_name);
        $this->assertSame(1, Lead::count());
    }
}
