<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadChatTest extends TestCase
{
    use RefreshDatabase;

    private User $cra;

    private Lead $lead;

    /** Orders the fake POS search returns. */
    private array $pancakeOrders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pancake.key' => 'pos-key', 'services.pancake.shop_id' => '1', 'services.pancake.inbox_url' => 'https://pancake.ph']);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'Asia/Manila'));
        Http::fake(['pos.pages.fm/*' => fn () => Http::response(['success' => true, 'data' => $this->pancakeOrders])]);
        $this->cra = User::create(['email' => 'lhea@example.com', 'display_name' => 'Lhea', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        $this->lead = Lead::create([
            'order_id' => '1359294', 'customer_name' => 'Ana Reyes', 'phone_number' => '09171234567', 'product_name' => 'Pterygium Drops', 'qty' => 1,
            'delivered_date' => '2026-09-01', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-10-09', 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $this->cra->id,
        ]);
    }

    private function order(string $number, ?string $conversation, string $orderedAt = '2026-09-01 02:00:00', string $phone = '9171234567'): PancakeOrder
    {
        return PancakeOrder::create([
            'pancake_order_id' => $number, 'ordered_on' => substr($orderedAt, 0, 10), 'ordered_at' => $orderedAt, 'phone_key' => $phone,
            'status' => 3, 'total_price' => 800, 'page_name' => 'Trusted Eye Care', 'page_id' => '1013', 'conversation_id' => $conversation,
        ]);
    }

    public function test_a_leads_chat_is_its_orders_conversation(): void
    {
        $this->order('1359294', '1013_555');

        $this->actingAs($this->cra)->getJson(route('segmentation.chat', $this->lead))
            ->assertExactJson(['url' => 'https://pancake.ph/1013?c_id=1013_555', 'page' => 'Trusted Eye Care']);
        Http::assertNothingSent();
    }

    public function test_an_order_saved_without_its_conversation_is_fetched_from_pancake_once_and_saved(): void
    {
        $this->order('1359294', null);
        $this->pancakeOrders = [[
            'display_id' => '1359294', 'inserted_at' => '2026-09-01T02:00:00', 'status' => 3, 'bill_phone_number' => '09171234567',
            'page_id' => 1013, 'page' => ['name' => 'Trusted Eye Care'], 'conversation_id' => '1013_777', 'items' => [],
        ]];

        $this->actingAs($this->cra)->getJson(route('segmentation.chat', $this->lead))->assertJsonPath('url', 'https://pancake.ph/1013?c_id=1013_777');
        $this->actingAs($this->cra)->getJson(route('segmentation.chat', $this->lead))->assertJsonPath('url', 'https://pancake.ph/1013?c_id=1013_777');

        Http::assertSentCount(1);
        $this->assertSame('1013_777', PancakeOrder::firstWhere('pancake_order_id', '1359294')->conversation_id);
    }

    public function test_with_no_conversation_on_the_order_the_customers_latest_chat_on_the_same_number_is_used(): void
    {
        $this->order('1359294', null);
        $this->order('1300001', '1013_old', '2026-05-01 02:00:00');
        $this->order('1300002', '1013_latest', '2026-08-01 02:00:00');
        $this->order('1300003', '1013_other', '2026-09-05 02:00:00', phone: '9179999999');

        $this->actingAs($this->cra)->getJson(route('segmentation.chat', $this->lead))->assertJsonPath('url', 'https://pancake.ph/1013?c_id=1013_latest');
    }

    public function test_no_chat_when_no_order_of_the_customer_has_one(): void
    {
        $this->actingAs($this->cra)->getJson(route('segmentation.chat', $this->lead))->assertExactJson(['url' => null, 'page' => null]);
    }
}
