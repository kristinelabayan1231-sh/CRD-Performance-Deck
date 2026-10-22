<?php

namespace Tests\Feature;

use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\PcReportEntry;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\DailyIncomeStatement;
use App\Services\LeadGenerator;
use App\Services\MonthlyMni;
use App\Support\ProductGroups;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyIncomeStatementTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-10-01';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Manila'));
        Product::create(['name' => 'Sinuxyl']);
    }

    private function user(string $name, string $role, ?string $pancake = null): User
    {
        return User::create([
            'email' => str_replace(' ', '', strtolower($name)).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true,
        ]);
    }

    private function order(User $cra, string $page, float $amount, array $extra = []): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => (string) (1374000 + $this->seq), 'ordered_on' => self::DAY, 'ordered_at' => '2026-10-01 02:00:00',
            'seller_name' => strtoupper($cra->pancake_name), 'customer_name' => 'Buyer', 'status' => 3, 'total_price' => $amount,
            'page_name' => $page, 'tags' => [], 'non_crd' => false, ...$extra,
        ]);
    }

    private function entry(User $cra, string $page, int $inquiries, int $engagements): void
    {
        PcReportEntry::create(['user_id' => $cra->id, 'page' => $page, 'category' => '', 'date' => self::DAY, 'inquiries' => $inquiries, 'engagements' => $engagements]);
    }

    public function test_supervisor_sees_each_cras_statement_per_product_and_the_department_adding_them_up(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $julyAnn = $this->user('July Ann', Role::CRA, 'CRD July Ann');
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        // Regina's Sinuxyl: two pages of PC Report counts; two confirmed orders (one with a ₱100 cost) and a canceled one
        // (in Gross Sales and Actual Cancelled, not in orders); another day's is left out.
        $this->entry($regina, 'Sinuxyl Nasal Spray', 100, 50);
        $this->entry($regina, 'Sinuxyl Nasal Care', 20, 10);
        $this->order($regina, 'Sinuxyl Nasal Spray', 999, ['items' => [['qty' => 1, 'name' => 'Sinuxyl', 'cost' => 100]]]);
        $this->order($regina, 'Sinuxyl Nasal Care', 500);
        $this->order($regina, 'Sinuxyl Nasal Spray', 700, ['status' => PancakeOrder::CANCELED, 'pancake_order_id' => 'canceled-1']);
        $this->order($regina, 'Sinuxyl Nasal Spray', 800, ['ordered_on' => '2026-10-02']);
        // July Ann's ClearSight.
        $this->entry($julyAnn, 'Clear Sight Hub', 40, 20);
        $this->order($julyAnn, 'Clear Sight Hub', 800);
        // October's % Projected Returns: 10%, for every product card.
        $this->actingAs($supervisor)->put(route('daily-is.returns.update'), ['month' => '2026-10', 'returns_pct' => 10])->assertRedirect();

        $show = fn () => $this->actingAs($supervisor)->get(route('daily-is.index', ['date' => self::DAY]))
            ->assertOk()->assertSee('Customer Retention Department')->assertSee('NET INCOME')->viewData('data');
        $data = $show();

        // Tax Allocation and operating costs: Regina's 1M MNI for October ÷ 24 ÷ the 2 products.
        $mni = app(MonthlyMni::class)->for(LeadGenerator::cras(), CarbonImmutable::parse('2026-10-01'), '1m')['cras'][$regina->id];
        $share = fn (string $key) => $mni[$key] / 24 / 2;
        $sinuxyl = $data['cras'][$regina->id]['products']['sinuxyl'];
        $delivered = 2199 - 700 - 219.9;
        $operating = array_sum(array_map($share, array_keys(DailyIncomeStatement::OPERATING)));
        $selling = 2 * 75 + $delivered * 0.0224 + 2 * 25;
        $expected = [
            'leads' => 120, 'engagements' => 60, 'orders' => 2, 'gross' => 2199.0, 'cancelled' => 700.0, 'returns' => 219.9,
            'delivered' => $delivered, 'tax' => $share('tax'), 'product_cost' => 100.0, 'gross_profit' => $delivered - $share('tax') - 100,
            'advertising' => 0.0, 'ads_vat' => 0.0, 'shipping' => 150, 'cod' => $delivered * 0.0224, 'vat_on_cod' => 0.0, 'fulfillment' => 50,
            'selling' => $selling, 'salaries' => $share('salaries'), 'hmo_expense' => $share('hmo_expense'), 'operating' => $operating,
            'net_income' => $delivered - $share('tax') - 100 - $selling - $operating,
        ];
        foreach ($expected as $key => $value) {
            $this->assertEqualsWithDelta($value, $sinuxyl[$key], 0.001, $key);
        }
        $this->assertGreaterThan(0, $share('salaries'));
        // Regina's card adds up her products: her operating costs are her MNI ÷ 24.
        $this->assertEqualsWithDelta($mni['salaries'] / 24, $data['cras'][$regina->id]['total']['salaries'], 0.001);
        $this->assertEqualsWithDelta(2199 + 800, $data['department']['total']['gross'], 0.001);
        $this->assertSame(3, $data['department']['total']['orders']);

        // The canceled order is resolved: Oct 1's Actual Cancelled drops to 0.
        PancakeOrder::where('pancake_order_id', 'canceled-1')->update(['status' => 3]);
        $this->assertEqualsWithDelta(0, $show()['cras'][$regina->id]['products']['sinuxyl']['cancelled'], 0.001);
    }

    public function test_a_product_group_added_today_counts_from_today_only(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        // Saved before (the first save keeps them as there from the start), then a third added on Oct 16.
        ProductGroups::save([['name' => 'Sinuxyl', 'pages' => ['Sinuxyl Nasal Spray']], ['name' => 'ClearSight', 'pages' => ['Clear Sight Hub']]]);
        ProductGroups::save([['name' => 'Sinuxyl', 'pages' => ['Sinuxyl Nasal Spray']], ['name' => 'clearsight', 'pages' => ['Clear Sight Hub']], ['name' => 'Ginseng', 'pages' => ['Ginseng Page']]]);

        $products = fn (string $date) => array_column($this->actingAs($supervisor)->get(route('daily-is.index', ['date' => $date]))->viewData('data')['products'], 'slug');
        $mni = app(MonthlyMni::class)->for(LeadGenerator::cras(), CarbonImmutable::parse('2026-10-01'), '1m')['cras'][$regina->id];

        $this->assertSame(['clearsight', 'sinuxyl'], $products('2026-10-15'));
        $this->assertSame(['clearsight', 'sinuxyl', 'ginseng'], $products('2026-10-16'));
        // Yesterday's per-product share stays ÷ 2; today's is ÷ 3.
        $salaries = fn (string $date) => $this->actingAs($supervisor)->get(route('daily-is.index', ['date' => $date]))->viewData('data')['cras'][$regina->id]['products']['sinuxyl']['salaries'];
        $this->assertEqualsWithDelta($mni['salaries'] / 24 / 2, $salaries('2026-10-15'), 0.001);
        $this->assertEqualsWithDelta($mni['salaries'] / 24 / 3, $salaries('2026-10-16'), 0.001);
        // PC Report doesn't list it on an earlier day either.
        $this->actingAs($supervisor)->get(route('pc-report.index', ['date' => '2026-10-15']))->assertDontSee('Ginseng');
    }

    public function test_product_cards_follow_the_set_order_with_full_names_whatever_order_settings_saved_them_in(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        // As saved in Settings live; "Other Thing" isn't in the order, so it comes last.
        $names = ['Sinuxyl', 'Other Thing', 'ClearSight', 'Canpro', 'Audi', 'Ginseng', 'Pterygium', 'ScarCream', 'Nutrilay', 'Medallion', 'Rose Soap', 'Cystora', 'Prime Man Herbal Tea', 'Gallvex'];
        ProductGroups::save(array_map(fn (string $name) => ['name' => $name, 'pages' => [$name.' Page']], $names));

        $products = $this->actingAs($supervisor)->get(route('daily-is.index', ['date' => '2026-10-15']))->viewData('data')['products'];

        // Each card shows the product's full name; one not in the list keeps its group name.
        $this->assertSame(['CLEAR SIGHT EYEDROPS', 'PTERYGIUM EYE DROPS', 'SINUXYL NASAL SPRAY', 'CANPRO HERBAL DRINK', 'AUDICURE', 'GINSENG', 'SCAR CREAM',
            'NUTRILAY', 'GALLVEX HERBAL DROPS', 'PRIME MAN HERBAL TEA', 'CYSTORA', 'ROSE SOAP', 'MEDALLION', 'Other Thing'], array_column($products, 'name'));
    }

    public function test_a_deactivated_product_shows_only_on_a_day_it_has_sales_with_a_label(): void
    {
        $admin = $this->user('Admin', Role::SUPER_ADMIN);
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $lhea = $this->user('Lhea', Role::CRA, 'CRD Lhei');
        ProductGroups::save([['name' => 'Sinuxyl', 'pages' => ['Sinuxyl Nasal Spray']], ['name' => 'ClearSight', 'pages' => ['Clear Sight Hub']], ['name' => 'Ginseng', 'pages' => ['Ginseng Page']]]);
        $this->order($regina, 'Sinuxyl Nasal Spray', 1500);

        $this->actingAs($admin)->put(route('settings.daily-is.update'), ['deactivated' => ['Sinuxyl', 'ClearSight']])
            ->assertRedirect()->assertSessionHas('status', 'Daily I.S. products saved.');
        $products = fn (array $query) => $this->actingAs($supervisor)->get(route('daily-is.index', $query))->viewData('data')['products'];

        // Oct 1: Sinuxyl sold, so it shows, labelled; ClearSight didn't, so it's gone; Ginseng is active and shows as usual.
        $expected = [
            ['slug' => 'sinuxyl', 'name' => 'SINUXYL NASAL SPRAY', 'deactivated' => true],
            ['slug' => 'ginseng', 'name' => 'GINSENG', 'deactivated' => false],
        ];
        $this->assertSame($expected, $products(['date' => self::DAY]));
        $this->actingAs($supervisor)->get(route('daily-is.index', ['date' => self::DAY]))->assertSeeTextInOrder(['SINUXYL NASAL SPRAY', 'Deactivated']);
        // Any CRA's sales count, also when looking at a CRA who sold none.
        $this->assertSame($expected, $products(['date' => self::DAY, 'cra' => $lhea->id]));
        // Oct 2: no sales, so only Ginseng.
        $this->assertSame(['ginseng'], array_column($products(['date' => '2026-10-02']), 'slug'));
    }

    public function test_only_users_with_the_daily_is_products_permission_can_deactivate_products(): void
    {
        $admin = $this->user('Admin', Role::SUPER_ADMIN);
        ProductGroups::save([['name' => 'Sinuxyl', 'pages' => ['Sinuxyl Nasal Spray']]]);

        $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->put(route('settings.daily-is.update'), ['deactivated' => ['Sinuxyl']])->assertForbidden();
        $this->actingAs($admin)->put(route('settings.daily-is.update'), ['deactivated' => ['Not A Group']])->assertSessionHasErrors('deactivated.0');
        $this->actingAs($admin)->get(route('settings.daily-is.index'))->assertOk()->assertSeeText('SINUXYL NASAL SPRAY');
        $this->assertSame([], DailyIncomeStatement::deactivated());
    }

    public function test_a_page_added_today_counts_from_today_only(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        ProductGroups::save([['name' => 'Sinuxyl', 'pages' => ['Sinuxyl Nasal Spray', 'Sinuxyl New Page']]]);
        PancakePage::create(['name' => 'Sinuxyl Nasal Spray', 'page_id' => '1', 'access_token' => 'token-1']);
        PancakePage::create(['name' => 'Sinuxyl New Page', 'page_id' => '2', 'access_token' => 'token-2', 'counts_from' => '2026-10-16']);
        foreach (['2026-10-15', '2026-10-16'] as $day) {
            $this->order($regina, 'Sinuxyl New Page', 500, ['ordered_on' => $day, 'page_id' => '2']);
        }

        $orders = fn (string $date) => $this->actingAs($supervisor)->get(route('daily-is.index', ['date' => $date]))->viewData('data')['cras'][$regina->id]['products']['sinuxyl']['orders'];

        $this->assertSame(0, $orders('2026-10-15'));
        $this->assertSame(1, $orders('2026-10-16'));
    }

    public function test_only_supervisors_set_the_months_projected_returns(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');

        $this->actingAs($regina)->put(route('daily-is.returns.update'), ['month' => '2026-10', 'returns_pct' => 10])->assertForbidden();
        $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->put(route('daily-is.returns.update'), ['month' => '2026-10', 'returns_pct' => 101])
            ->assertSessionHasErrors('returns_pct');
        $this->assertNull(DailyIncomeStatement::returnsPct(CarbonImmutable::parse('2026-10-01')));
    }

    public function test_supervisor_can_narrow_to_one_cra(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $julyAnn = $this->user('July Ann', Role::CRA, 'CRD July Ann');

        $response = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('daily-is.index', ['date' => self::DAY, 'cra' => $julyAnn->id]))
            ->assertOk()
            ->assertDontSee('Customer Retention Department');

        $this->assertSame([$julyAnn->id], array_keys($response->viewData('data')['cras']));
    }

    public function test_cra_without_daily_is_permission_is_refused(): void
    {
        $this->actingAs($this->user('Regina', Role::CRA, 'CRD Rej Vergara'))->get(route('daily-is.index'))->assertForbidden();
    }
}
