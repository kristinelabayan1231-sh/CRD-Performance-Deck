<?php

namespace Tests\Feature;

use App\Models\CvrDayEntry;
use App\Models\CvrOrderEntry;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CvrTrackerTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-10-12';

    private User $regina;

    private User $julyAnn;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Manila'));
        $this->regina = $this->user('Regina', Role::CRA, 'Regina V');
        $this->julyAnn = $this->user('July Ann', Role::CRA, 'July Ann DS');
    }

    private function user(string $name, string $role, ?string $pancake = null): User
    {
        return User::create([
            'email' => str_replace(' ', '', strtolower($name)).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true,
        ]);
    }

    private function order(User $cra, ?string $type, float $amount, string $phoneKey, array $extra = []): PancakeOrder
    {
        $this->seq++;

        return PancakeOrder::create([
            'pancake_order_id' => (string) (1391000 + $this->seq), 'ordered_on' => self::DAY, 'ordered_at' => '2026-10-12 02:00:00',
            'seller_name' => strtoupper($cra->pancake_name), 'customer_name' => "Buyer {$this->seq}", 'phone_number' => '0'.$phoneKey,
            'phone_key' => $phoneKey, 'status' => 1, 'total_price' => $amount, 'page_name' => 'Pterygium Eye Repair Drops',
            'tags' => [], 'conversion_type' => $type, 'non_crd' => false, ...$extra,
        ]);
    }

    private function lead(User $cra, array $extra = [], string $phone = '09990000000'): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => "o{$this->seq}", 'customer_name' => "Lead {$this->seq}", 'phone_number' => $phone,
            'product_name' => 'Pterygium Drops', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => self::DAY, 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra->id, 'assigned_at' => now(), ...$extra,
        ]);
    }

    public function test_cra_sees_their_tagged_orders_and_the_days_numbers(): void
    {
        // Assigned: the number is an assigned lead, even on another CRA's list.
        $this->lead($this->julyAnn, ['est_out_of_stock_date' => '2026-10-01'], '09170000001');
        $assigned = $this->order($this->regina, PancakeOrder::SEGMENTATION, 999, '9170000001');
        $pancake = $this->order($this->regina, PancakeOrder::BROADCAST, 500, '9170000002');
        // A lead added from a POS sale doesn't make its order Assigned.
        $this->lead($this->regina, ['order_id' => Lead::POS_PREFIX.'1', 'est_out_of_stock_date' => '2026-10-02'], '09170000002');
        // Left out: canceled, untagged, another CRA's.
        $this->order($this->regina, PancakeOrder::SEGMENTATION, 700, '9170000003', ['status' => PancakeOrder::CANCELED]);
        $this->order($this->regina, null, 800, '9170000004');
        $this->order($this->julyAnn, PancakeOrder::SEGMENTATION, 600, '9170000005');
        // Two earlier deliveries to the assigned customer.
        foreach (['2026-08-01', '2026-09-15'] as $i => $delivered) {
            LogisticsOrder::create(['order_id' => "L{$i}", 'team' => 'crd', 'source' => 'logistics', 'customer_name' => 'Buyer', 'phone_number' => '09170000001',
                'phone_key' => '9170000001', 'product' => 'Drops', 'qty' => 1, 'non_crd' => false, 'delivered_date' => $delivered]);
        }
        CvrOrderEntry::create(['pancake_order_id' => $assigned->pancake_order_id, 'point_of_contact' => 'chat']);
        // Regina's Oct 12 list: engaged, contacted but purchased, reserved, not answering.
        $this->lead($this->regina, ['contact_time' => '10', 'feedback' => 'no_budget']);
        $this->lead($this->regina, ['contact_time' => '11', 'feedback' => 'purchased']);
        $this->lead($this->regina, ['repeat_purchase' => 'reserve']);
        $this->lead($this->regina, ['status' => 'pjr_drop_call']);
        CvrDayEntry::create(['user_id' => $this->regina->id, 'date' => self::DAY, 'unconfirmed' => 2]);

        $response = $this->actingAs($this->regina)->get(route('cvr-tracker.index', ['date' => self::DAY]))->assertOk();

        $data = $response->viewData('data');
        $this->assertSame([$assigned->pancake_order_id, $pancake->pancake_order_id], array_column($data['orders'], 'id'));
        $this->assertSame([true, false], array_column($data['orders'], 'assigned'));
        $this->assertSame([3, 1], array_column($data['orders'], 'total_orders'));
        $this->assertSame([
            'gross' => 1499.0, 'bc_orders' => 1, 'bc_amount' => 500.0, 'sc_orders' => 1, 'sc_amount' => 999.0, 'orders' => 2, 'aov' => 749.5,
            'engagements' => null, 'cvr' => null, 'contacted' => 1, 'unconfirmed' => 2, 'engaged' => 1, 'reserved' => 1, 'not_answering' => 1,
            'status_total' => 5, 'pancake_leads' => 1, 'assigned_leads' => 1,
        ], $data['summary']);
        $response->assertSee('Unresolved tagging: 1 of Regina');
    }

    public function test_cra_changing_the_type_of_order_moves_it_between_broadcast_and_segmentation(): void
    {
        $order = $this->order($this->regina, PancakeOrder::SEGMENTATION, 999, '9170000001');
        CvrOrderEntry::create(['pancake_order_id' => $order->pancake_order_id, 'order_type' => PancakeOrder::BROADCAST]);

        $summary = $this->actingAs($this->regina)->get(route('cvr-tracker.index', ['date' => self::DAY]))->viewData('data')['summary'];

        $this->assertSame([1, 0], [$summary['bc_orders'], $summary['sc_orders']]);
    }

    public function test_orders_follow_the_sheet_including_one_made_under_another_account(): void
    {
        $late = $this->order($this->regina, PancakeOrder::SEGMENTATION, 999, '9170000001', ['ordered_at' => '2026-10-12 05:00:00']);
        $early = $this->order($this->regina, PancakeOrder::SEGMENTATION, 499, '9170000002', ['ordered_at' => '2026-10-12 01:00:00']);
        $unlisted = $this->order($this->regina, PancakeOrder::SEGMENTATION, 749, '9170000003', ['ordered_at' => '2026-10-12 00:30:00']);
        $otherAccount = $this->order($this->julyAnn, PancakeOrder::SEGMENTATION, 1000, '9170000004', ['seller_name' => 'AJ DELA CRUZ']);
        // July Ann's own order, counted for Regina instead.
        $moved = $this->order($this->julyAnn, PancakeOrder::BROADCAST, 600, '9170000005');
        CvrOrderEntry::create(['pancake_order_id' => $late->pancake_order_id, 'position' => 1, 'lead_source' => CvrOrderEntry::ASSIGNED]);
        CvrOrderEntry::create(['pancake_order_id' => $otherAccount->pancake_order_id, 'user_id' => $this->regina->id, 'position' => 2]);
        CvrOrderEntry::create(['pancake_order_id' => $early->pancake_order_id, 'position' => 3]);
        CvrOrderEntry::create(['pancake_order_id' => $moved->pancake_order_id, 'user_id' => $this->regina->id, 'position' => 4]);

        $response = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('cvr-tracker.index', ['date' => self::DAY, 'cra' => $this->regina->id]));

        $orders = $response->viewData('data')['orders'];
        $this->assertSame(
            [$late->pancake_order_id, $otherAccount->pancake_order_id, $early->pancake_order_id, $moved->pancake_order_id, $unlisted->pancake_order_id],
            array_column($orders, 'id'),
        );
        $this->assertTrue($orders[0]['assigned']);
        $this->assertSame([$this->regina->id => 5, $this->julyAnn->id => 0], $response->viewData('counts')->all());
    }

    public function test_cra_can_edit_an_order_counted_for_them_but_its_seller_cannot(): void
    {
        $order = $this->order($this->julyAnn, PancakeOrder::SEGMENTATION, 1000, '9170000004');
        CvrOrderEntry::create(['pancake_order_id' => $order->pancake_order_id, 'user_id' => $this->regina->id]);
        $url = route('cvr-tracker.orders.update', $order->pancake_order_id);

        $this->actingAs($this->regina)->putJson($url, ['point_of_contact' => 'text'])->assertOk();
        $this->actingAs($this->julyAnn)->putJson($url, ['point_of_contact' => 'chat'])->assertForbidden();

        $this->assertDatabaseHas('cvr_order_entries', ['pancake_order_id' => $order->pancake_order_id, 'point_of_contact' => 'text']);
    }

    public function test_manual_sheet_rows_with_no_order_show_as_missing(): void
    {
        CvrDayEntry::create(['user_id' => $this->regina->id, 'date' => self::DAY, 'sheet_missing' => [
            ['customer' => 'Sofia Ochar', 'amount' => 799], ['customer' => 'Rio Soriano', 'amount' => 499],
        ]]);

        $this->actingAs($this->regina)->get(route('cvr-tracker.index', ['date' => self::DAY]))
            ->assertSee("2 orders missing here from Regina's manual sheet", false)
            ->assertSee('Sofia Ochar (₱799), Rio Soriano (₱499)');
    }

    public function test_cra_sees_only_their_own_tracker(): void
    {
        $this->order($this->julyAnn, PancakeOrder::SEGMENTATION, 600, '9170000005');

        $response = $this->actingAs($this->regina)->get(route('cvr-tracker.index', ['date' => self::DAY, 'cra' => $this->julyAnn->id]))->assertOk();

        $this->assertTrue($response->viewData('cra')->is($this->regina));
        $this->assertSame([], $response->viewData('data')['orders']);
    }

    public function test_supervisor_can_open_any_cra(): void
    {
        $this->order($this->julyAnn, PancakeOrder::SEGMENTATION, 600, '9170000005');

        $response = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))
            ->get(route('cvr-tracker.index', ['date' => self::DAY, 'cra' => $this->julyAnn->id]))->assertOk();

        $this->assertCount(1, $response->viewData('data')['orders']);
    }

    public function test_user_without_cvr_tracker_permission_is_refused(): void
    {
        $this->actingAs($this->user('Plain', Role::USER))->get(route('cvr-tracker.index'))->assertForbidden();
    }

    public function test_cra_saves_their_orders_picks_and_a_type_matching_the_tag_is_no_change(): void
    {
        $order = $this->order($this->regina, PancakeOrder::SEGMENTATION, 999, '9170000001');
        $url = route('cvr-tracker.orders.update', $order->pancake_order_id);

        $this->actingAs($this->regina)->putJson($url, ['upsell' => 'cra', 'point_of_contact' => 'called'])->assertOk();
        $this->actingAs($this->regina)->putJson($url, ['order_type' => PancakeOrder::SEGMENTATION])->assertOk();

        $this->assertDatabaseHas('cvr_order_entries', [
            'pancake_order_id' => $order->pancake_order_id, 'upsell' => 'cra', 'point_of_contact' => 'called', 'order_type' => null, 'updated_by' => $this->regina->id,
        ]);
    }

    public function test_cra_cannot_edit_another_cras_order(): void
    {
        $order = $this->order($this->julyAnn, PancakeOrder::SEGMENTATION, 600, '9170000005');

        $this->actingAs($this->regina)->putJson(route('cvr-tracker.orders.update', $order->pancake_order_id), ['upsell' => 'cra'])->assertForbidden();

        $this->assertDatabaseCount('cvr_order_entries', 0);
    }

    public function test_upsell_outside_the_choices_is_rejected(): void
    {
        $order = $this->order($this->regina, PancakeOrder::SEGMENTATION, 999, '9170000001');

        $this->actingAs($this->regina)->putJson(route('cvr-tracker.orders.update', $order->pancake_order_id), ['upsell' => 'big'])
            ->assertJsonValidationErrors(['upsell' => 'The selected upsell is invalid.']);
    }

    public function test_cra_saves_their_unconfirmed_for_a_day_but_not_another_cras(): void
    {
        $this->actingAs($this->regina)->putJson(route('cvr-tracker.days.update'), ['cra' => $this->regina->id, 'date' => self::DAY, 'unconfirmed' => 3])->assertOk();
        $this->actingAs($this->regina)->putJson(route('cvr-tracker.days.update'), ['cra' => $this->regina->id, 'date' => self::DAY, 'unconfirmed' => 4])->assertOk();
        $this->actingAs($this->regina)->putJson(route('cvr-tracker.days.update'), ['cra' => $this->julyAnn->id, 'date' => self::DAY, 'unconfirmed' => 9])->assertForbidden();

        $this->assertSame([4], CvrDayEntry::pluck('unconfirmed')->all());
    }
}
