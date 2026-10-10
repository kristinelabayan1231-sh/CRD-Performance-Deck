<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SegmentationSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $alice;

    private User $bea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Manila'));
        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        $cra = Role::firstWhere('slug', Role::CRA)->id;
        $this->alice = User::create(['email' => 'alice@example.com', 'display_name' => 'Alice', 'role_id' => $cra, 'is_active' => true]);
        $this->bea = User::create(['email' => 'bea@example.com', 'display_name' => 'Bea', 'role_id' => $cra, 'is_active' => true]);
        Product::create(['name' => 'CanPro']);
        Product::create(['name' => 'Sinuxyl']);
    }

    private function lead(User $cra, string $product, array $fields = [], string $day = '2026-10-03'): void
    {
        static $id = 0;
        $id++;
        Lead::create(['order_id' => (string) $id, 'customer_name' => "Customer {$id}", 'phone_number' => '917'.$id, 'product_name' => $product, 'qty' => 1,
            'delivered_date' => '2026-09-03', 'consumption_days' => 30, 'est_out_of_stock_date' => $day, 'lead_type' => Lead::TYPE_FSD,
            'assigned_to' => $cra->id, ...$fields]);
    }

    public function test_summary_adds_up_feedback_statuses_hours_and_gives_insights(): void
    {
        foreach (range(1, 4) as $i) {
            $this->lead($this->alice, 'CanPro', ['status' => 'active', 'contact_time' => '10', 'feedback' => 'no_verbal_conv']);
        }
        $this->lead($this->alice, 'CanPro', ['status' => 'active', 'contact_time' => '10', 'repeat_purchase' => 'yes', 'feedback' => 'purchased']);
        $this->lead($this->bea, 'Sinuxyl', ['status' => 'busy_callback', 'feedback' => 'still_have_stocks']);
        $this->lead($this->bea, 'Sinuxyl', ['feedback' => 'blocked', 'status' => 'blocked']);
        $this->lead($this->bea, 'Sinuxyl');
        $this->lead($this->bea, 'Sinuxyl', [], '2026-09-20'); // other month

        $this->actingAs($this->owner)->get(route('segmentation.overview'))
            ->assertOk()
            ->assertSeeInOrder(['Daily', 'Weekly Segmentation', 'Summary'])
            ->assertViewHas('summary', fn (array $s) => $s['leads'] === 8 && $s['catered'] === 7 && $s['pending'] === 1 && $s['converted'] === 1
                && $s['feedback']->all() === ['blocked' => 1, 'no_verbal_conv' => 4, 'purchased' => 1, 'still_have_stocks' => 1]
                && $s['hours']['10'] === ['label' => '10:00AM-11:00AM', 'contacts' => 5, 'converted' => 1]
                && $s['stocks_no_callback'] === 1)
            ->assertSee("Customer's feedback")
            ->assertSee('NO VERBAL CONV: 4 (57.1%)')
            ->assertSee('Other (BLOCKED)')
            ->assertSee('Most common feedback: NO VERBAL CONV (57% of 7).')
            ->assertSee('Best converting hour: 10:00AM-11:00AM (20% converted)')
            ->assertSee('with STILL HAVE STOCKS have no callback date yet');

        // Filters narrow it: one CRA and one product.
        $this->actingAs($this->owner)->get(route('segmentation.overview', ['cra' => $this->bea->id, 'product' => 'Sinuxyl']))
            ->assertViewHas('summary', fn (array $s) => $s['leads'] === 3 && $s['converted'] === 0);
    }

    public function test_a_cra_sees_only_their_own_leads(): void
    {
        $this->lead($this->alice, 'CanPro');
        $this->lead($this->bea, 'CanPro');
        $this->lead($this->bea, 'CanPro');

        $this->actingAs($this->alice)->get(route('segmentation.overview', ['cra' => $this->bea->id]))
            ->assertOk()
            ->assertViewHas('summary', fn (array $s) => $s['leads'] === 1)
            ->assertDontSee('All CRAs');
    }
}
