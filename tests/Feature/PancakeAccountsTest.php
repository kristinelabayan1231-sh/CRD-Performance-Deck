<?php

namespace Tests\Feature;

use App\Models\CustomerHistory;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerDatabase;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PancakeAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00', 'Asia/Manila'));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        User::create(['email' => 'lhea@example.com', 'display_name' => 'Lhea', 'pancake_name' => 'CRD Lhei', 'role_id' => Role::where('slug', Role::CRA)->value('id'), 'is_active' => true]);
    }

    /**
     * A delivery the logistics API listed (or, with $pos, one saved from Pancake POS before the logistics report).
     */
    private function delivery(string $id, string $phone, string $date, string $team, ?string $seller, bool $pos = false): void
    {
        LogisticsOrder::create(['order_id' => $id, 'team' => $team, 'source' => $pos ? LogisticsOrder::SOURCE_POS : LogisticsOrder::SOURCE_LOGISTICS, 'customer_name' => "Customer {$id}",
            'phone_number' => $phone, 'phone_key' => $phone, 'product' => 'Canpro', 'delivered_date' => $date]);
        PancakeOrder::create(['pancake_order_id' => $id, 'ordered_on' => $date, 'phone_key' => $phone, 'status' => 3, 'total_price' => 500, 'seller_name' => $seller]);
    }

    public function test_page_shows_both_lists_and_where_each_is_used(): void
    {
        $this->actingAs($this->owner)->get(route('settings.pancake-accounts.index'))
            ->assertOk()
            ->assertSeeInOrder(['CRD team accounts', 'Customer Database', 'CRD Joanne Maclang', 'CRD Sha Galano'])
            ->assertSeeInOrder(['CRA accounts', 'Dashboard', 'Conversion Breakdown', 'Segmentation Productivity', 'CRD Lhei', 'Lhea']);

        $supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $this->actingAs($supervisor)->get(route('settings.pancake-accounts.index'))->assertForbidden();
        $this->actingAs($supervisor)->put(route('settings.pancake-accounts.update'), ['accounts' => ['X']])->assertForbidden();
    }

    public function test_saved_accounts_decide_who_the_customer_database_counts_and_relabel_older_pos_deliveries(): void
    {
        $this->delivery('L1', '9171111111', '2026-10-01', LogisticsOrder::TEAM_FSD, 'CRD SHA GALANO');
        $this->delivery('P1', '9172222222', '2026-03-01', LogisticsOrder::TEAM_FSD, 'CRD NEW AGENT', pos: true);
        $this->delivery('P2', '9173333333', '2026-03-02', LogisticsOrder::TEAM_CRD, 'CRD JOANNE MACLANG', pos: true);

        $this->assertSame(['all' => 3, 'crd' => 2, 'retained' => 2, 'repeat' => 0], app(CustomerDatabase::class)->counts([]));

        // Sha Galano and Joanne Maclang removed, a new account added (blanks and repeats dropped).
        $this->actingAs($this->owner)->put(route('settings.pancake-accounts.update'), ['accounts' => ['CRD Rej Vergara', ' crd  new agent ', '', 'CRD NEW AGENT']])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(['CRD Rej Vergara', 'crd new agent'], PancakeAccounts::crd());
        $this->assertSame(LogisticsOrder::TEAM_CRD, LogisticsOrder::firstWhere('order_id', 'P1')->team);
        $this->assertSame(LogisticsOrder::TEAM_FSD, LogisticsOrder::firstWhere('order_id', 'P2')->team);
        $this->assertSame(['all' => 3, 'crd' => 1, 'retained' => 1, 'repeat' => 0], app(CustomerDatabase::class)->counts([]));
    }

    public function test_a_change_rechecks_only_customers_delivered_in_the_month_before(): void
    {
        $this->delivery('R1', '9171111111', '2026-09-20', LogisticsOrder::TEAM_CRD, null);
        $this->delivery('O1', '9172222222', '2026-07-01', LogisticsOrder::TEAM_CRD, null);
        foreach (['9171111111', '9172222222'] as $phone) {
            CustomerHistory::create(['phone_key' => $phone, 'prior_cra_orders' => 1, 'checked_at' => now()->subWeek()]);
        }
        $customers = fn () => app(CustomerDatabase::class);

        $this->assertSame([], $customers()->uncheckedHistories(10));

        $this->actingAs($this->owner)->put(route('settings.pancake-accounts.update'), ['accounts' => ['CRD Rej Vergara']]);

        // Only the September customer is due again; both keep their last result meanwhile.
        $this->assertSame(['9171111111'], $customers()->uncheckedHistories(10));
        $this->assertSame(['checked' => 1, 'total' => 2], $customers()->historyProgress());
        $this->assertSame(['all' => 2, 'crd' => 2, 'retained' => 0, 'repeat' => 2], $customers()->counts([]));
    }
}
