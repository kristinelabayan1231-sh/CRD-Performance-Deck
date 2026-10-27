<?php

namespace Tests\Feature;

use App\Models\CustomerAge;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductTargetMarketTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $seq = 0;

    /** @var list<array<string, mixed>> What Pancake POS sends for an orders request. */
    private array $posOrders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pancake.key' => 'pos-key', 'services.pancake.shop_id' => '1']);
        $this->travelTo(CarbonImmutable::parse('2026-10-10 18:00', 'Asia/Manila'));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);

        Http::fake(fn () => Http::response(['success' => true, 'total_pages' => 1, 'data' => $this->posOrders]));
    }

    private function order(string $phoneKey, string $item, string $day, int $status = 3): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => (string) (9000 + $this->seq), 'ordered_on' => $day, 'ordered_at' => $day.' 02:00:00', 'seller_name' => 'CRD LHEI',
            'phone_key' => $phoneKey, 'status' => $status, 'total_price' => 500, 'total_discount' => 0, 'items' => [['qty' => 1, 'name' => $item]],
        ]);
    }

    private function posOrder(string $phone, ?string $note, string $insertedAt = '2026-10-01T02:00:00.000000', ?string $notePrint = null): array
    {
        $this->seq++;

        return [
            'id' => 360301022569000 + $this->seq, 'display_id' => 9000 + $this->seq, 'inserted_at' => $insertedAt, 'status' => 3,
            'bill_phone_number' => $phone, 'total_price' => 500, 'items' => [], 'note' => $note, 'note_print' => $notePrint,
        ];
    }

    /**
     * @param  array<string, array{0: int, 1: string}>  $ages  phone key => [age, day noted]
     */
    private function ages(array $ages): void
    {
        CustomerAge::remember(array_map(fn (array $age) => ['age' => $age[0], 'noted_on' => $age[1]], $ages));
    }

    /** @return array<string, array{0: int, 1: string}> */
    private function savedAges(): array
    {
        return CustomerAge::orderBy('phone_key')->get()->mapWithKeys(fn (CustomerAge $age) => [$age->phone_key => [$age->age, $age->noted_on->toDateString()]])->all();
    }

    public function test_each_products_customer_ages_are_compared_with_its_target(): void
    {
        Product::create(['name' => 'Clear Sight 3.0', 'age_min' => 45]);
        Product::create(['name' => 'Sinuxyl']);
        $this->ages([
            '9170000001' => [56, '2026-10-05'],
            // Noted as 46 a year after their first order (Oct 2): counted at 45.
            '9170000002' => [46, '2027-10-02'],
            '9170000003' => [36, '2026-10-05'],
            '9170000005' => [66, '2026-10-05'],
        ]);

        $this->order('9170000001', 'Clear Sight 3.0', '2026-10-05');
        $this->order('9170000001', 'Clear Sight 3.0', '2026-10-06');
        $this->order('9170000002', 'Clear Sight 3.0', '2026-10-02');
        $this->order('9170000002', 'Clear Sight 3.0', '2026-10-08');
        $this->order('9170000003', 'Clear Sight 3.0', '2026-10-05');
        // No noted age: counted, but left out of the percentages.
        $this->order('9170000004', 'Clear Sight 3.0', '2026-10-05');
        // Not counted: a canceled order, and an order before the month.
        $this->order('9170000005', 'Clear Sight 3.0', '2026-10-05', status: 6);
        $this->order('9170000005', 'Clear Sight 3.0', '2026-09-30');
        // Another product of a customer already counted, with no target.
        $this->order('9170000001', 'Sinuxyl', '2026-10-05');

        $response = $this->actingAs($this->owner)->get(route('product-target-market.index', ['period' => 'month', 'month' => '2026-10']))
            ->assertOk()
            ->assertSee('3 of 4 customers have an age (75%)');

        /** @var Collection<int, array<string, mixed>> $products */
        $products = $response->viewData('products')->keyBy(fn (array $row) => $row['product']->name);
        $clearSight = $products['Clear Sight 3.0'];

        $this->assertSame([4, 3], [$clearSight['customers'], $clearSight['withAge']]);
        $this->assertSame([0, 0, 1, 1, 1, 0], $clearSight['bands']);
        $this->assertSame([2, 1, 0], [$clearSight['inTarget'], $clearSight['younger'], $clearSight['older']]);
        $this->assertSame('partly', $clearSight['status']);
        // The fewest neighbouring age groups holding 75% of the customers: 35–64.
        $this->assertSame([2, 4], $clearSight['suggested']);
        $this->assertSame(['no_target', null], [$products['Sinuxyl']['status'], $products['Sinuxyl']['share']]);
    }

    public function test_the_status_follows_the_share_inside_the_target(): void
    {
        Product::create(['name' => 'Sinuxyl', 'age_min' => 25, 'age_max' => 54]);
        // 3 of 4 inside 25–54 = 75%: On target. Then a fifth, older customer makes it 60%: Partly. 2 of 5 would be Off target.
        $this->ages(['9170000001' => [30, '2026-10-05'], '9170000002' => [41, '2026-10-05'], '9170000003' => [54, '2026-10-05'], '9170000004' => [70, '2026-10-05'], '9170000005' => [55, '2026-10-05']]);
        foreach (['9170000001', '9170000002', '9170000003', '9170000004'] as $phoneKey) {
            $this->order($phoneKey, 'Sinuxyl', '2026-10-05');
        }

        $status = fn () => $this->actingAs($this->owner)->get(route('product-target-market.index'))->viewData('products')->first()['status'];

        $this->assertSame('on', $status());
        $this->order('9170000005', 'Sinuxyl', '2026-10-05');
        $this->assertSame('partly', $status());
        $this->ages(['9170000003' => [60, '2026-10-06']]);
        $this->assertSame('off', $status());
    }

    public function test_synced_orders_save_the_age_noted_in_pos(): void
    {
        $this->ages(['9170000002' => [40, '2026-09-01'], '9170000005' => [33, '2026-10-05']]);
        $this->posOrders = [
            // After a link, on its own line, without a space.
            $this->posOrder('09170000001', "cvc- https://drive.google.com/drive/folders/1Ldj?fbclid=IwY2\r\n\r\nAge:17"),
            // Case and spacing don't matter.
            $this->posOrder('09170000003', 'YEL - CALLDROPPED age 52'),
            // Only the internal note: the printed note (waybill) isn't read.
            $this->posOrder('09170000006', 'chua - ', notePrint: 'Age: 25'),
            // No age in the note: the saved one stays.
            $this->posOrder('09170000002', 'chua - upsell 400'),
            // Not an age: a word ending in "age", and a number over 120.
            $this->posOrder('09170000004', 'Package 2 Age: 400'),
            // An older order's note doesn't replace a newer one.
            $this->posOrder('09170000005', 'Age: 30'),
        ];

        $this->artisan('pancake:sync', ['--orders' => true, '--date' => '2026-10-01'])->assertSuccessful();

        $this->assertSame([
            '9170000001' => [17, '2026-10-01'],
            '9170000002' => [40, '2026-09-01'],
            '9170000003' => [52, '2026-10-01'],
            '9170000005' => [33, '2026-10-05'],
        ], $this->savedAges());
        $this->assertSame(1, PancakeOrder::where('phone_key', '9170000001')->count());
    }

    public function test_a_note_typed_after_the_order_is_picked_up_by_the_change_refresh(): void
    {
        $this->posOrders = [$this->posOrder('09170000001', 'chua - Age: 61')];

        $this->artisan('pancake:sync', ['--tags' => true, '--date' => '2026-10-01'])->assertSuccessful();

        $this->assertSame(['9170000001' => [61, '2026-10-01']], $this->savedAges());
    }

    public function test_the_last_three_months_of_ages_can_be_fetched_again(): void
    {
        $this->posOrders = [$this->posOrder('09170000001', 'Age: 45')];

        $this->artisan('pancake:sync', ['--ages' => true, '--from' => '2026-10-01', '--to' => '2026-10-02'])
            ->expectsOutputToContain('1 customers with an age')
            ->assertSuccessful();

        CustomerAge::query()->delete();
        $this->actingAs($this->owner)->post(route('product-target-market.ages'))
            ->assertRedirect()->assertSessionHas('status');

        // The page's fetch runs after the response: the last 3 months, without saving the orders themselves.
        $this->assertSame(['9170000001' => [45, '2026-10-01']], $this->savedAges());
        $this->assertSame(0, PancakeOrder::count());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'startDateTime='.CarbonImmutable::parse('2026-07-11', 'Asia/Manila')->getTimestamp()));
    }

    public function test_only_roles_with_the_permission_see_it(): void
    {
        $cra = User::create(['email' => 'cra@gmail.com', 'role_id' => Role::where('slug', Role::CRA)->value('id'), 'is_active' => true]);
        $supervisor = User::create(['email' => 'sup@gmail.com', 'role_id' => Role::where('slug', Role::CRA_SUPERVISOR)->value('id'), 'is_active' => true]);

        $this->actingAs($cra)->get(route('product-target-market.index'))->assertForbidden();
        $this->actingAs($cra)->post(route('product-target-market.ages'))->assertForbidden();
        $this->actingAs($supervisor)->get(route('product-target-market.index'))->assertOk()->assertSee('Product Target Market');
    }
}
