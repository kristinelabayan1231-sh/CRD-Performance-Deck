<?php

namespace Tests\Feature;

use App\Models\CvrOrderEntry;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\PcReportEntry;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShecomProductCost;
use App\Models\User;
use App\Services\DailyIncomeStatement;
use App\Services\DailyIsConflicts;
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
        Product::create(['name' => 'Sinuxyl', 'unit_cost' => 35]);
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

    public function test_the_sidebar_links_daily_is_for_those_who_can_view_it(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $link = 'href="'.route('daily-is.index').'"';

        $this->actingAs($supervisor)->get(route('dashboard'))->assertOk()->assertSee($link, false);
    }

    public function test_supervisor_sees_each_cras_statement_per_product_and_the_department_adding_them_up(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $julyAnn = $this->user('July Ann', Role::CRA, 'CRD July Ann');
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        // Shecom's costs by Pancake item: "3 Bottles Sinuxyl" was ₱90, ₱105 from Oct 1 and ₱200 from Oct 2; "1 Sinuxyl" has none.
        ShecomProductCost::create(['product' => '3 Bottles Sinuxyl', 'cost' => 90, 'effective_from' => null]);
        ShecomProductCost::create(['product' => '3 Bottles Sinuxyl', 'cost' => 105, 'effective_from' => '2026-10-01']);
        ShecomProductCost::create(['product' => '3 Bottles Sinuxyl', 'cost' => 200, 'effective_from' => '2026-10-02']);
        // Regina's Sinuxyl: two pages of PC Report counts; two confirmed orders (before Nov 3 Product Cost is Settings'
        // ₱35 unit cost a piece, not Shecom's or Pancake's: "3 Bottles Sinuxyl" 3 pieces, "1 Sinuxyl" 1) and a canceled one
        // (in Gross Sales and Actual Cancelled, not in orders); another day's is left out.
        $this->entry($regina, 'Sinuxyl Nasal Spray', 100, 50);
        $this->entry($regina, 'Sinuxyl Nasal Care', 20, 10);
        $this->order($regina, 'Sinuxyl Nasal Spray', 999, ['items' => [['qty' => 1, 'pieces' => 3, 'name' => '3 Bottles Sinuxyl', 'cost' => 100]]]);
        $this->order($regina, 'Sinuxyl Nasal Care', 500, ['items' => [['qty' => 1, 'name' => '1 Sinuxyl']]]);
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
            'delivered' => $delivered, 'tax' => $share('tax'), 'product_cost' => 140.0, 'gross_profit' => $delivered - $share('tax') - 140,
            'advertising' => 0.0, 'ads_vat' => 0.0, 'shipping' => 150, 'cod' => $delivered * 0.0224, 'vat_on_cod' => 0.0, 'fulfillment' => 50,
            'selling' => $selling, 'salaries' => $share('salaries'), 'hmo_expense' => $share('hmo_expense'), 'operating' => $operating,
            'net_income' => $delivered - $share('tax') - 140 - $selling - $operating,
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

    public function test_product_cost_leaves_out_tsd_add_ons_but_keeps_an_order_of_only_a_tsd_product(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        Product::create(['name' => 'Haplunas', 'is_tsd' => true, 'unit_cost' => 50]);
        // Sinuxyl 2 pieces with a Haplunas add-on: only Sinuxyl's 2 × ₱35. Haplunas alone: its ₱50.
        $this->order($regina, 'Sinuxyl Nasal Spray', 1300, ['items' => [['qty' => 1, 'pieces' => 2, 'name' => 'Sinuxyl'], ['qty' => 1, 'name' => 'Haplunas Balm']]]);
        $this->order($regina, 'Sinuxyl Nasal Spray', 500, ['items' => [['qty' => 1, 'name' => 'Haplunas Balm']], 'tsd_upsell' => true]);

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('daily-is.index', ['date' => self::DAY]))->viewData('data');

        $this->assertEqualsWithDelta(70 + 50, $data['cras'][$regina->id]['products']['sinuxyl']['product_cost'], 0.001);
    }

    public function test_a_card_counts_only_its_own_product_not_another_crd_product_on_the_order(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        Product::create(['name' => 'CanPro Guyabano Herbal Drink', 'unit_cost' => 200]);
        // A Sinuxyl order with a CanPro: Sinuxyl's card counts 2 × ₱35 only.
        $this->order($regina, 'Sinuxyl Nasal Spray', 1200, ['items' => [['qty' => 1, 'pieces' => 2, 'name' => 'Sinuxyl'], ['qty' => 1, 'name' => 'CanPro Guyabano Herbal Drink']]]);

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('daily-is.index', ['date' => self::DAY]))->viewData('data');

        $this->assertEqualsWithDelta(70, $data['cras'][$regina->id]['products']['sinuxyl']['product_cost'], 0.001);
    }

    public function test_a_day_with_product_costs_from_the_sheet_uses_them(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $julyAnn = $this->user('July Ann', Role::CRA, 'CRD July Ann');
        $this->order($regina, 'Sinuxyl Nasal Spray', 999, ['items' => [['qty' => 1, 'pieces' => 5, 'name' => 'Sinuxyl']]]);
        $this->order($julyAnn, 'Sinuxyl Nasal Spray', 499, ['items' => [['qty' => 1, 'name' => 'Sinuxyl']]]);
        Setting::put(DailyIncomeStatement::PRODUCT_COST_OVERRIDES_KEY, json_encode([self::DAY => ['Regina' => ['sinuxyl' => 123]]]));

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('daily-is.index', ['date' => self::DAY]))->viewData('data');

        // Regina's Sinuxyl is the sheet's ₱123 (not 5 × ₱35); July Ann isn't on the sheet that day, so ₱0.
        $this->assertEqualsWithDelta(123, $data['cras'][$regina->id]['products']['sinuxyl']['product_cost'], 0.001);
        $this->assertEqualsWithDelta(0, $data['cras'][$julyAnn->id]['products']['sinuxyl']['product_cost'], 0.001);
    }

    public function test_from_nov_3_product_cost_is_shecoms_current_cost_and_zero_without_one(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'Asia/Manila'));
        ShecomProductCost::create(['product' => '3 Bottles Sinuxyl', 'cost' => 30, 'effective_from' => '2026-11-04']);
        foreach (['2026-10-30', '2026-11-03'] as $day) {
            $this->order($regina, 'Sinuxyl Nasal Spray', 999, ['ordered_on' => $day, 'items' => [['qty' => 1, 'pieces' => 3, 'name' => '3 Bottles Sinuxyl'], ['qty' => 1, 'name' => '1 Sinuxyl']]]);
        }

        $cost = fn (string $day) => $this->actingAs($supervisor)->get(route('daily-is.index', ['date' => $day]))->viewData('data')['cras'][$regina->id]['products']['sinuxyl']['product_cost'];

        // Oct 30: Settings' ₱35 × 4 pieces. Nov 3: Shecom's ₱30 × 3 (dated Nov 4, it still counts), ₱0 for "1 Sinuxyl".
        $this->assertEqualsWithDelta(140, $cost('2026-10-30'), 0.001);
        $this->assertEqualsWithDelta(90, $cost('2026-11-03'), 0.001);
        // A cost added later for "1 Sinuxyl" shows on Nov 3 too.
        ShecomProductCost::create(['product' => '1 Sinuxyl', 'cost' => 30, 'effective_from' => '2026-11-05']);
        $this->assertEqualsWithDelta(120, $cost('2026-11-03'), 0.001);
    }

    public function test_settings_lists_shecoms_costs_for_crd_and_tsd_products_only_and_the_sold_items_without_one(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        Product::create(['name' => 'Haplunas', 'is_tsd' => true]);
        ShecomProductCost::create(['product' => '1 Bottle Sinuxyl', 'cost' => 30, 'effective_from' => '2026-10-10']);
        ShecomProductCost::create(['product' => 'Haplunas Balm', 'cost' => 35, 'effective_from' => null]);
        ShecomProductCost::create(['product' => 'Fungi Clear', 'cost' => 42, 'effective_from' => null]);
        // Sold lately: "1 Bottle Sinuxyl" has a cost; "Sinuxyl" (5 pieces × 2) has none. An older sale isn't listed.
        $this->order($regina, 'Sinuxyl Nasal Spray', 999, ['ordered_on' => '2026-10-15', 'items' => [['qty' => 2, 'pieces' => 5, 'name' => 'Sinuxyl'], ['qty' => 1, 'name' => '1 Bottle Sinuxyl']]]);
        $this->order($regina, 'Sinuxyl Nasal Spray', 500, ['ordered_on' => '2026-09-20', 'items' => [['qty' => 1, 'name' => 'Old Sinuxyl Bundle']]]);

        $view = $this->actingAs($this->user('Admin', Role::SUPER_ADMIN))->get(route('settings.daily-is.index'))
            ->assertOk()->assertSeeText('Product costs from Shecom')->assertDontSeeText('Fungi Clear');

        $this->assertSame([
            ['item' => 'Haplunas Balm', 'cost' => 35.0, 'from' => null, 'product' => 'Haplunas'],
            ['item' => '1 Bottle Sinuxyl', 'cost' => 30.0, 'from' => '2026-10-10', 'product' => 'Sinuxyl'],
        ], $view->viewData('costs'));
        $this->assertSame([['item' => 'Sinuxyl', 'product' => 'Sinuxyl', 'pieces' => 10]], $view->viewData('missingCosts'));
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

    public function test_an_order_the_cvr_tracker_counts_for_another_cra_goes_to_that_cra(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $julyAnn = $this->user('July Ann', Role::CRA, 'CRD July Ann');
        $other = User::create(['email' => 'anna@example.com', 'pancake_name' => 'CRD Anna', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $this->order($regina, 'Sinuxyl Nasal Spray', 1000, ['conversion_type' => PancakeOrder::BROADCAST]);
        $this->order($regina, 'Sinuxyl Nasal Spray', 700, ['conversion_type' => PancakeOrder::BROADCAST]);
        $this->order($other, 'Sinuxyl Nasal Spray', 500, ['conversion_type' => PancakeOrder::SEGMENTATION]);
        // Regina's ₱700 is July Ann's in the CVR Tracker; the ₱500 made under a non-CRA account is Regina's.
        CvrOrderEntry::create(['pancake_order_id' => '1374002', 'user_id' => $julyAnn->id]);
        CvrOrderEntry::create(['pancake_order_id' => '1374003', 'user_id' => $regina->id]);

        $cras = app(DailyIncomeStatement::class)->day(LeadGenerator::cras(), CarbonImmutable::parse(self::DAY))['cras'];

        $this->assertSame([1500.0, 700.0], [$cras[$regina->id]['total']['gross'], $cras[$julyAnn->id]['total']['gross']]);
    }

    public function test_supervisors_see_orders_the_daily_is_and_report_summary_count_differently(): void
    {
        $regina = $this->user('Regina', Role::CRA, 'CRD Rej Vergara');
        $this->order($regina, 'Sinuxyl Nasal Spray', 1000, ['conversion_type' => PancakeOrder::BROADCAST]);   // in both
        $this->order($regina, 'Some Other Page', 800, ['conversion_type' => PancakeOrder::BROADCAST]);       // Report Summary only
        $this->order($regina, 'Sinuxyl Nasal Spray', 600);                                                    // Daily I.S. only

        $response = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('daily-is.index', ['date' => '2026-10-05']))->assertOk()
            ->assertSee('2 order conflicts');

        $conflicts = $response->viewData('conflicts')->map(fn (array $c) => [$c['type'], $c['order_id'], $c['note'], $c['amount']])->sortBy(1)->values()->all();
        $this->assertSame([
            [DailyIsConflicts::PAGE, '1374002', 'Not in a product group', 800.0],
            [DailyIsConflicts::UNTAGGED, '1374003', null, 600.0],
        ], $conflicts);
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

    public function test_each_row_has_a_screenshot_button_named_after_the_row_and_day(): void
    {
        $this->user('Regina', Role::CRA, 'CRD Rej Vergara');

        $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('daily-is.index', ['date' => self::DAY]))
            ->assertOk()
            ->assertSeeInOrder(['data-is-shot-file="Daily IS - Customer Retention Department - 2026-10-01.png"', 'data-is-shot-file="Daily IS - Regina - 2026-10-01.png"'], false);
    }

    public function test_cra_without_daily_is_permission_is_refused(): void
    {
        $this->actingAs($this->user('Regina', Role::CRA, 'CRD Rej Vergara'))->get(route('daily-is.index'))->assertForbidden();
    }
}
