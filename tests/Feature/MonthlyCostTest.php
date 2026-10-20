<?php

namespace Tests\Feature;

use App\Models\MonthlyCostRow;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\MonthlyCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCostTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
    }

    /**
     * The CRD sheet's positions: 8 people and two vacant positions.
     */
    private function sheetRows(): void
    {
        MonthlyCostRow::factory()->fixed(60000)->create(['position' => 'Customer Retention Manager', 'name' => 'Oliver']);
        MonthlyCostRow::factory()->fixed(34285.71)->create(['position' => 'Customer Retention Supervisor', 'name' => 'Joanne']);
        MonthlyCostRow::factory()->vacant()->fixed(15600)->create(['position' => 'Customer Retention Supervisor - Trainee']);
        MonthlyCostRow::factory()->count(4)->create();
        MonthlyCostRow::factory()->fixed(15600)->create(['name' => 'Rose-An']);
        MonthlyCostRow::factory()->vacant()->fixed(15600)->create();
        MonthlyCostRow::factory()->fixed(30000)->create(['position' => 'Junior AI Engineer', 'name' => 'Kristine']);
    }

    public function test_the_monthly_cost_adds_up_like_the_crd_sheet(): void
    {
        $this->sheetRows();
        // The sheet's CEO share (44,095.59) is 33.33% of 132,300, the default.

        $this->actingAs($this->supervisor)->get(route('monthly-cost.index'))->assertOk()
            ->assertViewHas('totals', [
                'team' => 217885.71, 'shared' => 64093.59, 'customer_retention' => 281979.30, 'thirteenth_month' => 23498.28,
                'sil' => 10810.71, 'gov_benefits' => 23206.40, 'people' => 8, 'total' => 339494.68,
            ])
            ->assertSeeTextInOrder(['Total monthly cost', '₱339,494.68', '8 people', 'Customer Retention', '₱281,979.30',
                '13th Month', '₱23,498.28', 'SIL', '₱10,810.71', 'Gov. Benefits', '₱23,206.40', '2,900.80 × 8 people'])
            ->assertSeeTextInOrder(['Rose-An', '15,600.00', 'Vacant', '0.00', 'Vacant · not counted'])
            ->assertDontSeeText('Pay')
            ->assertSeeTextInOrder(['33.33% of CEO', '₱132,300.00 × 33.33%', '44,095.59', 'TOTAL SALARY OF CRD', '281,979.30']);
    }

    public function test_only_users_with_the_crd_mc_permission_can_open_or_change_it(): void
    {
        $cra = User::create(['email' => 'cra@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        $row = MonthlyCostRow::factory()->create();

        $this->actingAs($cra)->get(route('dashboard'))->assertDontSee(route('monthly-cost.index'));
        $this->actingAs($cra)->get(route('monthly-cost.index'))->assertForbidden();
        $this->actingAs($cra)->delete(route('monthly-cost.destroy', $row))->assertForbidden();
        $this->assertModelExists($row);

        $this->actingAs($this->supervisor)->get(route('dashboard'))->assertSee(route('monthly-cost.index'));
    }

    public function test_a_supervisor_adds_edits_and_deletes_rows(): void
    {
        $this->actingAs($this->supervisor)->post(route('monthly-cost.store'), [
            'position' => 'Customer Retention Associate - Regular', 'name' => 'Lhea', 'salary' => '19500',
        ])->assertRedirect()->assertSessionHas('status', 'Row added.');

        $row = MonthlyCostRow::sole();
        $this->assertSame(['Lhea', '19500.00', $this->supervisor->id], [$row->name, $row->salary, $row->updated_by]);
        $this->assertSame(19500.0, $row->monthlySalary());

        $this->actingAs($this->supervisor)->patch(route('monthly-cost.update', $row), [
            'position' => 'Junior AI Engineer', 'name' => 'Lhea', 'salary' => '30000',
        ])->assertRedirect()->assertSessionHas('status', 'Row saved.');
        $this->assertSame(['Junior AI Engineer', '30000.00'], [$row->fresh()->position, $row->fresh()->salary]);

        $this->actingAs($this->supervisor)->delete(route('monthly-cost.destroy', $row))->assertRedirect();
        $this->assertModelMissing($row);
    }

    public function test_a_row_needs_a_position_and_a_salary(): void
    {
        $this->actingAs($this->supervisor)->post(route('monthly-cost.store'), ['editing' => 'new'])
            ->assertSessionHasErrors(['position', 'salary' => 'Enter the monthly salary.']);
        $this->assertDatabaseCount('monthly_cost_rows', 0);

        // The form comes back open with what was typed.
        $this->actingAs($this->supervisor)->post(route('monthly-cost.store'), ['position' => 'Typed position', 'editing' => 'new']);
        $this->actingAs($this->supervisor)->withSession(['_old_input' => ['editing' => 'new', 'position' => 'Typed position']])
            ->get(route('monthly-cost.index'))->assertSee('value="Typed position"', false);
    }

    public function test_settings_change_the_shares_and_benefits(): void
    {
        MonthlyCostRow::factory()->create();
        $settings = [...config('monthly_cost.defaults'), 'ceo_share' => 50, 'phic' => 100, 'hdmf' => 100, 'sss' => 100];

        $this->actingAs($this->supervisor)->put(route('monthly-cost.settings'), $settings)->assertSessionHas('status', 'Settings saved.');

        // 19,500 + 60,000 × 33.33% + 132,300 × 50%; gov. benefits 300 for the one person.
        $this->actingAs($this->supervisor)->get(route('monthly-cost.index'))
            ->assertViewHas('totals', fn (array $t) => $t['team'] === 19500.0 && $t['customer_retention'] === 105648.0 && $t['gov_benefits'] === 300.0);

        // The Gov. benefits card saves its three alone; the rest stay as they were.
        $this->actingAs($this->supervisor)->put(route('monthly-cost.settings'), ['phic' => 622.70, 'hdmf' => 400, 'sss' => 1878.10])
            ->assertSessionHasNoErrors();
        $this->assertSame(2900.8, (new MonthlyCost)->govBenefitsPerPerson());
        $this->assertSame('50', Setting::value(MonthlyCost::PREFIX.'ceo_share'));

        $this->actingAs($this->supervisor)->put(route('monthly-cost.settings'), [...$settings, 'ceo_share' => 120])
            ->assertSessionHasErrors('ceo_share');
        $this->assertSame('50', Setting::value(MonthlyCost::PREFIX.'ceo_share'));
    }

    public function test_a_row_is_linked_to_its_cra(): void
    {
        $regina = User::create(['email' => 'regina@example.com', 'display_name' => 'Regina', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        $row = MonthlyCostRow::factory()->create(['name' => 'Ma Regina Vergara']);

        $this->actingAs($this->supervisor)->patch(route('monthly-cost.update', $row), ['position' => $row->position, 'name' => $row->name, 'user_id' => $regina->id, 'salary' => 19500])
            ->assertSessionHasNoErrors();
        $this->assertSame($regina->id, $row->fresh()->user_id);
        $this->actingAs($this->supervisor)->get(route('monthly-cost.index'))->assertSeeText('CRA: Regina');
    }

    public function test_a_shared_salary_is_edited_in_its_row(): void
    {
        $this->actingAs($this->supervisor)->get(route('monthly-cost.index'))
            ->assertSee('data-cost-edit="shared-vp"', false)
            ->assertSee('name="vp_salary"', false);

        // The row's form sends only its salary and share; the other shared salaries keep theirs.
        $this->actingAs($this->supervisor)->put(route('monthly-cost.settings'), ['editing' => 'shared-vp', 'vp_salary' => 50000, 'vp_share' => 20])
            ->assertSessionHasNoErrors();
        $this->assertSame(10000.0, (new MonthlyCost)->shared()[1]['amount']);
        $this->assertNull(Setting::value(MonthlyCost::PREFIX.'ceo_salary'));
    }
}
