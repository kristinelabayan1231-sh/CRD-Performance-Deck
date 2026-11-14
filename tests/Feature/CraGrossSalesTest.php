<?php

namespace Tests\Feature;

use App\Models\ActualIncomeInput;
use App\Models\ActualIncomeStatement;
use App\Models\CraGrossSale;
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

    private function sale(string $month, User $cra, ?float $gross, ?float $net): void
    {
        CraGrossSale::create(['month' => $month, 'user_id' => $cra->id, 'gross' => $gross, 'net' => $net]);
    }

    public function test_each_month_shows_the_figures_typed_for_the_cra(): void
    {
        $joanna = $this->user('Joanna Rose', Role::CRA);
        $regina = $this->user('Regina', Role::CRA);
        $this->sale('2026-01-01', $joanna, 733282, 292860.75);
        $this->sale('2026-01-01', $regina, 689904, 100000);
        $this->sale('2026-07-01', $joanna, 606647, -5000);
        // Another year is left out.
        $this->sale('2025-01-01', $joanna, 999999, 1);

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('cra-gross-sales.index', ['year' => 2026]))
            ->assertSee('CRA Gross Sales &amp; IBSC', false)
            ->viewData('data');

        $joannaMonths = $data['cras'][$joanna->id]['months'];
        $this->assertSame(['gross' => 733282.0, 'net' => 292860.75], $joannaMonths[0]);
        $this->assertSame(['gross' => 606647.0, 'net' => -5000.0], $joannaMonths[6]);
        $this->assertNull($joannaMonths[1]);
        $this->assertNull($data['cras'][$regina->id]['months'][6]);
        $this->assertNull($data['totals'][11]);
        $this->assertSame(['gross' => 1339929.0, 'net' => 287860.75], $data['cras'][$joanna->id]['year']);
        $this->assertSame(['gross' => 1423186.0, 'net' => 392860.75], $data['totals'][0]);
        $this->assertSame(2029833.0, $data['year']['gross']);
    }

    public function test_a_cra_who_left_shows_for_the_months_they_have_figures(): void
    {
        $this->user('Joanna Rose', Role::CRA);
        $sharie = $this->user('Sharie', Role::CRA);
        $sharie->update(['is_active' => false]);
        $this->sale('2026-06-01', $sharie, 116783, -85261.84);

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
        $this->sale('2026-02-01', $joanna, 601167, 300000);
        $this->sale('2026-02-01', $regina, 677181, 100000);

        $response = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('cra-gross-sales.index', ['year' => 2026]));

        $this->assertSame($regina->id, $response->viewData('data')['top'][1]);
        $this->assertNull($response->viewData('data')['top'][0]);
        $this->assertSame(250000.0, $response->viewData('goal'));
    }

    public function test_running_acb_is_the_counted_net_income_times_1_4_percent(): void
    {
        $joanna = $this->user('Joanna Rose', Role::CRA);
        $regina = $this->user('Regina', Role::CRA);
        // January–May: only Net Income below 250k counts (February's 292,860.75 and May's 250,000 don't).
        $this->sale('2026-01-01', $joanna, 700000, 200000);
        $this->sale('2026-02-01', $joanna, 700000, 292860.75);
        $this->sale('2026-03-01', $joanna, 700000, -50000);
        $this->sale('2026-05-01', $joanna, 700000, 250000);
        // June on: every month counts, 250k and up too.
        $this->sale('2026-06-01', $joanna, 700000, 300000);
        $this->sale('2026-08-01', $joanna, 700000, -67607.86);

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('cra-gross-sales.index', ['year' => 2026]))
            ->assertSee('Running ACB')
            ->viewData('data');

        $this->assertSame(round((200000 - 50000 + 300000 - 67607.86) * 0.014, 2), $data['acb'][$joanna->id]);
        // A CRA with nothing typed has none; TOTAL adds up the CRAs.
        $this->assertNull($data['acb'][$regina->id]);
        $this->assertSame($data['acb'][$joanna->id], $data['acbTotal']);
    }

    public function test_the_figures_are_copied_once_from_actual_income_and_no_longer_follow_it(): void
    {
        $joanna = $this->user('Joanna Rose', Role::CRA);
        $sharie = $this->user('Sharie', Role::CRA);
        $sharie->update(['is_active' => false]);
        ActualIncomeInput::create(['month' => '2026-10-01', 'user_id' => $joanna->id, 'field' => 'gross', 'amount' => 500000]);
        ActualIncomeInput::create(['month' => '2026-10-01', 'user_id' => $joanna->id, 'field' => 'delivered', 'amount' => 400000]);
        ActualIncomeStatement::create(['month' => '2026-06-01', 'user_id' => $sharie->id, 'figures' => ['gross' => 116783, 'net_income' => -85261.84]]);

        (require database_path('migrations/2026_11_14_045125_copy_cra_gross_sales_from_actual_income.php'))->up();

        $october = app(ActualIncome::class)->month(collect([$joanna]), CarbonImmutable::parse('2026-10-01'))['cras'][$joanna->id];
        $this->assertDatabaseCount('cra_gross_sales', 2);
        $this->assertEqualsWithDelta($october['net_income'], CraGrossSale::where('user_id', $joanna->id)->value('net'), 0.01);
        $this->assertSame(-85261.84, CraGrossSale::where('user_id', $sharie->id)->value('net'));

        // A later Actual Income change leaves the page alone.
        ActualIncomeInput::where('field', 'gross')->update(['amount' => 1]);
        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('cra-gross-sales.index', ['year' => 2026]))->viewData('data');
        $this->assertSame(500000.0, $data['cras'][$joanna->id]['months'][9]['gross']);
    }

    public function test_a_supervisor_edits_the_figures_in_edit_mode(): void
    {
        $joanna = $this->user('Joanna Rose', Role::CRA);
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->get(route('cra-gross-sales.index', ['year' => 2026]))
            ->assertViewHas('canEdit', false)->assertSee('>Edit</a>', false)->assertDontSee('data-cra-gross-sales-input', false);
        $this->get(route('cra-gross-sales.index', ['year' => 2026, 'edit' => 1]))
            ->assertViewHas('canEdit', true)->assertSee('data-cra-gross-sales-input', false);

        $put = fn (string $field, $value) => $this->actingAs($supervisor)
            ->putJson(route('cra-gross-sales.update'), ['month' => '2026-03', 'cra' => $joanna->id, 'field' => $field, 'value' => $value]);
        $put('gross', 700000)->assertOk();
        $put('net', -1200.5)->assertOk();
        $put('gross', 710000)->assertOk();

        $this->assertDatabaseCount('cra_gross_sales', 1);
        $sale = CraGrossSale::first();
        $this->assertSame([710000.0, -1200.5, $supervisor->id], [$sale->gross, $sale->net, $sale->updated_by]);

        // Emptying both clears the month.
        $put('gross', null)->assertOk();
        $put('net', null)->assertOk();
        $this->assertDatabaseCount('cra_gross_sales', 0);

        $put('orders', 1)->assertUnprocessable();
    }

    public function test_a_cra_cannot_see_or_edit_it(): void
    {
        $joanna = $this->user('Joanna Rose', Role::CRA);

        $this->actingAs($joanna)->get(route('cra-gross-sales.index'))->assertForbidden();
        $this->actingAs($joanna)->putJson(route('cra-gross-sales.update'), ['month' => '2026-03', 'cra' => $joanna->id, 'field' => 'gross', 'value' => 1])
            ->assertForbidden();
        $this->assertDatabaseCount('cra_gross_sales', 0);
    }
}
