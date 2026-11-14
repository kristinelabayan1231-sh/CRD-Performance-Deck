<?php

namespace Tests\Feature;

use App\Models\ActualIncomeInput;
use App\Models\ActualIncomeStatement;
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

    public function test_months_open_read_only_and_edit_mode_is_for_the_supervisor(): void
    {
        $joanna = $this->fiveCras()[2];
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->get(route('actual-income.index', ['month' => '2026-11']))
            ->assertViewHas('canEdit', false)->assertSee('>Edit</a>', false)->assertDontSee('data-actual-income-input', false)
            ->assertSee('type="month" name="month" value="2026-11"', false);
        $this->get(route('actual-income.index', ['month' => '2026-11', 'edit' => 1]))
            ->assertViewHas('canEdit', true)->assertSee('>Done</a>', false)->assertSee('data-actual-income-input', false);

        // Someone who can view but not type gets no Edit button, even with ?edit=1.
        $viewer = $this->user('Viewer', Role::CRA);
        $viewer->role->update(['permissions' => ['actual_income.view']]);
        $this->actingAs($viewer)->get(route('actual-income.index', ['month' => '2026-11', 'edit' => 1]))
            ->assertViewHas('canEdit', false)->assertDontSee('>Edit</a>', false);
        $this->actingAs($viewer)->putJson(route('actual-income.update'), ['month' => '2026-11', 'cra' => $joanna->id, 'field' => 'orders', 'value' => 1])
            ->assertForbidden();
    }

    public function test_a_supervisor_types_over_a_line_of_a_month_from_the_sheet_and_its_totals_move_with_it(): void
    {
        $joanna = $this->fiveCras()[2];
        $sharie = $this->user('Sharie', Role::CRA);
        $sharie->update(['is_active' => false]);
        ActualIncomeStatement::create(['month' => '2026-01-01', 'user_id' => $joanna->id, 'figures' => [
            'orders' => 10, 'gross' => 10000, 'delivered' => 8000, 'unfulfilled' => 500, 'gross_profit' => 6000,
            'selling' => 1000, 'operating' => 2000, 'net_income' => 3000, 'aov' => 1000,
        ]]);
        ActualIncomeStatement::create(['month' => '2026-01-01', 'user_id' => $sharie->id, 'figures' => ['gross' => 100, 'net_income' => -5]]);
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->get(route('actual-income.index', ['month' => '2026-01', 'edit' => 1]))
            ->assertViewHas('canEdit', true)->assertSee('data-field="delivered"', false)->assertDontSee('data-field="net_income"', false);

        $put = fn (?int $cra, string $field, $value) => $this->actingAs($supervisor)
            ->putJson(route('actual-income.update'), ['month' => '2026-01', 'cra' => $cra, 'field' => $field, 'value' => $value]);
        $put($joanna->id, 'delivered', 8500)->assertOk();
        $put($joanna->id, 'rent', 300)->assertOk();
        $put($joanna->id, 'gross', 12000)->assertOk();
        // An inactive CRA's sheet card can be corrected too; a total can't be typed over.
        $put($sharie->id, 'gross', 200)->assertOk();
        $put($joanna->id, 'net_income', 1)->assertUnprocessable();

        $figures = ActualIncomeStatement::where('user_id', $joanna->id)->first()->figures;
        $this->assertEquals([8500, 12000, 2000, 6500, 1000, 2300, 3200, 1200, 300],
            [$figures['delivered'], $figures['gross'], $figures['unfulfilled'], $figures['gross_profit'], $figures['selling'],
                $figures['operating'], $figures['net_income'], $figures['aov'], $figures['rent']]);
        $this->assertEquals(200, ActualIncomeStatement::where('user_id', $sharie->id)->first()->figures['gross']);
        $this->assertDatabaseCount('actual_income_inputs', 0);
    }

    public function test_a_month_from_the_sheet_shows_its_cards_as_they_are(): void
    {
        $joanna = $this->fiveCras()[2];
        $sharie = $this->user('Sharie', Role::CRA);
        $sharie->update(['is_active' => false]);
        // January 2026 as in the sheet: its Shipping Fee and NET INCOME aren't what the formulas would give.
        ActualIncomeStatement::create(['month' => '2026-01-01', 'user_id' => null, 'figures' => ['gross' => 1952486, 'shipping' => 175132, 'net_income' => 758698.72]]);
        ActualIncomeStatement::create(['month' => '2026-01-01', 'user_id' => $joanna->id, 'figures' => [
            'orders' => 926, 'aov' => 800, 'gross' => 733282, 'shipping' => 66046, 'vat_on_cod' => 1072.09, 'ai_expense' => 0, 'net_income' => 292860.75,
        ]]);
        ActualIncomeStatement::create(['month' => '2026-01-01', 'user_id' => $sharie->id, 'figures' => ['gross' => 100, 'net_income' => -5]]);
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $response = $this->actingAs($supervisor)->get(route('actual-income.index', ['month' => '2026-01']));

        $data = $response->viewData('data');
        $this->assertSame([$joanna->id, $sharie->id], $data['shown']->pluck('id')->sort()->values()->all());
        $this->assertSame([66046.0, 292860.75, 800.0], [$data['cras'][$joanna->id]['shipping'], $data['cras'][$joanna->id]['net_income'], $data['cras'][$joanna->id]['aov']]);
        $this->assertSame(758698.72, $data['department']['net_income']);
        // A sheet-only line shows when a card has an amount on it.
        $labels = array_column($data['lines'], 0);
        $this->assertContains('VAT on COD Fee', $labels);
        $this->assertNotContains('AI Expense', $labels);
        $this->assertFalse($response->viewData('canEdit'));
    }
}
