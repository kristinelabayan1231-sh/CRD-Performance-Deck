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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrdBoardTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** The Drive "Product Photos" folder's files for the faked Drive: name => [file id, bytes]. */
    private array $driveFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'embeddedfolderview')) {
                return Http::response(collect($this->driveFiles)->map(fn (array $file, string $name) => '<div class="flip-entry" id="entry-'.$file[0].'"><div class="flip-entry-title">'.$name.'</div></div>')->implode(''));
            }
            $file = collect($this->driveFiles)->firstWhere(0, $request->data()['id'] ?? null);

            return $file ? Http::response($file[1], 200, ['Content-Type' => 'image/jpeg']) : Http::response('', 200);
        });
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

    public function test_top_product_shows_its_photo_from_the_drive_folder_matched_by_file_name(): void
    {
        $this->driveFiles = ['Sinuxyl.jpg' => ['file-sinuxyl', 'sinuxyl-photo'], 'Notes.txt' => ['file-notes', 'text']];
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $this->order($regina, '2026-10-15', 'Sinuxyl Nasal Spray', 2000);
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->get(route('dashboard.board'))->assertOk()->assertSee(route('product-photos.show', 'sinuxyl'));

        $photo = $this->actingAs($supervisor)->get(route('product-photos.show', 'sinuxyl'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('sinuxyl-photo', $photo->getContent());
        // A product with no photo in the folder: none.
        $this->actingAs($supervisor)->get(route('product-photos.show', 'clearsight'))->assertNotFound();
    }

    public function test_yesterday_shows_yesterday_as_a_whole_day_with_its_own_top_seller(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $julyAnn = $this->user('July Ann', Role::CRA, 'CRD July Ann');
        $this->order($julyAnn, '2026-10-14', 'Clear Sight Hub', 900);
        $this->order($regina, '2026-10-15', 'Sinuxyl Nasal Spray', 2000);
        $this->order($julyAnn, '2026-10-16', 'Clear Sight Hub', 700);

        $response = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('dashboard.board', ['day' => 'yesterday']))
            ->assertOk()
            ->assertSeeInOrder(['Sales yesterday · Thu, Oct 15, whole day', 'Tue, Oct 13', 'Wed, Oct 14', 'Top seller · Oct 15', 'Regina'])
            ->assertDontSee('Running sales today');

        // Oct 15 is the big figure; today's (Oct 16) sale isn't on the board.
        $this->assertEqualsWithDelta(2000, $response->viewData('today')['gross'], 0.001);
        $this->assertEqualsWithDelta(900, $response->viewData('days')[1]['gross'], 0.001);
        $this->assertSame('2026-10-15', $response->viewData('today')['day']->toDateString());
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
