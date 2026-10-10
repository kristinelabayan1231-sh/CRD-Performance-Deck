<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillCustomerDatabase;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\LogisticsRetention;
use App\Services\PancakeSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00', 'Asia/Manila'));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        User::create(['email' => 'anna@gmail.com', 'role_id' => Role::where('slug', Role::CRA)->value('id'), 'is_active' => true, 'pancake_name' => 'Anna Cruz']);
        Product::create(['name' => 'Canpro', 'srp' => 499]);
    }

    /**
     * Ana: FSD order in September, then a CRD-delivered order today (her first by a CRA).
     * Ben: CRD-delivered in September, then today an order sold by CRA Anna Cruz, plus one waiting.
     * Cara: FSD only, today, not in Pancake. Dan: one CRD-delivered order in September.
     * Eve: yesterday, an FSD-listed order sold by a past CRA's CRD account (not a user here).
     */
    private function customers(): void
    {
        $this->delivered('A1', 'fsd', 'Ana Cruz', '09171111111', '2026-09-02', pos: [1000, 3, 'FSD AGENT', [['name' => 'Ginseng Serum', 'qty' => 1]]]);
        $this->delivered('A2', 'crd', 'Ana Cruz', '09171111111', '2026-10-09', pos: [1500, 3, 'SOMEONE ELSE', [['name' => 'CANPRO 60s', 'qty' => 2]]]);
        $this->delivered('B1', 'crd', 'Ben Reyes', '9172222222', '2026-09-10', pos: [800, 3, null, []]);
        $this->delivered('B2', 'fsd', 'Ben Reyes', '9172222222', '2026-10-09', pos: [900, 16, 'ANNA CRUZ', [['name' => 'Canpro', 'qty' => 30]]]);
        PancakeOrder::create(['pancake_order_id' => 'B3', 'ordered_on' => '2026-10-09', 'phone_key' => '9172222222', 'status' => 9, 'total_price' => 500, 'seller_name' => 'ANNA CRUZ']);
        $this->delivered('C1', 'fsd', 'Cara Diaz', '9173333333', '2026-10-09');
        $this->delivered('D1', 'crd', 'Dan Lim', '9174444444', '2026-09-15');
        $this->delivered('E1', 'fsd', 'Eve Santos', '9175555555', '2026-10-08', pos: [700, 3, 'CRD JULY DE LOS SANTOS', []]);
    }

    /**
     * @param  array{0: float, 1: int, 2: ?string, 3: list<array{name: string, qty: int}>}|null  $pos  total, status, seller, items
     */
    private function delivered(string $id, string $team, string $name, string $phone, string $date, ?array $pos = null): void
    {
        $key = substr(preg_replace('/\D/', '', $phone), -10);
        LogisticsOrder::remember([['order_id' => $id, 'team' => $team, 'customer_name' => $name, 'phone_number' => $phone,
            'product' => 'Canpro', 'qty' => null, 'delivered_date' => $date]]);

        if ($pos) {
            PancakeOrder::create(['pancake_order_id' => $id, 'ordered_on' => CarbonImmutable::parse($date)->subDays(3), 'phone_key' => $key,
                'total_price' => $pos[0], 'status' => $pos[1], 'seller_name' => $pos[2], 'items' => $pos[3]]);
        }
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $user = User::create(['email' => 'u@gmail.com', 'role_id' => Role::defaultUser()->id, 'is_active' => true]);

        $this->actingAs($user)->get(route('customers.index'))->assertForbidden();
        $this->actingAs($user)->get(route('customers.show', '9171111111'))->assertForbidden();
    }

    public function test_reading_logistics_saves_every_fsd_and_crd_delivered_order(): void
    {
        config(['services.shecom.key' => 'test-key']);
        Http::fake(['*retention-stockout*' => Http::response([
            'stock_outs' => [['order_id' => '1', 'customer_name' => 'Ana', 'phone_number' => '9171111111', 'product_name' => 'Canpro', 'qty' => 2, 'delivered_date' => '2026-10-01']],
            'repeat_detail' => [
                ['order_id' => '1', 'customer_name' => 'Ana', 'phone_number' => '9171111111', 'product' => 'Canpro', 'delivered_date' => '2026-10-01'],
                ['order_id' => '2', 'customer_name' => 'Ben', 'phone_number' => '09172222222', 'product' => 'Sinuxyl', 'delivered_date' => '2026-10-02'],
            ],
            'retention_detail' => [
                ['order_id' => '3', 'customer_name' => 'Cara', 'phone_number' => '+63 917 333 3333', 'product' => 'Pterygium', 'delivered_date' => '2026-10-03'],
                // Before customers.delivered_from (Jan 1): not kept.
                ['order_id' => '4', 'customer_name' => 'Dan', 'phone_number' => '9174444444', 'product' => 'Pterygium', 'delivered_date' => '2025-12-31'],
            ],
        ])]);

        app(LogisticsRetention::class)->refresh();

        $this->assertSame(
            [['1', 'crd', 2, '9171111111'], ['2', 'crd', null, '9172222222'], ['3', 'fsd', null, '9173333333']],
            LogisticsOrder::orderBy('order_id')->get()->map(fn ($o) => [$o->order_id, $o->team, $o->qty, $o->phone_key])->all(),
        );
    }

    public function test_list_shows_purchases_and_total_spent_with_crd_lead_counts_over_all_time(): void
    {
        $this->customers();

        $this->actingAs($this->owner)->get(route('customers.index'))
            ->assertOk()
            // Sorted by total spent: Ana ₱2,500, Ben ₱1,700, then Cara and Dan with no Pancake amount.
            ->assertSeeInOrder(['Ana Cruz', 'Retained', '09171111111', '₱2,500.00', 'Ben Reyes', 'Repeat Customer', '₱1,700.00'])
            ->assertSee('Cara Diaz')->assertSee('Dan Lim')
            ->assertViewHas('counts', ['all' => 5, 'crd' => 4, 'retained' => 3, 'repeat' => 1]);
    }

    public function test_pages_and_counts_are_kept_for_a_few_minutes_and_cleared_by_an_account_change(): void
    {
        // Serialize like the real (database) cache, which doesn't unserialize objects.
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        $this->customers();
        foreach ([1, 2] as $visit) {
            $this->actingAs($this->owner)->get(route('customers.index', ['segment' => 'repeat']))->assertOk()
                ->assertViewHas('customers', fn ($page) => $page->total() === 1 && $page->first()->customer_name === 'Ben Reyes');
        }

        // A new delivery doesn't show until the cache lapses or is cleared.
        $this->delivered('F1', 'crd', 'Fay Cruz', '9176666666', '2026-10-09');
        $this->actingAs($this->owner)->get(route('customers.index'))->assertViewHas('counts', fn ($counts) => $counts['all'] === 5);

        $this->actingAs($this->owner)->put(route('settings.pancake-accounts.update'), ['accounts' => config('customers.crd_accounts')]);
        $this->actingAs($this->owner)->get(route('customers.index'))
            ->assertViewHas('counts', fn ($counts) => $counts['all'] === 6)
            ->assertViewHas('customers', fn ($page) => $page->total() === 6);
    }

    public function test_dashboard_shows_retained_and_repeat_customers_for_the_month_to_date(): void
    {
        Http::fake(fn () => Http::response(['success' => true, 'count' => 0, 'stock_outs' => [], 'data' => [], 'users_engagements' => []]));
        $this->withoutDefer();
        $this->customers();

        // October to date: Ana and Eve's first CRA-handled orders, Ben's second (Dan's was in September).
        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeTextInOrder(['AOV', 'Retained', '2', 'Repeat customers', '1', 'Customer churn', 'Overall churn rate'])
            ->assertSee(route('customers.index', ['from' => '2026-10-01', 'to' => '2026-10-09', 'segment' => 'repeat']));

        $cra = User::firstWhere('email', 'anna@gmail.com');
        $this->actingAs($cra)->get(route('dashboard'))->assertOk()->assertDontSee('Repeat customers');
    }

    public function test_today_retained_is_a_first_cra_order_and_repeat_has_an_earlier_one(): void
    {
        $this->customers();

        $response = $this->actingAs($this->owner)->get(route('customers.index', ['period' => 'today']));
        $response->assertViewHas('counts', ['all' => 3, 'crd' => 2, 'retained' => 1, 'repeat' => 1]);

        $this->actingAs($this->owner)->get(route('customers.index', ['period' => 'today', 'segment' => 'retained']))
            ->assertSee('Ana Cruz')->assertDontSee('Ben Reyes')->assertDontSee('Dan Lim');
        $this->actingAs($this->owner)->get(route('customers.index', ['period' => 'today', 'segment' => 'repeat']))
            ->assertSee('Ben Reyes')->assertDontSee('Ana Cruz');
        $this->actingAs($this->owner)->get(route('customers.index', ['period' => 'today', 'segment' => 'fsd']))
            ->assertSee('Cara Diaz')->assertDontSee('Ana Cruz')->assertDontSee('Ben Reyes');
    }

    public function test_search_finds_a_customer_by_any_number_format_and_keeps_their_full_totals(): void
    {
        $this->customers();

        $this->actingAs($this->owner)->get(route('customers.index', ['search' => '+63 917 222']))
            ->assertSee('Ben Reyes')->assertSee('₱1,700.00')->assertDontSee('Ana Cruz');

        // Names match whatever the case.
        $this->actingAs($this->owner)->get(route('customers.index', ['search' => 'eve sant']))
            ->assertSee('Eve Santos')->assertDontSee('Ben Reyes');
    }

    public function test_profile_breaks_orders_down_by_status_and_marks_reached_cltv(): void
    {
        $this->customers();

        $this->actingAs($this->owner)->get(route('customers.show', '9172222222'))
            ->assertOk()
            ->assertSeeInOrder(['Ben Reyes', 'Repeat Customer'])
            ->assertSeeInOrder(['Waiting for pickup', '1', '₱500.00', 'Delivered', '1', '₱800.00', 'Payment collected', '1', '₱900.00'])
            // 30 Canpro × ₱499 = ₱14,970 = SRP × 30.
            ->assertSeeInOrder(['Canpro', '30', '₱14,970.00', '₱14,970.00', 'Reached CLTV']);

        $this->actingAs($this->owner)->get(route('customers.show', '9171111111'))
            ->assertSeeInOrder(['Canpro', '2', '₱998.00', '₱14,970.00', '₱13,972.00 to go'])
            ->assertDontSee('Reached CLTV');
    }

    public function test_profile_counts_logistics_orders_missing_from_pancake_as_delivered_without_amount(): void
    {
        $this->customers();

        $this->actingAs($this->owner)->get(route('customers.show', '9173333333'))
            ->assertOk()
            ->assertSeeInOrder(['Cara Diaz', 'FSD only', 'Delivered', '1 not in Pancake yet']);

        $this->actingAs($this->owner)->get(route('customers.show', '9999999999'))->assertNotFound();
    }

    public function test_pos_orders_are_saved_with_their_items(): void
    {
        config(['services.pancake.key' => 'pos-key', 'services.pancake.shop_id' => '1']);
        Http::fake(['*' => Http::response(['success' => true, 'total_pages' => 1, 'data' => [[
            'display_id' => 501, 'inserted_at' => '2026-10-08T02:00:00', 'status' => 3, 'total_price' => 998, 'bill_phone_number' => '09171111111',
            'items' => [['quantity' => 2, 'variation_info' => ['name' => 'CANPRO 60s', 'retail_price' => 499, 'images' => ['…']]]],
        ]]])]);

        $this->assertSame(1, app(PancakeSync::class)->syncOrders(CarbonImmutable::parse('2026-10-08')));
        $this->assertSame([['name' => 'CANPRO 60s', 'qty' => 2]], PancakeOrder::firstWhere('pancake_order_id', '501')->items);
    }

    public function test_date_range_counts_crd_accounts_from_the_list_as_handled_by_a_cra(): void
    {
        $this->customers();

        // Oct 8 only: Eve, whose order was sold by a past CRA's CRD account.
        $this->actingAs($this->owner)->get(route('customers.index', ['from' => '2026-10-08', 'to' => '2026-10-08']))
            ->assertOk()
            ->assertSee('Oct 8, 2026')
            ->assertSeeInOrder(['Eve Santos', 'Retained'])
            ->assertDontSee('Ana Cruz')
            ->assertViewHas('counts', ['all' => 1, 'crd' => 1, 'retained' => 1, 'repeat' => 0]);

        $this->actingAs($this->owner)->get(route('customers.index', ['from' => '2026-10-09', 'to' => '2026-10-08']))
            ->assertSessionHasErrors(['to' => 'The "to" date must be on or after the "from" date.']);
    }

    public function test_opening_a_customer_fetches_delivered_orders_pancake_has_not_backfilled(): void
    {
        config(['services.pancake.key' => 'pos-key', 'services.pancake.shop_id' => '1']);
        Http::fake(fn (Request $request) => Http::response(['success' => true, 'total_pages' => 1, 'data' => str_contains($request->url(), 'search=C1&')
            ? [['display_id' => 'C1', 'inserted_at' => '2026-10-06T02:00:00', 'status' => 3, 'total_price' => 650, 'bill_phone_number' => '9173333333',
                'items' => [['quantity' => 1, 'variation_info' => ['name' => 'Canpro']]]]]
            : []]));
        $this->customers();

        $this->actingAs($this->owner)->get(route('customers.show', '9173333333'))
            ->assertOk()
            ->assertSeeInOrder(['Delivered', '₱650.00'])
            ->assertDontSee('not in Pancake yet');
    }

    public function test_backfill_runs_oldest_first_in_two_month_windows_then_stops_for_good(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-03 18:00', 'Asia/Manila'));
        config([
            'services.pancake.key' => 'pos-key', 'services.pancake.shop_id' => '1',
            'customers.backfill_from' => '2025-12-30', 'customers.delivered_from' => '2026-01-01', 'customers.logistics_from' => '2026-01-02',
        ]);
        $orderDays = [];
        $deliveryDays = [];
        Http::fake(function (Request $request) use (&$orderDays, &$deliveryDays) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $day = CarbonImmutable::createFromTimestamp($query['startDateTime'], 'Asia/Manila')->toDateString();
            $query['updateStatus'] === '3' ? $deliveryDays[] = $day : $orderDays[] = $day;

            return Http::response(['success' => true, 'total_pages' => 1, 'data' => $query['updateStatus'] === '3' ? [[
                'display_id' => 9001, 'inserted_at' => '2025-12-29T02:00:00', 'status' => 3, 'total_price' => 1200,
                'bill_phone_number' => '09176666666', 'bill_full_name' => 'Faye Cruz', 'assigning_seller' => ['name' => 'Lhea Gatdula'],
                'items' => [['quantity' => 2, 'variation_info' => ['name' => 'Canpro']]],
            ]] : []]);
        });

        // Window 1 (Dec 30 – Feb 28) in one run, then March in the next; then it never runs again.
        $this->artisan('customers:backfill', ['--window' => true])->expectsOutputToContain('Jan–Feb 2026 done.')->assertSuccessful();
        $this->artisan('customers:backfill')->expectsOutputToContain('backfill finished')->assertSuccessful();
        $this->artisan('customers:backfill')->expectsOutputToContain('is finished')->assertSuccessful();

        $this->assertCount(63, $orderDays);
        $this->assertSame(['2025-12-30', '2026-03-02'], [$orderDays[0], end($orderDays)]);
        // POS deliveries only before the logistics report starts.
        $this->assertSame(['2026-01-01'], $deliveryDays);
        $delivery = LogisticsOrder::firstWhere('order_id', '9001');
        $this->assertSame(['crd', 'pos', '9176666666', '2026-01-01'], [$delivery->team, $delivery->source, $delivery->phone_key, $delivery->delivered_date->toDateString()]);
        $this->assertSame('1200.00', PancakeOrder::firstWhere('pancake_order_id', '9001')->total_price);
        $this->assertSame('2026-03-02', Setting::value(BackfillCustomerDatabase::DONE_THROUGH));
    }

    public function test_a_failed_history_lookup_waits_while_the_others_are_saved(): void
    {
        config(['services.pancake.key' => 'pos-key', 'services.pancake.shop_id' => '1']);
        Http::fake(fn (Request $request) => str_contains($request->url(), 'search=9174444444&')
            ? Http::response('Too many requests', 429)
            : Http::response(['success' => true, 'total_pages' => 1, 'data' => []]));
        $this->customers();

        // Dan's lookup fails: the other three are saved, and Dan waits instead of being retried first every run.
        $this->artisan('customers:check-history')->expectsOutputToContain('3 of 4 CRD customers done')->assertSuccessful();
        $this->artisan('customers:check-history')->expectsOutputToContain('to check right now')->assertSuccessful();

        $this->travel(7)->hours();
        $this->artisan('customers:check-history')->expectsOutputToContain('Checked 0 of 1 customers')->assertSuccessful();
    }

    public function test_crd_orders_before_the_first_day_found_in_pancake_make_a_customer_repeat(): void
    {
        config(['services.pancake.key' => 'pos-key', 'services.pancake.shop_id' => '1']);
        $order = fn (string $at, int $status, string $seller, string $phone = '09174444444') => [
            'display_id' => uniqid(), 'inserted_at' => $at, 'status' => $status, 'bill_phone_number' => $phone, 'assigning_seller' => ['name' => $seller],
        ];
        Http::fake(fn (Request $request) => Http::response(['success' => true, 'total_pages' => 1, 'data' => str_contains($request->url(), 'search=9174444444&') ? [
            $order('2025-11-24T03:48:41', 3, 'CRD Rej Vergara'),        // counts: delivered, CRD account, before Jan 1
            $order('2025-12-01T03:00:00', 6, 'CRD Rej Vergara'),        // canceled
            $order('2025-10-01T03:00:00', 3, 'Angel Mirador'),          // not a CRA
            $order('2026-02-01T03:00:00', 3, 'CRD Lhei'),               // not before Jan 1
            $order('2025-09-01T03:00:00', 3, 'CRD Lhei', '09990000000'), // another customer (number in a note)
        ] : []]));
        $this->customers();

        // Dan has one CRA-handled delivery this year: Retained until his history is checked.
        $this->actingAs($this->owner)->get(route('customers.index'))
            ->assertViewHas('counts', ['all' => 5, 'crd' => 4, 'retained' => 3, 'repeat' => 1])
            ->assertSee('0 of 4');

        $this->artisan('customers:check-history')->expectsOutputToContain('4 of 4 CRD customers done')->assertSuccessful();
        $this->artisan('customers:check-history')->expectsOutputToContain('to check right now')->assertSuccessful();
        Cache::flush();

        $this->actingAs($this->owner)->get(route('customers.index'))
            ->assertViewHas('counts', ['all' => 5, 'crd' => 4, 'retained' => 2, 'repeat' => 2])
            ->assertDontSee('Checking CRD customers');
        $this->actingAs($this->owner)->get(route('customers.show', '9174444444'))
            ->assertSeeInOrder(['Dan Lim', 'Repeat Customer', 'Before Jan 1, 2026:', '1 CRA-handled order', 'last Nov 24, 2025']);
    }
}
