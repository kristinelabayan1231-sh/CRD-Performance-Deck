<?php

namespace Tests\Feature;

use App\Models\MniDepartment;
use App\Models\MniInput;
use App\Models\MonthlyCostRow;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyMniTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Manila'));
    }

    private function user(string $name, string $role): User
    {
        return User::create([
            'email' => str_replace(' ', '', strtolower($name)).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true,
        ]);
    }

    public function test_statement_follows_the_sheets_formulas_and_the_department_adds_up_the_cras(): void
    {
        // October 2026, 1M, as in the sheet: Joanna's targets and the department's Tax Allocation split over 5 CRAs.
        $cras = collect(['Regina', 'July Ann', 'Joanna Rose', 'Lhea', 'Rose-An'])->map(fn ($name) => $this->user($name, Role::CRA));
        $joanna = $cras[2];
        MniInput::create(['month' => '2026-10-01', 'plan' => '1m', 'user_id' => $joanna->id, 'orders' => 730, 'conversion_rate' => 0.15, 'aov' => 880,
            'cancelled_pct' => 5, 'returns_pct' => 9.5, 'product_cost_pct' => 12]);
        MniInput::create(['month' => '2026-10-01', 'plan' => '1m', 'user_id' => $cras[0]->id, 'orders' => 619, 'conversion_rate' => 0.15, 'aov' => 900,
            'cancelled_pct' => 5, 'returns_pct' => 9.5, 'product_cost_pct' => 12]);
        MniDepartment::create(['month' => '2026-10-01', 'plan' => '1m', 'tax_allocation' => 100000, 'advertising_cost' => 0]);
        // Another plan's inputs are left out.
        MniInput::create(['month' => '2026-10-01', 'plan' => '2m', 'user_id' => $joanna->id, 'orders' => 900]);

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('mni.index', ['month' => '2026-10', 'plan' => '1m']))
            ->assertOk()
            ->assertSee('Monthly MNI')
            ->viewData('data');

        $statement = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $data['cras'][$joanna->id]);
        $this->assertEquals([
            'leads' => 486666.67, 'orders' => 730, 'conversion_rate' => 0.0, 'aov' => 880.0, 'gross' => 642400.0, 'cancelled' => 32120.0,
            'returns' => 61028.0, 'delivered' => 549252.0, 'tax' => 20000.0, 'product_cost' => 77088.0, 'gross_profit' => 452164.0,
            'advertising' => 0.0, 'ads_vat' => 0.0, 'shipping' => 54750.0, 'cod' => 10985.04, 'vat_on_cod' => 1318.2, 'fulfillment' => 18250.0,
            'selling' => 85303.24,
        ], array_intersect_key($statement, array_flip(['leads', 'orders', 'conversion_rate', 'aov', 'gross', 'cancelled', 'returns', 'delivered', 'tax',
            'product_cost', 'gross_profit', 'advertising', 'ads_vat', 'shipping', 'cod', 'vat_on_cod', 'fulfillment', 'selling'])));
        // Department: Joanna + Regina; the tax is the whole ₱100,000 (each CRA's share, even those without targets yet).
        $this->assertSame(1349, $data['department']['orders']);
        $this->assertEqualsWithDelta(642400 + 557100, $data['department']['gross'], 0.001);
        $this->assertEqualsWithDelta(100000, $data['department']['tax'], 0.001);
        $this->assertEqualsWithDelta(0.0015, $data['department']['conversion_rate'], 0.0000001);
        // NET INCOME = Gross Profit − Total Selling Costs − Total Operating Costs.
        $this->assertEqualsWithDelta(452164 - 85303.24 - $data['cras'][$joanna->id]['operating'], $data['cras'][$joanna->id]['net_income'], 0.01);
    }

    public function test_operating_costs_split_crd_mc_and_the_departments_inputs_across_the_cras(): void
    {
        // CRD - MC as in the sheet: three non-CRA rows, the five CRAs' own rows and a vacant one; CEO at ₱132,300 (default).
        $cras = collect(['Regina', 'July Ann', 'Joanna Rose', 'Lhea', 'Rose-An'])->map(fn ($name) => $this->user($name, Role::CRA));
        MonthlyCostRow::factory()->fixed(60000)->create(['position' => 'Customer Retention Manager']);
        MonthlyCostRow::factory()->fixed(34285.71)->create(['position' => 'Customer Retention Supervisor']);
        MonthlyCostRow::factory()->fixed(30000)->create(['position' => 'Junior AI Engineer - for Automation']);
        foreach ($cras as $i => $cra) {
            MonthlyCostRow::factory()->fixed($i === 4 ? 15600 : 19500)->create(['user_id' => $cra->id]);
        }
        MonthlyCostRow::factory()->vacant()->fixed(15600)->create();
        MniDepartment::create(['month' => '2026-10-01', 'plan' => '1m', 'communication_allowance' => 5000, 'miscellaneous_expenses' => 2500,
            'product_research' => 10000, 'hmo_expense' => 7500]);

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('mni.index', ['month' => '2026-10', 'plan' => '1m']))
            ->assertOk()->viewData('data');

        // Regina: 19,500 + (60,000 + 34,285.71 + 30,000 + 19,998 + 44,095.59) ÷ 5.
        $regina = $data['cras'][$cras[0]->id];
        $this->assertEqualsWithDelta(57175.86, $regina['salaries'], 0.001);
        $this->assertEqualsWithDelta(57175.86 / 12, $regina['thirteenth_month'], 0.001);
        $this->assertEqualsWithDelta(57175.86 * 12 / 313, $regina['sil'], 0.001);
        $this->assertEqualsWithDelta(1000, $regina['communication'], 0.001);
        $this->assertEqualsWithDelta(500, $regina['misc'], 0.001);
        // Gov. benefits: 2,900.80 × 8 people (the vacant row has none) ÷ 5 CRAs.
        $this->assertEqualsWithDelta(2900.80 * 8 / 5, $regina['gov_benefits'], 0.001);
        $this->assertEqualsWithDelta(15600 + 37675.86, $data['cras'][$cras[4]->id]['salaries'], 0.001);
        // The department adds back up to CRD - MC's TOTAL SALARY OF CRD and Gov. Benefits.
        $this->assertEqualsWithDelta(281979.30, $data['department']['salaries'], 0.001);
        $this->assertEqualsWithDelta(23206.40, $data['department']['gov_benefits'], 0.001);
        $this->assertEqualsWithDelta(5000, $data['department']['communication'], 0.001);
        // The CRD card's SIL is the CRAs' SIL added up, not its own Salaries × 24 ÷ 313.
        $this->assertEqualsWithDelta(collect($data['cras'])->sum('sil'), $data['department']['sil'], 0.001);

        // Product Research to HMO Expense: the department's ÷ 5; Total Operating Costs = Salaries to HMO Expense.
        $this->assertEqualsWithDelta(2000, $regina['product_research'], 0.001);
        $this->assertEqualsWithDelta(1500, $regina['hmo_expense'], 0.001);
        $this->assertEqualsWithDelta(0, $regina['rent'], 0.001);
        $operating = 57175.86 + 1000 + 57175.86 / 12 + 57175.86 * 12 / 313 + 2900.80 * 8 / 5 + 500 + 2000 + 1500;
        $this->assertEqualsWithDelta($operating, $regina['operating'], 0.001);
        // No targets typed: NET INCOME = 0 Gross Profit − 0 Selling − Operating.
        $this->assertEqualsWithDelta(-$operating, $regina['net_income'], 0.001);
        $this->assertEqualsWithDelta(10000, $data['department']['product_research'], 0.001);
        $this->assertEqualsWithDelta(collect($data['cras'])->sum('net_income'), $data['department']['net_income'], 0.001);
    }

    public function test_the_october_seed_matches_the_crd_sales_report_sheets(): void
    {
        // CRD - MC as in the sheet (CEO at the ₱132,300 default), the five CRAs, then the October 2026 seed migration.
        $names = ['Joanna Rose', 'Regina', 'July Ann', 'Lhea', 'Rose-An'];
        $cras = collect($names)->mapWithKeys(fn ($name) => [$name => $this->user($name, Role::CRA)]);
        MonthlyCostRow::factory()->fixed(60000)->create(['position' => 'Customer Retention Manager']);
        MonthlyCostRow::factory()->fixed(34285.71)->create(['position' => 'Customer Retention Supervisor']);
        MonthlyCostRow::factory()->vacant()->fixed(0)->create(['position' => 'Customer Retention Supervisor - Trainee']);
        foreach ($cras as $name => $cra) {
            MonthlyCostRow::factory()->fixed($name === 'Rose-An' ? 15600 : 19500)->create(['user_id' => $cra->id]);
        }
        MonthlyCostRow::factory()->vacant()->fixed(0)->create(['position' => 'Customer Retention Associate - Trainee']);
        MonthlyCostRow::factory()->fixed(30000)->create(['position' => 'Junior AI Engineer - for Automation']);
        (require database_path('migrations/2026_10_20_181237_seed_october_2026_mni_inputs.php'))->up();

        // The sheets' figures: line => [CRD, Joanna Rose, Regina, July Ann, Lhea, Rose-An].
        $sheets = [
            '1m' => [
                'tax' => [100000, 20000, 20000, 20000, 20000, 20000],
                'advertising' => [0, 0, 0, 0, 0, 0],
                'ads_vat' => [0, 0, 0, 0, 0, 0],
                'salaries' => [281979.3, 57175.86, 57175.86, 57175.86, 57175.86, 53275.86],
                'communication' => [810, 162, 162, 162, 162, 162],
                'thirteenth_month' => [23498.28, 4764.66, 4764.66, 4764.66, 4764.66, 4439.66],
                'sil' => [10810.71, 2192.05, 2192.05, 2192.05, 2192.05, 2042.52],
                'misc' => [7300, 1460, 1460, 1460, 1460, 1460],
                'product_research' => [5000, 1000, 1000, 1000, 1000, 1000],
                'company_assets' => [20181.41, 4036.28, 4036.28, 4036.28, 4036.28, 4036.28],
                'executive_benefits' => [19333.33, 3866.67, 3866.67, 3866.67, 3866.67, 3866.67],
                'managers_allowance' => [0, 0, 0, 0, 0, 0],
                'water_bill' => [203.33, 40.67, 40.67, 40.67, 40.67, 40.67],
                'rent' => [37650, 7530, 7530, 7530, 7530, 7530],
                'management_fee' => [33333.33, 6666.67, 6666.67, 6666.67, 6666.67, 6666.67],
                'business_development_fund' => [25000, 5000, 5000, 5000, 5000, 5000],
                'hmo_expense' => [11526.84, 2305.37, 2305.37, 2305.37, 2305.37, 2305.37],
                'leads' => [2132666.67, 486666.67, 412666.67, 413333.33, 412000, 408000],
                'orders' => [3199, 730, 619, 620, 618, 612],
                'aov' => [895.44, 880, 900, 900, 900, 900],
                'gross' => [2864500, 642400, 557100, 558000, 556200, 550800],
                'cancelled' => [143225, 32120, 27855, 27900, 27810, 27540],
                'returns' => [272127.5, 61028, 52924.5, 53010, 52839, 52326],
                'delivered' => [2449147.5, 549252, 476320.5, 477090, 475551, 470934],
                'product_cost' => [343740, 77088, 66852, 66960, 66744, 66096],
                'gross_profit' => [2005407.5, 452164, 389468.5, 390130, 388807, 384838],
                'shipping' => [239925, 54750, 46425, 46500, 46350, 45900],
                'cod' => [48982.95, 10985.04, 9526.41, 9541.8, 9511.02, 9418.68],
                'vat_on_cod' => [5877.95, 1318.2, 1143.17, 1145.02, 1141.32, 1130.24],
                'fulfillment' => [79975, 18250, 15475, 15500, 15450, 15300],
                'selling' => [374760.9, 85303.24, 72569.58, 72686.82, 72452.34, 71748.92],
                'gov_benefits' => [23206.4, 4641.28, 4641.28, 4641.28, 4641.28, 4641.28],
                'magic_fund' => [6666.67, 1333.33, 1333.33, 1333.33, 1333.33, 1333.33],
                'office_miscellaneous' => [19233.33, 3846.67, 3846.67, 3846.67, 3846.67, 3846.67],
                'maintenance_expenses' => [8933.33, 1786.67, 1786.67, 1786.67, 1786.67, 1786.67],
                'consultants' => [5786.67, 1157.33, 1157.33, 1157.33, 1157.33, 1157.33],
                'birthday_cake_allowance' => [600, 120, 120, 120, 120, 120],
                'internet' => [4565.68, 913.14, 913.14, 913.14, 913.14, 913.14],
                'electricity' => [30000, 6000, 6000, 6000, 6000, 6000],
                'operating' => [575618.6, 115998.62, 115998.62, 115998.62, 115998.62, 111624.1],
                'net_income' => [1055027.99, 250862.13, 200900.3, 201444.56, 200356.03, 201464.97],
            ],
            '2m' => [
                'tax' => [100000, 20000, 20000, 20000, 20000, 20000],
                'advertising' => [0, 0, 0, 0, 0, 0],
                'ads_vat' => [0, 0, 0, 0, 0, 0],
                'salaries' => [281979.3, 57175.86, 57175.86, 57175.86, 57175.86, 53275.86],
                'communication' => [810, 162, 162, 162, 162, 162],
                'thirteenth_month' => [23498.28, 4764.66, 4764.66, 4764.66, 4764.66, 4439.66],
                'sil' => [10810.71, 2192.05, 2192.05, 2192.05, 2192.05, 2042.52],
                'misc' => [7300, 1460, 1460, 1460, 1460, 1460],
                'product_research' => [5000, 1000, 1000, 1000, 1000, 1000],
                'company_assets' => [20181.41, 4036.28, 4036.28, 4036.28, 4036.28, 4036.28],
                'executive_benefits' => [19333.33, 3866.67, 3866.67, 3866.67, 3866.67, 3866.67],
                'managers_allowance' => [0, 0, 0, 0, 0, 0],
                'water_bill' => [203.33, 40.67, 40.67, 40.67, 40.67, 40.67],
                'rent' => [37650, 7530, 7530, 7530, 7530, 7530],
                'management_fee' => [33333.33, 6666.67, 6666.67, 6666.67, 6666.67, 6666.67],
                'business_development_fund' => [25000, 5000, 5000, 5000, 5000, 5000],
                'hmo_expense' => [11526.84, 2305.37, 2305.37, 2305.37, 2305.37, 2305.37],
                'leads' => [3280000, 657333.33, 657333.33, 657333.33, 657333.33, 650666.67],
                'orders' => [4920, 986, 986, 986, 986, 976],
                'aov' => [900, 900, 900, 900, 900, 900],
                'gross' => [4428000, 887400, 887400, 887400, 887400, 878400],
                'cancelled' => [221400, 44370, 44370, 44370, 44370, 43920],
                'returns' => [420660, 84303, 84303, 84303, 84303, 83448],
                'delivered' => [3785940, 758727, 758727, 758727, 758727, 751032],
                'product_cost' => [531360, 106488, 106488, 106488, 106488, 105408],
                'gross_profit' => [3154580, 632239, 632239, 632239, 632239, 625624],
                'shipping' => [369000, 73950, 73950, 73950, 73950, 73200],
                'cod' => [75718.8, 15174.54, 15174.54, 15174.54, 15174.54, 15020.64],
                'vat_on_cod' => [9086.26, 1820.94, 1820.94, 1820.94, 1820.94, 1802.48],
                'fulfillment' => [123000, 24650, 24650, 24650, 24650, 24400],
                'selling' => [581805.06, 116595.48, 116595.48, 116595.48, 116595.48, 115423.12],
                'gov_benefits' => [11603.2, 2320.64, 2320.64, 2320.64, 2320.64, 2320.64],
                'magic_fund' => [3333.33, 666.67, 666.67, 666.67, 666.67, 666.67],
                'office_miscellaneous' => [9733.33, 1946.67, 1946.67, 1946.67, 1946.67, 1946.67],
                'maintenance_expenses' => [26266.67, 5253.33, 5253.33, 5253.33, 5253.33, 5253.33],
                'consultants' => [5166.67, 1033.33, 1033.33, 1033.33, 1033.33, 1033.33],
                'birthday_cake_allowance' => [1600, 320, 320, 320, 320, 320],
                'internet' => [4532.33, 906.47, 906.47, 906.47, 906.47, 906.47],
                'electricity' => [38666.67, 7733.33, 7733.33, 7733.33, 7733.33, 7733.33],
                'operating' => [572528.72, 115380.65, 115380.65, 115380.65, 115380.65, 111006.13],
                'net_income' => [2000246.22, 400262.87, 400262.87, 400262.87, 400262.87, 399194.76],
            ],
        ];

        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $off = [];
        foreach ($sheets as $plan => $sheet) {
            $data = $this->actingAs($supervisor)->get(route('mni.index', ['month' => '2026-10', 'plan' => $plan]))->assertOk()->viewData('data');
            $columns = ['CRD' => $data['department'], ...collect($names)->mapWithKeys(fn ($name) => [$name => $data['cras'][$cras[$name]->id]])->all()];

            // As shown (2 decimals); the sheet's totals add up unrounded figures, so a centavo apart is the same.
            foreach ($sheet as $line => $expected) {
                foreach (array_values($columns) as $i => $figures) {
                    $shown = round((float) $figures[$line], 2);
                    if (abs($shown - $expected[$i]) > 0.011) {
                        $off[] = "$plan $line · ".array_keys($columns)[$i].': sheet '.number_format($expected[$i], 2).', app '.number_format($shown, 2);
                    }
                }
            }
        }
        $this->assertSame([], $off);

        // 2M lists Product Research under Selling And Marketing; 1M under Operating Costs.
        $this->actingAs($supervisor)->get(route('mni.index', ['month' => '2026-10', 'plan' => '2m']))
            ->assertSeeTextInOrder(['Fulfillment Fee', 'Product Research', 'Total Selling Costs', 'Operating Costs', 'Salaries']);
        $this->actingAs($supervisor)->get(route('mni.index', ['month' => '2026-10', 'plan' => '1m']))
            ->assertSeeTextInOrder(['Total Selling Costs', 'Operating Costs', 'Miscellaneous expenses', 'Product Research', 'Magic Fund']);

        // Run again (e.g. after inputs were edited): nothing saved is overwritten.
        MniInput::where('plan', '1m')->where('user_id', $cras['Regina']->id)->update(['orders' => 700]);
        (require database_path('migrations/2026_10_20_181237_seed_october_2026_mni_inputs.php'))->up();
        $this->assertSame(700, MniInput::where('plan', '1m')->where('user_id', $cras['Regina']->id)->value('orders'));
        $this->assertSame(10, MniInput::count());
    }

    public function test_a_supervisor_saves_a_cras_input_and_the_departments(): void
    {
        $regina = $this->user('Regina', Role::CRA);
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => $regina->id, 'field' => 'orders', 'value' => 619])->assertOk();
        $this->actingAs($supervisor)->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => $regina->id, 'field' => 'aov', 'value' => 900])->assertOk();
        $this->actingAs($supervisor)->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => null, 'field' => 'tax_allocation', 'value' => 100000])->assertOk();
        $this->actingAs($supervisor)->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => null, 'field' => 'communication_allowance', 'value' => 5000])->assertOk();
        $this->actingAs($supervisor)->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => null, 'field' => 'hmo_expense', 'value' => 7500])->assertOk();
        $this->actingAs($supervisor)->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => $regina->id, 'field' => 'miscellaneous_expenses', 'value' => 1])->assertStatus(422);

        $this->assertDatabaseHas('mni_inputs', ['user_id' => $regina->id, 'plan' => '1m', 'orders' => 619, 'aov' => 900, 'updated_by' => $supervisor->id]);
        $this->assertDatabaseHas('mni_departments', ['plan' => '1m', 'tax_allocation' => 100000, 'communication_allowance' => 5000, 'miscellaneous_expenses' => null, 'hmo_expense' => 7500]);
        $this->assertSame(1, MniInput::count());
    }

    public function test_a_percentage_over_100_is_rejected(): void
    {
        $regina = $this->user('Regina', Role::CRA);

        $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))
            ->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => $regina->id, 'field' => 'returns_pct', 'value' => 120])
            ->assertJsonValidationErrors(['value']);
    }

    public function test_a_cra_cannot_open_or_change_the_mni(): void
    {
        $regina = $this->user('Regina', Role::CRA);

        $this->actingAs($regina)->get(route('mni.index'))->assertForbidden();
        $this->actingAs($regina)->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => $regina->id, 'field' => 'orders', 'value' => 999])->assertForbidden();
    }
}
