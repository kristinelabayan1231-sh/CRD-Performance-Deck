<?php

namespace Tests\Feature;

use App\Models\MniDepartment;
use App\Models\MniInput;
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
        ], $statement);
        // Department: Joanna + Regina; the tax is the whole ₱100,000 (each CRA's share, even those without targets yet).
        $this->assertSame(1349, $data['department']['orders']);
        $this->assertEqualsWithDelta(642400 + 557100, $data['department']['gross'], 0.001);
        $this->assertEqualsWithDelta(100000, $data['department']['tax'], 0.001);
        $this->assertEqualsWithDelta(0.0015, $data['department']['conversion_rate'], 0.0000001);
    }

    public function test_a_supervisor_saves_a_cras_input_and_the_departments(): void
    {
        $regina = $this->user('Regina', Role::CRA);
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => $regina->id, 'field' => 'orders', 'value' => 619])->assertOk();
        $this->actingAs($supervisor)->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => $regina->id, 'field' => 'aov', 'value' => 900])->assertOk();
        $this->actingAs($supervisor)->putJson(route('mni.update'), ['month' => '2026-10', 'plan' => '1m', 'cra' => null, 'field' => 'tax_allocation', 'value' => 100000])->assertOk();

        $this->assertDatabaseHas('mni_inputs', ['user_id' => $regina->id, 'plan' => '1m', 'orders' => 619, 'aov' => 900, 'updated_by' => $supervisor->id]);
        $this->assertDatabaseHas('mni_departments', ['plan' => '1m', 'tax_allocation' => 100000]);
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
