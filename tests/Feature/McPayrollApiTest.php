<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\MonthlyCostRow;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McPayrollApiTest extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY = '/api/external/v1/crd/mc/summary';

    private const ROSTER = '/api/external/v1/crd/mc/roster';

    private string $mcKey;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Asia/Manila'));
        $this->mcKey = ApiKey::issue('Sales report', scope: ApiKey::MC)[1];
        MonthlyCostRow::factory()->fixed(20000)->create(['position' => 'CRA', 'name' => 'Ana Cruz', 'sort_order' => 1]);
        MonthlyCostRow::factory()->fixed(18000)->create(['position' => 'CRA', 'name' => 'Ben Reyes', 'sort_order' => 2]);
        MonthlyCostRow::factory()->vacant()->create(['position' => 'CRA', 'sort_order' => 3]);
    }

    private function fetch(string $path, string $month = '2026-11', ?string $key = null): TestResponse
    {
        return $this->withToken($key ?? $this->mcKey)->getJson("{$path}?month={$month}");
    }

    public function test_only_an_mc_key_opens_payroll_and_an_mc_key_opens_nothing_else(): void
    {
        $retentionKey = ApiKey::issue('Finance')[1];

        $this->getJson(self::SUMMARY.'?month=2026-11')->assertUnauthorized();
        $this->fetch(self::SUMMARY, key: $retentionKey)->assertForbidden();
        $this->fetch(self::ROSTER, key: $retentionKey)->assertForbidden();
        $this->withToken($this->mcKey)->getJson('/api/external/v1/retention/daily?from=2026-11-01&to=2026-11-01')->assertForbidden();
        $this->fetch(self::SUMMARY)->assertOk();
    }

    public function test_the_summary_matches_crd_mc_with_crds_share_of_the_shared_salaries(): void
    {
        // Defaults: Sales Manager 60,000 and CEO 132,300 at 33.33% (19,998 + 44,095.59); VP 0. Gov. 2,900.80 a person.
        $this->fetch(self::SUMMARY)->assertOk()->assertExactJson([
            'data' => [
                'month' => '2026-11',
                'headcount' => 2,
                'salaries' => 102093.59,
                'thirteenthMonth' => 8507.8,
                'sil' => 3914.13,
                'govBenefits' => 5801.6,
                'govBreakdown' => ['phic' => 1245.4, 'hdmf' => 800, 'sss' => 3756.2],
                'updatedAt' => '2026-11-10T10:00:00+08:00',
            ],
            'meta' => ['department' => 'crd', 'timezone' => 'Asia/Manila', 'currency' => 'PHP', 'generatedAt' => '2026-11-10T10:00:00+08:00'],
        ]);
    }

    public function test_the_roster_lists_people_then_shared_salaries_and_nothing_personal(): void
    {
        $employees = $this->fetch(self::ROSTER)->assertOk()->json('data.employees');

        $this->assertSame(['Ana Cruz', 'Ben Reyes', null, null], array_column($employees, 'name'));
        $this->assertSame(['CRA', 'CRA', 'Sales Manager', 'CEO'], array_column($employees, 'position'));
        $this->assertSame([20000, 18000, 60000, 132300], array_column($employees, 'monthlySalary'));
        $this->assertSame([100, 100, 33.33, 33.33], array_column($employees, 'allocationPct'));
        $this->assertSame(['crd team', 'crd team', 'leadership', 'leadership'], array_column($employees, 'group'));
        $this->assertSame(['position', 'name', 'group', 'monthlySalary', 'allocationPct', 'status', 'startDate', 'endDate'], array_keys($employees[0]));
    }

    public function test_the_current_month_follows_crd_mc_and_a_past_month_keeps_what_it_had(): void
    {
        $this->fetch(self::SUMMARY)->assertJsonPath('data.headcount', 2);

        // An edit in CRD - MC is saved as this month's figures, and updatedAt moves.
        $this->travel(2)->hours();
        $this->actingAs(User::create(['email' => 'admin@example.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]))
            ->post(route('monthly-cost.store'), ['position' => 'Supervisor', 'name' => 'Cora Lim', 'salary' => 30000]);
        $this->assertDatabaseHas('monthly_cost_snapshots', ['month' => '2026-11']);
        $this->fetch(self::SUMMARY)->assertJsonPath('data.headcount', 3)->assertJsonPath('data.updatedAt', '2026-11-10T12:00:00+08:00');

        // December: November stays as it ended, even after CRD - MC changes again.
        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00', 'Asia/Manila'));
        MonthlyCostRow::where('name', 'Ana Cruz')->delete();
        $this->artisan('mc:snapshot')->assertSuccessful();

        $this->fetch(self::SUMMARY, '2026-11')->assertJsonPath('data.headcount', 3)->assertJsonPath('data.updatedAt', '2026-11-10T12:00:00+08:00');
        $this->fetch(self::SUMMARY, '2026-12')->assertJsonPath('data.headcount', 2);
        $this->assertSame(['Ben Reyes', 'Cora Lim'], array_values(array_filter(array_column($this->fetch(self::ROSTER, '2026-12')->json('data.employees'), 'name'))));
    }

    public function test_a_month_never_saved_or_not_started_is_404_and_a_bad_month_is_422(): void
    {
        $this->fetch(self::SUMMARY, '2026-10')->assertNotFound()->assertJsonPath('message', 'No figures were saved for that month: the API keeps months from when it was turned on.');
        $this->fetch(self::ROSTER, '2026-12')->assertNotFound()->assertJsonPath('message', 'That month has not started yet.');
        $this->fetch(self::SUMMARY, 'November')->assertJsonValidationErrors('month');
    }

    public function test_settings_makes_an_mc_key_and_lists_what_each_key_reads(): void
    {
        $admin = User::create(['email' => 'admin@example.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);

        $this->actingAs($admin)->post(route('settings.api-keys.store'), ['name' => 'Finance MC', 'scope' => ApiKey::MC])
            ->assertSessionHas('new_key', fn (array $key) => $key['scope'] === ApiKey::SCOPES[ApiKey::MC]);
        $this->actingAs($admin)->post(route('settings.api-keys.store'), ['name' => 'Bad', 'scope' => 'everything'])->assertSessionHasErrors('scope');

        $this->assertSame(ApiKey::MC, ApiKey::firstWhere('name', 'Finance MC')->scope);
        $this->actingAs($admin)->get(route('settings.api-keys.index'))->assertOk()
            ->assertSee('MC / salaries')->assertSee('/api/external/v1/crd/mc/summary')->assertSee('Joining or leaving mid-month');
    }
}
