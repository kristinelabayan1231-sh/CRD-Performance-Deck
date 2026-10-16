<?php

namespace Tests\Feature;

use App\Models\CvrDayEntry;
use App\Models\CvrOrderEntry;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CvrBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CvrBackfillSeederTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $id, string $seller, string $type): PancakeOrder
    {
        return PancakeOrder::create([
            'pancake_order_id' => $id, 'ordered_on' => '2026-10-01', 'ordered_at' => '2026-10-01 01:00:00', 'seller_name' => $seller,
            'customer_name' => 'Buyer', 'status' => 3, 'total_price' => 999, 'tags' => [], 'conversion_type' => $type, 'non_crd' => false,
        ]);
    }

    public function test_it_fills_the_sheets_picks_counts_other_accounts_orders_for_the_cra_and_keeps_app_picks(): void
    {
        $regina = User::create([
            'email' => 'regina@example.com', 'display_name' => 'Regina', 'pancake_name' => 'CRD Rej Vergara',
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
        // Sheet row 1 (Marlyn Dalipe): her own order. Row 7 (Felipe Dong Devilleres): made under AJ DELA CRUZ.
        $this->order('1374479', 'CRD REJ VERGARA', PancakeOrder::SEGMENTATION);
        $this->order('1374535', 'AJ DELA CRUZ', PancakeOrder::SEGMENTATION);
        // Picked in the app before the backfill ran: kept.
        CvrOrderEntry::create(['pancake_order_id' => '1374535', 'point_of_contact' => 'chat']);

        $this->seed(CvrBackfillSeeder::class);
        $this->seed(CvrBackfillSeeder::class);

        $this->assertDatabaseCount('cvr_order_entries', 2);
        $this->assertDatabaseHas('cvr_order_entries', [
            'pancake_order_id' => '1374479', 'user_id' => null, 'position' => 1, 'lead_source' => 'assigned',
            'upsell' => 'without', 'order_type' => null, 'point_of_contact' => 'chat',
        ]);
        $this->assertDatabaseHas('cvr_order_entries', [
            'pancake_order_id' => '1374535', 'user_id' => $regina->id, 'position' => 7, 'point_of_contact' => 'chat',
        ]);
        // Her Oct 5 sheet marks three contacts UNCONFIRMED.
        $this->assertSame([3], CvrDayEntry::where('user_id', $regina->id)->whereDate('date', '2026-10-05')->pluck('unconfirmed')->all());
        // Her Oct 7 sheet row for Sofia Ochar matches no order.
        $this->assertSame(
            [['customer' => 'Sofia Ochar', 'amount' => 799]],
            CvrDayEntry::where('user_id', $regina->id)->whereDate('date', '2026-10-07')->value('sheet_missing'),
        );
    }
}
