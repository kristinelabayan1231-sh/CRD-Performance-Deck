<?php

namespace Tests\Feature;

use App\Models\PancakeOrder;
use App\Models\PcReportEntry;
use App\Models\Role;
use App\Models\User;
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
        // Regina's Sinuxyl: two pages of PC Report counts, two orders; a canceled one and another day's are left out.
        $this->entry($regina, 'Sinuxyl Nasal Spray', 100, 50);
        $this->entry($regina, 'Sinuxyl Nasal Care', 20, 10);
        $this->order($regina, 'Sinuxyl Nasal Spray', 999);
        $this->order($regina, 'Sinuxyl Nasal Care', 500);
        $this->order($regina, 'Sinuxyl Nasal Spray', 700, ['status' => PancakeOrder::CANCELED]);
        $this->order($regina, 'Sinuxyl Nasal Spray', 800, ['ordered_on' => '2026-10-02']);
        // July Ann's ClearSight.
        $this->entry($julyAnn, 'Clear Sight Hub', 40, 20);
        $this->order($julyAnn, 'Clear Sight Hub', 800);

        $data = $this->actingAs($this->user('Sup', Role::CRA_SUPERVISOR))->get(route('daily-is.index', ['date' => self::DAY]))
            ->assertOk()
            ->assertSee('Customer Retention Department')
            ->assertSee('NET INCOME')
            ->viewData('data');

        $this->assertEquals(['leads' => 120, 'engagements' => 60, 'orders' => 2, 'gross' => 1499.0, 'conversion' => 2 / 60, 'aov' => 749.5], $data['cras'][$regina->id]['products']['sinuxyl']);
        $this->assertEquals(['leads' => 0, 'engagements' => 0, 'orders' => 0, 'gross' => 0.0, 'conversion' => null, 'aov' => null], $data['cras'][$regina->id]['products']['clearsight']);
        $this->assertEquals(['leads' => 160, 'engagements' => 80, 'orders' => 3, 'gross' => 2299.0, 'conversion' => 3 / 80, 'aov' => 2299 / 3], $data['department']['total']);
        $this->assertEquals(['leads' => 40, 'engagements' => 20, 'orders' => 1, 'gross' => 800.0, 'conversion' => 0.05, 'aov' => 800.0], $data['department']['products']['clearsight']);
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
