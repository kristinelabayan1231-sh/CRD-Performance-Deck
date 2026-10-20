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
        $this->assertEqualsWithDelta(57175.86 * 24 / 313, $regina['sil'], 0.001);
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
        $operating = 57175.86 + 1000 + 57175.86 / 12 + 57175.86 * 24 / 313 + 2900.80 * 8 / 5 + 500 + 2000 + 1500;
        $this->assertEqualsWithDelta($operating, $regina['operating'], 0.001);
        // No targets typed: NET INCOME = 0 Gross Profit − 0 Selling − Operating.
        $this->assertEqualsWithDelta(-$operating, $regina['net_income'], 0.001);
        $this->assertEqualsWithDelta(10000, $data['department']['product_research'], 0.001);
        $this->assertEqualsWithDelta(collect($data['cras'])->sum('net_income'), $data['department']['net_income'], 0.001);
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
