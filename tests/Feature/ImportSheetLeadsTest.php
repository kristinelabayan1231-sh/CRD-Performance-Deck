<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportSheetLeadsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ',Customer Name,Qty,Product,Contact #,Delivered Date,Days since Delivered,Est. Out of Stock,Recommended Replenishment Day,Assigned to,Status,Repeat Purchase?,Customer Tagging,Date of Contact,Time of contact,Customer\'s Feedback,Note from associate,Callback date,Call Recording Link';

    private User $joanna;

    private User $regina;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shecom.key' => 'test-key']);
        $cra = Role::firstWhere('slug', Role::CRA)->id;
        $this->joanna = User::create(['email' => 'joanna@example.com', 'display_name' => 'Joanna Rose', 'role_id' => $cra, 'is_active' => true]);
        $this->regina = User::create(['email' => 'regina@example.com', 'display_name' => 'Regina', 'role_id' => $cra, 'is_active' => true]);
    }

    private function sheet(string ...$rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet');
        file_put_contents($path, implode("\n", [self::HEADER, ...$rows]));

        return $path;
    }

    private function fakeApi(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/management/retention-stockout' => Http::response([
            'stock_outs' => [[
                'order_id' => '1001', 'tracking_number' => 'JT1001', 'customer_name' => 'Ana Cruz', 'phone_number' => '9171111111',
                'product_name' => 'Pterygium', 'qty' => 2, 'delivered_date' => '2026-08-03', 'consumption_days_per_unit' => 15,
                'estimated_out_of_stock_date' => '2026-09-01',
            ], [
                'order_id' => '3003', 'tracking_number' => 'JT3003', 'customer_name' => 'Eva Sol', 'phone_number' => '9175555555',
                'product_name' => 'Sinuxyl', 'qty' => 1, 'delivered_date' => '2026-08-10', 'consumption_days_per_unit' => 30,
                'estimated_out_of_stock_date' => '2026-09-08',
            ]],
            'repeat_detail' => [],
            'retention_detail' => [
                // Same customer's earlier FSD order: not the one the sheet row is about.
                ['order_id' => '900', 'tracking_number' => 'JT900', 'phone_number' => '9171111111', 'product' => 'Pterygium', 'delivered_date' => '2026-04-10'],
                ['order_id' => '2002', 'tracking_number' => 'JT2002', 'phone_number' => '9172222222', 'product' => 'Sinuxyl', 'delivered_date' => '2026-08-04'],
            ],
        ])]);
    }

    private function import(string $path, string ...$options): void
    {
        $this->artisan('leads:import-sheet', [
            'path' => $path, '--year' => 2026, '--alias' => ['Anna=Joanna Rose', 'Rej=Regina'],
            ...collect($options)->mapWithKeys(fn ($o) => [explode('=', $o, 2)[0] => explode('=', $o, 2)[1]])->all(),
        ])->assertSuccessful();
    }

    public function test_rows_are_matched_to_shecom_orders_and_assigned_to_the_sheet_cra(): void
    {
        $this->fakeApi();
        $path = $this->sheet(
            'CRD LEADS,Ana Cruz,2,Pterygium Drops,9171111111,August 3,65,September 1,August 25,Anna,PJR/Inactive/CBR/Drop call,NO,Warm Leads / Old Customers (16 to 30 days),09/26/2025,8:00PM-9:00PM,HINDI NAKAUSAP NI CRA,cbr,,',
            'FACEBOOK SALES LEADS,Ben Reyes,2,Sinuxyl,9172222222,August 4,64,September 1,August 25,Rej,,,,,,,,,',
            ',Cara Diaz,1,Sinuxyl,9173333333,August 18,50,September 1,August 25,Rej,Active,YES,,Sept 28,9995828454,MAY STOCKS PA,,,',
            ',Eva Sol,1,Sinuxyl,,August 10,50,September 1,August 25,Rej,,,,,,,,,',
        );

        $this->import($path);

        $crd = Lead::firstWhere('order_id', '1001');
        $this->assertSame([Lead::TYPE_CRD, $this->joanna->id, 'JT1001', '2026-09-01'], [$crd->lead_type, $crd->assigned_to, $crd->tracking_number, $crd->est_out_of_stock_date->toDateString()]);
        $this->assertSame(['pjr_drop_call', 'no', 'warm', '2026-09-26', '20', 'no_verbal_conv', 'cbr'],
            [$crd->status, $crd->repeat_purchase, $crd->customer_tag, $crd->contact_date->toDateString(), $crd->contact_time, $crd->feedback, $crd->notes]);

        $fsd = Lead::firstWhere('order_id', '2002');
        $this->assertSame([Lead::TYPE_FSD, $this->regina->id, null], [$fsd->lead_type, $fsd->assigned_to, $fsd->status]);

        // No Shecom order: still imported from the sheet, with a sheet ID; a phone number as the time is dropped.
        $sheetOnly = Lead::firstWhere('customer_name', 'Cara Diaz');
        $this->assertStringStartsWith('SHEET-9173333333-20260818', $sheetOnly->order_id);
        $this->assertSame(['active', 'yes', '2026-09-28', null, 'still_have_stocks'],
            [$sheetOnly->status, $sheetOnly->repeat_purchase, $sheetOnly->contact_date->toDateString(), $sheetOnly->contact_time, $sheetOnly->feedback]);

        // No phone in the sheet: matched by name and delivered date, phone taken from Shecom.
        $this->assertSame('9175555555', Lead::firstWhere('order_id', '3003')->phone_number);
        $this->assertSame(4, Lead::count());
    }

    public function test_a_batch_imports_only_that_day_and_cra_and_reruns_do_not_duplicate(): void
    {
        $this->fakeApi();
        $path = $this->sheet(
            'CRD LEADS,Ana Cruz,2,Pterygium Drops,9171111111,August 3,65,September 1,August 25,Anna,,,,,,,,,',
            'FACEBOOK SALES LEADS,Ben Reyes,2,Sinuxyl,9172222222,August 4,64,September 1,August 25,Rej,,,,,,,,,',
            ',Dan Lim,1,Sinuxyl,9174444444,August 19,49,September 2,August 26,Rej,,,,,,,,,',
        );

        $this->import($path, '--date=2026-09-01', '--cra=Rej');
        $this->import($path, '--date=2026-09-01', '--cra=Rej');

        $this->assertSame(['Ben Reyes'], Lead::pluck('customer_name')->all());
    }
}
