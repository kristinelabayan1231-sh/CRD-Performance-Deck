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

    public function test_summary_adds_up_feedback_statuses_and_hours(): void
    {
        foreach (range(1, 4) as $i) {
            $this->lead($this->alice, 'CanPro', ['status' => 'active', 'contact_time' => '10', 'feedback' => 'no_verbal_conv']);
        }
        $this->lead($this->alice, 'CanPro', ['status' => 'active', 'contact_time' => '10', 'repeat_purchase' => 'yes', 'feedbacks' => ['still_have_stocks', 'purchased']]);
        $this->lead($this->bea, 'Sinuxyl', ['status' => 'busy_callback', 'feedback' => 'still_have_stocks']);
        $this->lead($this->bea, 'Sinuxyl', ['feedback' => 'blocked', 'status' => 'blocked']);
        $this->lead($this->bea, 'Sinuxyl');
        $this->lead($this->bea, 'Sinuxyl', [], '2026-09-20'); // other month

        $this->actingAs($this->owner)->get(route('product-performance.segmentation-summary'))
            ->assertOk()
            ->assertSeeInOrder(['Products', 'Segmentation Summary'])
            ->assertViewHas('summary', fn (array $s) => $s['leads'] === 8 && $s['catered'] === 7 && $s['pending'] === 1 && $s['converted'] === 1
                && $s['feedback_picks']->sortKeys()->all() === ['blocked' => 1, 'no_verbal_conv' => 4, 'purchased' => 1, 'still_have_stocks' => 2]
                && $s['feedback_picks_by_product']['Sinuxyl']->sortKeys()->all() === ['blocked' => 1, 'still_have_stocks' => 1]
                && $s['feedback_picks_by_product']['CanPro']->sortKeys()->all() === ['no_verbal_conv' => 4, 'purchased' => 1, 'still_have_stocks' => 1]
                && $s['feedback_reasons_by_product']['CanPro']['customers'] === 5 && $s['feedback_reasons_by_product']['CanPro']['shipments'] === 5
                && $s['feedback_reasons_by_product']['CanPro']['qty'] === 5
                && $s['feedback_reasons_by_product']['CanPro']['reasons']->all() === [
                    'no_verbal_conv' => ['customers' => 4, 'shipments' => 4, 'qty' => 4], 'purchased' => ['customers' => 1, 'shipments' => 1, 'qty' => 1],
                    'still_have_stocks' => ['customers' => 1, 'shipments' => 1, 'qty' => 1],
                ]
                && $s['hours']['10'] === ['label' => '10:00AM-11:00AM', 'contacts' => 5, 'converted' => 1])
            ->assertDontSee('Call Outcome')
            ->assertSee("Customer's Feedback")
            ->assertSee('NO VERBAL CONV: 4 (50%)')
            ->assertSee('STILL HAVE STOCKS: 2 (25%)')
            ->assertSee('Other (BLOCKED)')
            ->assertSee('By product')
            ->assertSeeInOrder(['Feedback by product', 'CanPro', 'NO VERBAL CONV', 'Sinuxyl'])
            ->assertDontSee('Insights');

        // Filters narrow it: one CRA and one product.
        $this->actingAs($this->owner)->get(route('product-performance.segmentation-summary', ['cra' => $this->bea->id, 'product' => 'Sinuxyl']))
            ->assertViewHas('summary', fn (array $s) => $s['leads'] === 3 && $s['converted'] === 0);
    }

    public function test_no_verbal_conv_reasons_group_the_notes_by_keyword(): void
    {
        $this->lead($this->alice, 'CanPro', ['status' => 'pjr_drop_call', 'feedback' => 'no_verbal_conv', 'notes' => 'cx ended the call', 'contact_date' => '2026-10-03']);
        $this->lead($this->alice, 'CanPro', ['status' => 'pjr_drop_call', 'feedback' => 'no_verbal_conv', 'notes' => 'Ringing, call end']);
        $this->lead($this->bea, 'Sinuxyl', ['status' => 'pjr_drop_call', 'feedback' => 'no_verbal_conv', 'notes' => 'PJR 3x']);
        $this->lead($this->bea, 'Sinuxyl', ['status' => 'inactive', 'feedback' => 'no_verbal_conv', 'notes' => 'CBR']);
        $this->lead($this->bea, 'Sinuxyl', ['status' => 'inactive', 'feedback' => 'no_verbal_conv', 'notes' => 'nag sorry daw']);
        $this->lead($this->bea, 'Sinuxyl', ['status' => 'inactive', 'feedback' => 'no_verbal_conv']);
        $this->lead($this->bea, 'Sinuxyl', ['feedback' => 'no_verbal_conv']);
        $this->lead($this->bea, 'Sinuxyl', ['status' => 'inactive', 'feedback' => 'no_verbal_conv', 'notes' => 'Unattended']);
        $this->lead($this->bea, 'Sinuxyl', ['status' => 'inactive', 'feedback' => 'no_verbal_conv', 'notes' => 'voicemail']);
        $this->lead($this->bea, 'Sinuxyl', ['status' => 'active', 'feedback' => 'no_budget', 'notes' => 'cx ended the call']);
        // Ticked as a second choice still counts.
        $this->lead($this->bea, 'Sinuxyl', ['status' => 'active', 'feedbacks' => ['no_budget', 'no_verbal_conv'], 'notes' => 'wrong number']);

        $this->actingAs($this->owner)->get(route('product-performance.segmentation-summary'))->assertOk()
            ->assertViewHas('summary', fn (array $s) => collect($s['no_verbal']['reasons'])->pluck('total', 'key')->sortKeys()->all() === [
                'cbr' => 1, 'ended' => 2, 'no_note' => 1, 'other' => 2, 'pjr' => 1, 'status_inactive' => 1, 'unattended' => 1, 'wrong_number' => 1,
            ] && collect($s['no_verbal']['reasons'])->firstWhere('key', 'status_inactive')['label'] === 'Inactive'
                && count($s['no_verbal']['notes']) === 10)
            ->assertSee('NO VERBAL CONV · why?')
            ->assertSeeInOrder(['Why no verbal conversation', '10', 'Back to chart', 'Customer ended / dropped the call', '2', '20%'])
            ->assertSeeInOrder(['Alice · Oct 3 · PJR/Inactive/CBR/Drop call', '“cx ended the call”']);

        // The filters apply: Bea's leads only.
        $this->actingAs($this->owner)->get(route('product-performance.segmentation-summary', ['cra' => $this->bea->id]))
            ->assertViewHas('summary', fn (array $s) => count($s['no_verbal']['notes']) === 8);
    }

    public function test_summary_moved_from_the_tracker_to_product_performance(): void
    {
        $this->actingAs($this->owner)->get('/segmentation/overview')->assertRedirect('/product-performance/segmentation-summary');
        $this->actingAs($this->owner)->get(route('segmentation.index'))->assertOk()
            ->assertSee('Weekly Segmentation')->assertDontSee('href="'.url('/segmentation/overview').'"', false);

        // Supervisors open it from Product Performance.
        $supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $this->actingAs($supervisor)->get(route('product-performance.segmentation-summary'))->assertOk();
    }

    public function test_a_cra_sees_only_their_own_leads(): void
    {
        $this->lead($this->alice, 'CanPro');
        $this->lead($this->bea, 'CanPro');
        $this->lead($this->bea, 'CanPro');

        // Product Performance access without the tracker's view-all.
        $this->alice->role->update(['permissions' => [...$this->alice->role->permissions, 'product_performance.view']]);
        $this->actingAs($this->alice->fresh())->get(route('product-performance.segmentation-summary', ['cra' => $this->bea->id]))
            ->assertOk()
            ->assertViewHas('summary', fn (array $s) => $s['leads'] === 1)
            ->assertDontSee('All CRAs');
    }
}
