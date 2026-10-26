<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\BacklogFill;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NicheTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private User $ben;

    private User $supervisor;

    private Product $pterygium;

    private CarbonImmutable $day;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00', 'Asia/Manila'));
        $this->day = CarbonImmutable::parse('2026-10-09');
        $this->anna = $this->cra('Anna', 'CRD ANNA');
        $this->ben = $this->cra('Ben', 'CRD BEN');
        $this->supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $this->pterygium = Product::create(['name' => 'Pterygium']);
        Product::create(['name' => 'Sinuxyl']);
    }

    private function cra(string $name, string $pancake): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    private function lead(string $product, string $outOfStock, ?User $cra = null, array $extra = []): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => (string) (9000 + $this->seq), 'customer_name' => 'C'.$this->seq, 'phone_number' => '0917800'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'product_name' => $product, 'qty' => 1, 'delivered_date' => '2026-06-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => $outOfStock, 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra?->id, ...$extra,
        ]);
    }

    private function annaOnPterygium(): void
    {
        $this->actingAs($this->supervisor)->post(route('settings.niches.store'), [
            'name' => 'Eye care', 'products' => [$this->pterygium->id], 'cras' => [$this->anna->id],
        ])->assertSessionHasNoErrors();
    }

    public function test_a_cra_in_a_niche_gets_only_its_products_leads(): void
    {
        $this->annaOnPterygium();
        foreach (['Pterygium', 'Sinuxyl', 'Sinuxyl', 'Pterygium'] as $product) {
            $this->lead($product, '2026-10-09');
        }

        app(LeadGenerator::class)->assign($this->day);

        $this->assertSame(['Pterygium', 'Pterygium'], Lead::where('assigned_to', $this->anna->id)->pluck('product_name')->all());
        $this->assertSame(['Sinuxyl', 'Sinuxyl'], Lead::where('assigned_to', $this->ben->id)->pluck('product_name')->all());
    }

    public function test_high_aov_and_vip_customers_stay_with_their_cra_but_two_orders_dont_cross_the_niche(): void
    {
        $this->annaOnPterygium();
        $vip = $this->lead('Sinuxyl', '2026-10-09');
        CustomerAccount::create(['phone_key' => LeadGenerator::normalizePhone($vip->phone_number), 'assigned_cra_id' => $this->anna->id]);
        $regular = $this->lead('Sinuxyl', '2026-10-09');
        foreach (['2026-05-01', '2026-06-01'] as $on) {
            PancakeOrder::create(['pancake_order_id' => 'P'.$on, 'ordered_on' => $on, 'ordered_at' => $on.' 02:00:00', 'seller_name' => 'CRD ANNA',
                'phone_key' => LeadGenerator::normalizePhone($regular->phone_number), 'status' => 3, 'total_price' => 800]);
        }

        app(LeadGenerator::class)->assign($this->day);

        $this->assertSame($this->anna->id, $vip->fresh()->assigned_to);
        $this->assertSame($this->ben->id, $regular->fresh()->assigned_to);
    }

    public function test_saving_moves_only_untouched_leads_outside_the_niche(): void
    {
        $today = $this->lead('Sinuxyl', '2026-10-09', $this->anna);
        $worked = $this->lead('Sinuxyl', '2026-10-09', $this->anna, ['status' => 'active']);
        $backlog = $this->lead('Sinuxyl', '2026-10-05', $this->anna);
        $inNiche = $this->lead('Pterygium', '2026-10-05', $this->anna);

        $this->annaOnPterygium();

        $this->assertSame($this->ben->id, $today->fresh()->assigned_to);
        $this->assertSame($this->anna->id, $worked->fresh()->assigned_to);
        $this->assertSame($this->anna->id, $inNiche->fresh()->assigned_to);
        // Earlier days' leads wait for the backlog fill, still counted on Anna's Oct 5.
        $this->assertNull($backlog->fresh()->assigned_to);
        $this->assertTrue(LeadMove::where('lead_id', $backlog->id)->where('assigned_to', $this->anna->id)->whereDate('listed_on', '2026-10-05')->exists());
    }

    public function test_the_backlog_fill_gives_a_niche_cra_only_its_products(): void
    {
        $this->annaOnPterygium();
        $this->lead('Sinuxyl', '2026-10-01');
        $this->lead('Pterygium', '2026-10-02');
        $this->lead('Sinuxyl', '2026-10-03');

        app(BacklogFill::class)->fill($this->day, collect([$this->anna]));

        $this->assertSame(['Pterygium'], Lead::where('assigned_to', $this->anna->id)->pluck('product_name')->all());
    }

    public function test_niches_are_managed_by_supervisors_only(): void
    {
        $this->actingAs($this->supervisor)->get(route('settings.niches.index'))->assertOk()->assertSee('Add niche');
        $this->actingAs($this->anna)->get(route('settings.niches.index'))->assertForbidden();

        $this->actingAs($this->supervisor)->post(route('settings.niches.store'), ['name' => 'Empty', 'products' => []])->assertSessionHasErrors('products');

        $this->annaOnPterygium();
        $niche = $this->anna->fresh()->niche;
        $this->actingAs($this->supervisor)->delete(route('settings.niches.destroy', $niche))->assertRedirect();
        $this->assertNull($this->anna->fresh()->niche_id);
    }
}
