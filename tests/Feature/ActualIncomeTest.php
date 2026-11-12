<?php

namespace Tests\Feature;

use App\Models\ActualIncomeInput;
use App\Models\Role;
use App\Models\User;
use App\Services\MonthlyMni;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ActualIncomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Asia/Manila'));
    }

    private function user(string $name, string $role): User
    {
        return User::create([
            'email' => str_replace(' ', '', strtolower($name)).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true,
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function fiveCras(): Collection
    {
        return collect(['Regina', 'July Ann', 'Joanna Rose', 'Lhea', 'Rose-An'])->map(fn (string $name) => $this->user($name, Role::CRA));
    }

    /**
     * @param  array<string, float>  $amounts
     */
    private function type(?User $cra, array $amounts): void
    {
        foreach ($amounts as $field => $amount) {
            ActualIncomeInput::create(['month' => '2026-11-01', 'user_id' => $cra?->id, 'field' => $field, 'amount' => $amount]);
        }
    }

    public function test_cards_follow_the_crd_sheets_formulas(): void
    {
        // The CRD sheet: Joanna's typed figures and the CRD's typed amounts, shared over 5 CRAs.
        $cras = $this->fiveCras();
        $joanna = $cras[2];
        $this->type($joanna, ['orders' => 510, 'gross' => 429688, 'cancelled' => 28186, 'returns' => 35476, 'delivered' => 366026, 'product_cost' => 50827]);
        $this->type($cras[0], ['orders' => 490, 'gross' => 400000, 'delivered' => 350000]);
        $this->type(null, ['tax' => 100000, 'advertising' => 967246.48, 'ads_vat' => 3266.94, 'product_research' => 500, 'communication' => 600, 'misc' => 7000]);
        // Another month's amounts are left out.
        ActualIncomeInput::create(['month' => '2026-10-01', 'user_id' => $joanna->id, 'field' => 'orders', 'amount' => 999]);

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('actual-income.index', ['month' => '2026-11']))
            ->assertSee('Actual Income')
            ->viewData('data');

        $card = array_map(fn ($value) => is_float($value) ? round($value, 2) : $value, $data['cras'][$joanna->id]);
        $this->assertSame([
            'orders' => 510.0, 'conversion' => null, 'aov' => 842.53, 'unfulfilled' => 0.0, 'tax' => 20000.0, 'gross_profit' => 295199.0,
            'advertising' => 193449.3, 'ads_vat' => 653.39, 'shipping' => 38250.0, 'cod' => 7320.52, 'fulfillment' => 12750.0,
            'product_research' => 166.67, 'selling' => 252589.87, 'communication' => 120.0, 'misc' => 1400.0,
        ], array_intersect_key($card, array_flip(['orders', 'conversion', 'aov', 'unfulfilled', 'tax', 'gross_profit', 'advertising', 'ads_vat',
            'shipping', 'cod', 'fulfillment', 'product_research', 'selling', 'communication', 'misc'])));
        // Salaries to Government Benefits come from the 1M Monthly MNI.
        $mni = app(MonthlyMni::class)->for($cras, CarbonImmutable::parse('2026-11-01'), '1m')['cras'][$joanna->id];
        $this->assertSame([$mni['salaries'], $mni['thirteenth_month'], $mni['sil'], $mni['gov_benefits']],
            array_values(array_intersect_key($data['cras'][$joanna->id], array_flip(['salaries', 'thirteenth_month', 'sil', 'gov_benefits']))));
        $this->assertEqualsWithDelta(295199 - 252589.87 - $card['operating'], $card['net_income'], 0.01);

        // The CRD card adds up the CRA cards and keeps its own typed amounts.
        $department = $data['department'];
        $this->assertSame(1000.0, $department['orders']);
        $this->assertSame(829688.0, $department['gross']);
        $this->assertSame(75000.0, $department['shipping']);
        $this->assertSame(100000.0, $department['tax']);
        $this->assertSame(500.0, $department['product_research']);
        $this->assertSame(716026.0 - 100000 - 50827, $department['gross_profit']);
    }

    public function test_a_supervisor_saves_and_clears_amounts_on_the_cards(): void
    {
        $joanna = $this->fiveCras()[2];
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->putJson(route('actual-income.update'), ['month' => '2026-11', 'cra' => $joanna->id, 'field' => 'orders', 'value' => 510])->assertOk();
        $this->actingAs($supervisor)->putJson(route('actual-income.update'), ['month' => '2026-11', 'cra' => null, 'field' => 'tax', 'value' => 100000])->assertOk();
        $this->actingAs($supervisor)->putJson(route('actual-income.update'), ['month' => '2026-11', 'cra' => $joanna->id, 'field' => 'orders', 'value' => 520])->assertOk();

        $this->assertDatabaseCount('actual_income_inputs', 2);
        $this->assertDatabaseHas('actual_income_inputs', ['user_id' => $joanna->id, 'field' => 'orders', 'amount' => 520, 'updated_by' => $supervisor->id]);
        $this->assertDatabaseHas('actual_income_inputs', ['user_id' => null, 'field' => 'tax', 'amount' => 100000]);

        $this->actingAs($supervisor)->putJson(route('actual-income.update'), ['month' => '2026-11', 'cra' => null, 'field' => 'tax', 'value' => null])->assertOk();

        $this->assertDatabaseMissing('actual_income_inputs', ['field' => 'tax']);
    }

    public function test_a_line_worked_out_on_a_card_cannot_be_typed_there(): void
    {
        $joanna = $this->fiveCras()[2];

        // Tax Allocation is the CRD's ÷ CRAs on a CRA card; Gross Sales adds up the CRA cards on the CRD card.
        $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))
            ->putJson(route('actual-income.update'), ['month' => '2026-11', 'cra' => $joanna->id, 'field' => 'tax', 'value' => 5])
            ->assertUnprocessable();
        $this->putJson(route('actual-income.update'), ['month' => '2026-11', 'cra' => null, 'field' => 'gross', 'value' => 5])
            ->assertUnprocessable();

        $this->assertDatabaseCount('actual_income_inputs', 0);
    }

    public function test_a_cra_cannot_see_or_type_the_actual_income(): void
    {
        $joanna = $this->fiveCras()[2];

        $this->actingAs($joanna)->get(route('actual-income.index'))->assertForbidden();
        $this->actingAs($joanna)->putJson(route('actual-income.update'), ['month' => '2026-11', 'cra' => $joanna->id, 'field' => 'orders', 'value' => 1])->assertForbidden();

        $this->assertDatabaseCount('actual_income_inputs', 0);
    }
}
