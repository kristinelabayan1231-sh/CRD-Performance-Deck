<?php

namespace Tests\Feature;

use App\Models\ActualIncomeInput;
use App\Models\ActualIncomeStatement;
use App\Models\Role;
use App\Models\User;
use App\Services\ActualIncome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CraGrossSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Manila'));
    }

    private function user(string $name, string $role): User
    {
        return User::create([
            'email' => str_replace(' ', '', strtolower($name)).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, float>  $amounts
     */
    private function type(string $month, ?User $cra, array $amounts): void
    {
        foreach ($amounts as $field => $amount) {
            ActualIncomeInput::create(['month' => $month, 'user_id' => $cra?->id, 'field' => $field, 'amount' => $amount]);
        }
    }

    public function test_each_month_takes_the_cras_actual_income_card(): void
    {
        $joanna = $this->user('Joanna Rose', Role::CRA);
        $regina = $this->user('Regina', Role::CRA);
        // January: both CRAs.
        $this->type('2026-01-01', null, ['tax' => 100000]);
        $this->type('2026-01-01', $joanna, ['gross' => 733282, 'delivered' => 500000, 'product_cost' => 50000]);
        $this->type('2026-01-01', $regina, ['gross' => 689904, 'delivered' => 400000]);
        // July: Joanna only.
        $this->type('2026-07-01', $joanna, ['gross' => 606647, 'delivered' => 300000]);
        // Another year is left out.
        $this->type('2025-01-01', $joanna, ['gross' => 999999]);

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('cra-gross-sales.index', ['year' => 2026]))
            ->assertSee('CRA Gross Sales &amp; IBSC', false)
            ->viewData('data');

        // Each month's NET INCOME is the CRA's Actual Income card's for that month.
        $cras = collect([$joanna, $regina]);
        $january = app(ActualIncome::class)->month($cras, CarbonImmutable::parse('2026-01-01'))['cras'];
        $july = app(ActualIncome::class)->month($cras, CarbonImmutable::parse('2026-07-01'))['cras'];
        $joannaMonths = $data['cras'][$joanna->id]['months'];
        $this->assertSame(['gross' => 733282.0, 'net' => $january[$joanna->id]['net_income']], $joannaMonths[0]);
        $this->assertSame(['gross' => 606647.0, 'net' => $july[$joanna->id]['net_income']], $joannaMonths[6]);
        // A month with nothing typed on the CRA's card is empty.
        $this->assertNull($joannaMonths[1]);
        $this->assertNull($data['cras'][$regina->id]['months'][6]);
        $this->assertNull($data['totals'][11]);
        // The year adds up the months; TOTAL adds up the CRAs.
        $this->assertSame(1339929.0, $data['cras'][$joanna->id]['year']['gross']);
        $this->assertEqualsWithDelta($january[$joanna->id]['net_income'] + $july[$joanna->id]['net_income'], $data['cras'][$joanna->id]['year']['net'], 0.001);
        $this->assertSame(1423186.0, $data['totals'][0]['gross']);
        $this->assertEqualsWithDelta($january[$joanna->id]['net_income'] + $january[$regina->id]['net_income'], $data['totals'][0]['net'], 0.001);
        $this->assertSame(2029833.0, $data['year']['gross']);
    }

    public function test_a_cra_who_left_shows_for_the_sheet_months_they_have_a_card(): void
    {
        $this->user('Joanna Rose', Role::CRA);
        $sharie = $this->user('Sharie', Role::CRA);
        $sharie->update(['is_active' => false]);
        ActualIncomeStatement::create(['month' => '2026-06-01', 'user_id' => $sharie->id, 'figures' => ['gross' => 116783, 'net_income' => -85261.84]]);

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('cra-gross-sales.index', ['year' => 2026]))
            ->assertSee('Sharie')
            ->viewData('data');

        $this->assertSame(['gross' => 116783.0, 'net' => -85261.84], $data['cras'][$sharie->id]['months'][5]);
        $this->assertNull($data['cras'][$sharie->id]['months'][6]);
    }

    public function test_the_top_seller_is_the_cra_with_the_most_gross_sales_and_the_goal_is_250k_a_month(): void
    {
        $joanna = $this->user('Joanna Rose', Role::CRA);
        $regina = $this->user('Regina', Role::CRA);
        // Regina sells more in February though Joanna keeps more Net Income.
        $this->type('2026-02-01', $joanna, ['gross' => 601167, 'delivered' => 600000]);
        $this->type('2026-02-01', $regina, ['gross' => 677181, 'delivered' => 300000]);

        $response = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('cra-gross-sales.index', ['year' => 2026]));

        $this->assertSame($regina->id, $response->viewData('data')['top'][1]);
        $this->assertNull($response->viewData('data')['top'][0]);
        $this->assertSame(250000.0, $response->viewData('goal'));
    }

    public function test_a_cra_cannot_see_it(): void
    {
        $this->actingAs($this->user('Joanna Rose', Role::CRA))->get(route('cra-gross-sales.index'))->assertForbidden();
    }
}
