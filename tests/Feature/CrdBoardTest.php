<?php

namespace Tests\Feature;

use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\DailyIncomeStatement;
use App\Services\LeadGenerator;
use App\Support\ProductGroups;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrdBoardTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Manila'));
        Product::create(['name' => 'Sinuxyl', 'unit_cost' => 35]);
        ProductGroups::save([['name' => 'Sinuxyl', 'pages' => ['Sinuxyl Nasal Spray']], ['name' => 'ClearSight', 'pages' => ['Clear Sight Hub']]]);
    }

    private function user(string $name, string $role, ?string $pancake = null): User
    {
        return User::create([
            'email' => str_replace(' ', '', strtolower($name)).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true,
        ]);
    }

    private function order(User $cra, string $day, string $page, float $amount): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => (string) (1375000 + $this->seq), 'ordered_on' => $day, 'ordered_at' => $day.' 02:00:00',
            'seller_name' => strtoupper($cra->pancake_name), 'customer_name' => 'Buyer', 'status' => 3, 'total_price' => $amount,
            'page_name' => $page, 'tags' => [], 'non_crd' => false,
        ]);
    }

    public function test_board_shows_the_daily_is_for_today_and_the_two_days_before_with_yesterdays_top_seller_and_product(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $julyAnn = $this->user('July Ann', Role::CRA, 'CRD July Ann');
        $this->order($regina, '2026-10-14', 'Sinuxyl Nasal Spray', 500);
        $this->order($regina, '2026-10-15', 'Sinuxyl Nasal Spray', 2000);
        $this->order($julyAnn, '2026-10-15', 'Clear Sight Hub', 300);
        $this->order($julyAnn, '2026-10-16', 'Clear Sight Hub', 700);

        $board = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('dashboard.board'))
            ->assertOk()
            ->assertSeeInOrder(['Running sales today', 'Wed, Oct 14', 'Thu, Oct 15', 'Top seller · Oct 15', 'Regina', 'Top product · Oct 15', 'SINUXYL NASAL SPRAY'])
            ->viewData('today');

        // Gross Sales and Net Income are the Daily I.S. department totals for each day.
        $statement = fn (string $day) => app(DailyIncomeStatement::class)->day(LeadGenerator::cras(), CarbonImmutable::parse($day))['department']['total'];
        $this->assertEqualsWithDelta(700, $board['gross'], 0.001);
        $this->assertEqualsWithDelta($statement('2026-10-16')['net_income'], $board['net'], 0.001);
        $days = $this->actingAs($this->user('Sup 2', Role::CRA_SUPERVISOR))->get(route('dashboard.board'))->viewData('days');
        $this->assertEqualsWithDelta(500, $days[0]['gross'], 0.001);
        $this->assertEqualsWithDelta($statement('2026-10-15')['net_income'], $days[1]['net'], 0.001);
    }

    public function test_board_says_so_when_yesterday_had_no_sales(): void
    {
        $this->user('Regina', Role::CRA, 'CRD Rej Vergara');

        $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('dashboard.board'))
            ->assertOk()
            ->assertSeeText('No sales on Oct 15.')
            ->assertSee('data-board-file="CRD Board - 2026-10-16.png"', false);
    }

    public function test_only_users_who_see_every_cras_daily_is_get_the_board(): void
    {
        $cra = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');

        $this->actingAs($cra)->get(route('dashboard.board'))->assertForbidden();
        $this->actingAs($cra)->get(route('dashboard'))->assertOk()->assertDontSee('data-poster-open', false);
        $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('dashboard'))->assertOk()->assertSee('data-poster-open', false);
    }
}
