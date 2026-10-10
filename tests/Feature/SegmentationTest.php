<?php

namespace Tests\Feature;

use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SegmentationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shecom.key' => 'test-key', 'segmentation.leads_per_cra' => 2]);
        $this->day = CarbonImmutable::parse('2026-10-05');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Manila'));

        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
    }

    private function cra(string $email): User
    {
        return User::create(['email' => $email, 'name' => ucfirst(strtok($email, '@')), 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
    }

    /**
     * An order whose stock runs out on $outDate (qty 1, 15 days per unit).
     */
    private function row(string $orderId, string $phone, string $outDate, string $product = 'Pterygium Drops', int $qty = 1, int $days = 15): array
    {
        $delivered = CarbonImmutable::parse($outDate)->subDays($qty * $days - 1)->toDateString();

        return [
            'order_id' => $orderId, 'tracking_number' => "JT{$orderId}", 'customer_name' => "Customer {$orderId}",
            'phone_number' => $phone, 'product_name' => $product, 'qty' => $qty, 'delivered_date' => $delivered,
            'consumption_days_per_unit' => $days, 'estimated_out_of_stock_date' => $outDate,
        ];
    }

    private function fakeApi(array $rows, array $fsdOrders = []): void
    {
        Http::fake(['*/management/retention-stockout' => Http::response(['count' => count($rows), 'stock_outs' => $rows, 'retention_detail' => $fsdOrders])]);
    }

    public function test_crd_leads_are_todays_crd_stockouts_and_fsd_leads_come_from_fsd_deliveries(): void
    {
        Product::create(['name' => 'Sinuxyl', 'consumption_days' => 30]);
        // Pancake knows FSD order f1 was 2 units: Aug 7 + 2×30 − 1 = Oct 5.
        // Real order IDs are numeric strings (they must stay keys when looked up).
        DeliveredOrder::create(['order_id' => '1350001', 'customer_name' => 'Fe', 'phone_number' => '9178888888', 'product_raw' => 'Sinuxyl',
            'qty' => 2, 'delivered_date' => '2026-08-07', 'source' => DeliveredOrder::SOURCE_PANCAKE]);
        // Pancake was synced for Sep 6 too (another customer), but never for Sep 5.
        DeliveredOrder::create(['order_id' => 'x9', 'customer_name' => 'Other', 'phone_number' => '9179999999', 'product_raw' => 'Sinuxyl',
            'qty' => 1, 'delivered_date' => '2026-09-06', 'source' => DeliveredOrder::SOURCE_PANCAKE]);
        $fsd = fn (string $id, string $delivered) => ['order_id' => $id, 'tracking_number' => "JT{$id}", 'customer_name' => "Customer {$id}",
            'phone_number' => '917'.str_pad(substr(md5($id), 0, 7), 7, '0'), 'product' => 'Sinuxyl', 'delivered_date' => $delivered];

        $this->fakeApi([
            $this->row('1', '9171111111', '2026-10-05'),
            $this->row('4', '9173333333', '2026-10-06'),       // not today
        ], [
            $fsd('1350001', '2026-08-07'),   // 2 units (Pancake) -> today
            $fsd('f2', '2026-09-06'),   // Pancake has no qty: 1 unit assumed -> today, flagged
            $fsd('f3', '2026-08-07'),   // no qty: 1 unit would run out Sep 5 -> not today
            $fsd('f4', '2026-09-07'),   // runs out Oct 6 -> not today
            $fsd('f5', '2026-09-05'),   // day not synced from Pancake yet -> waits
        ]);

        $result = app(LeadGenerator::class)->generate($this->day);

        $this->assertSame(['1', '1350001', 'f2'], Lead::orderBy('order_id')->pluck('order_id')->all());
        $this->assertSame([1, 2], [$result['crd'], $result['fsd']]);
        $this->assertSame(Lead::TYPE_CRD, Lead::firstWhere('order_id', '1')->lead_type);
        $this->assertSame([Lead::TYPE_FSD, 2, false], [Lead::firstWhere('order_id', '1350001')->lead_type, Lead::firstWhere('order_id', '1350001')->qty, Lead::firstWhere('order_id', '1350001')->qty_unknown]);
        $this->assertSame([1, true], [Lead::firstWhere('order_id', 'f2')->qty, Lead::firstWhere('order_id', 'f2')->qty_unknown]);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key'));

        // Managers see the leads Pancake had no quantity for.
        $this->actingAs($this->owner)->get(route('dashboard'))->assertSee('1 FSD lead with no quantity in Pancake')->assertSee('Customer f2');
    }

    public function test_a_successful_sync_keeps_a_copy_of_every_delivered_order(): void
    {
        $this->fakeApi([
            $this->row('1', '9171111111', '2026-10-05'),
            $this->row('2', '9172222222', '2026-11-30'),
        ]);

        app(LeadGenerator::class)->generate($this->day);

        $this->assertSame(['1', '2'], DeliveredOrder::orderBy('order_id')->pluck('order_id')->all());
        $this->assertSame(15, DeliveredOrder::firstWhere('order_id', '2')->consumption_days_per_unit);
    }

    public function test_when_the_retention_api_is_down_leads_come_from_saved_orders_and_product_consumption(): void
    {
        Http::fake(['*/management/retention-stockout' => Http::response(['status' => 'error', 'message' => 'Application failed to respond'], 502)]);
        $alice = $this->cra('alice@gmail.com');
        Product::create(['name' => 'Pterygium', 'consumption_days' => 10]);

        $saved = fn (string $id, string $phone, string $delivered, int $qty, ?int $days, string $product = 'Pterygium Drops') => DeliveredOrder::create([
            'order_id' => $id, 'customer_name' => "Customer {$id}", 'phone_number' => $phone, 'product_raw' => $product,
            'qty' => $qty, 'delivered_date' => $delivered, 'consumption_days_per_unit' => $days, 'source' => DeliveredOrder::SOURCE_PANCAKE,
        ]);
        // Product Consumption says 10 days: Sep 16 + 2×10 − 1 = Oct 5. The saved 15 days is ignored.
        $saved('p1', '9171111111', '2026-09-16', 2, 15);
        // No matching product: the saved 15 days apply. Sep 21 + 15 − 1 = Oct 5.
        $saved('p2', '9172222222', '2026-09-21', 1, 15, 'Mystery Serum');
        // Neither: skipped.
        $saved('p3', '9173333333', '2026-09-21', 1, null, 'Mystery Serum');
        // Runs out Oct 6: not today's lead.
        $saved('p4', '9174444444', '2026-09-27', 1, null);
        // Already a lead with a different date and an assignment: left exactly as it is.
        $saved('p5', '9175555555', '2026-09-26', 1, null);
        $existing = Lead::create([
            'order_id' => 'p5', 'customer_name' => 'Kept', 'phone_number' => '9175555555', 'product_name' => 'Pterygium', 'qty' => 1,
            'delivered_date' => '2026-09-20', 'consumption_days' => 15, 'est_out_of_stock_date' => '2026-10-04',
            'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $alice->id, 'status' => 'active',
        ]);

        $result = app(LeadGenerator::class)->generate($this->day);

        $this->assertSame('fallback', $result['source']);
        $this->assertSame(['p1', 'p2'], Lead::whereDate('est_out_of_stock_date', '2026-10-05')->orderBy('order_id')->pluck('order_id')->all());
        $this->assertSame(10, Lead::firstWhere('order_id', 'p1')->consumption_days);
        $this->assertSame('2026-10-04', $existing->fresh()->est_out_of_stock_date->toDateString());
        $this->assertSame('Kept', $existing->fresh()->customer_name);
        $this->assertSame($alice->id, $existing->fresh()->assigned_to);

        $this->actingAs($this->owner)->get('/segmentation?date=2026-10-05')->assertSee('Backup mode');
    }

    public function test_without_saved_orders_the_retention_api_error_still_shows(): void
    {
        Http::fake(['*/management/retention-stockout' => Http::response(['error' => 'down'], 502)]);

        $this->expectExceptionMessage('Retention API returned HTTP 502');

        app(LeadGenerator::class)->generate($this->day);
    }

    public function test_crd_leads_first_up_to_quota_then_excess_spread_evenly(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $bob = $this->cra('bob@gmail.com');

        // 3 CRD (repeat phones) + 4 New, quota 2 each => 7 leads over 2 CRAs.
        $rows = [];
        foreach (['a', 'b', 'c'] as $i => $p) {
            $rows[] = $this->row("c{$i}", "91700000{$i}0", '2026-10-05');
            $rows[] = $this->row("c{$i}-old", "91700000{$i}0", '2026-07-01');
            $rows[] = $this->row("c{$i}-older", "91700000{$i}0", '2026-05-01');
        }
        foreach (range(1, 4) as $i) {
            $rows[] = $this->row("n{$i}", "91800000{$i}0", '2026-10-05');
        }
        $this->fakeApi($rows);

        $result = app(LeadGenerator::class)->generate($this->day);

        $this->assertSame(7, $result['found']);
        $this->assertSame(0, $result['unassigned']);
        $counts = Lead::whereDate('est_out_of_stock_date', $this->day)->get()->countBy('assigned_to');
        $this->assertEqualsCanonicalizing([4, 3], $counts->values()->all());

        // CRD Leads were handed out before any FSD Lead: both CRAs got CRD first.
        $firstTwo = Lead::orderBy('assigned_at')->orderBy('id')->take(3)->pluck('lead_type')->unique()->all();
        $this->assertSame([Lead::TYPE_CRD], $firstTwo);
        $this->assertTrue(Lead::where('lead_type', Lead::TYPE_CRD)->pluck('assigned_to')->contains($alice->id));
        $this->assertTrue(Lead::where('lead_type', Lead::TYPE_CRD)->pluck('assigned_to')->contains($bob->id));
    }

    public function test_resync_keeps_assignment_and_status(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05')]);

        app(LeadGenerator::class)->generate($this->day);
        $lead = Lead::firstWhere('order_id', '1');
        // A CRD type set by the sheet import must survive the order-count rule.
        $lead->update(['status' => 'busy_callback', 'lead_type' => Lead::TYPE_CRD]);
        $this->cra('bob@gmail.com');

        $result = app(LeadGenerator::class)->generate($this->day);

        $this->assertSame(0, $result['created']);
        $this->assertSame($alice->id, $lead->fresh()->assigned_to);
        $this->assertSame('busy_callback', $lead->fresh()->status);
        $this->assertSame(Lead::TYPE_CRD, $lead->fresh()->lead_type);
        $this->assertSame(1, Lead::count());
    }

    public function test_out_of_stock_date_comes_from_the_api_and_products_are_grouped(): void
    {
        Product::create(['name' => 'Pterygium']);

        // The API's own date is used as given, even if it differs from delivered + days.
        $row = $this->row('1', '9171111111', '2026-10-05');
        $row['delivered_date'] = '2026-09-01';
        $row['product_name'] = 'Pterygium Eye Drops';
        $this->fakeApi([$row]);

        app(LeadGenerator::class)->generate($this->day);

        $lead = Lead::firstWhere('order_id', '1');
        $this->assertSame('2026-10-05', $lead->est_out_of_stock_date->toDateString());
        $this->assertSame(['Pterygium', 'Pterygium Eye Drops'], [$lead->product_name, $lead->product_raw]);
    }

    public function test_page_shows_sheet_columns_and_filters(): void
    {
        $this->cra('alice@gmail.com');
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05', 'Audicure', qty: 5, days: 15)]);
        app(LeadGenerator::class)->generate($this->day);

        $this->actingAs($this->owner)->get('/segmentation')
            ->assertOk()
            ->assertSeeInOrder(['Customer Name', 'Qty', 'Product', 'Contact #', 'Delivered Date', 'Days since Delivered', 'Est. Out of Stock', 'Recommended Replenishment Day', 'Assigned to', 'Status'])
            ->assertSee('Customer 1')
            ->assertSee('Sep 28, 2026') // replenishment day = out date - 7
            ->assertSee('Alice');

        $this->actingAs($this->owner)->get('/segmentation?month=2026-09')->assertOk()->assertDontSee('Customer 1');
        $this->actingAs($this->owner)->get('/segmentation?cra=unassigned&date=2026-10-05')->assertOk()->assertDontSee('Customer 1');
    }

    public function test_cra_sees_only_own_leads_and_can_set_status(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $bob = $this->cra('bob@gmail.com');
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05'), $this->row('2', '9172222222', '2026-10-05')]);
        app(LeadGenerator::class)->generate($this->day);

        $mine = Lead::where('assigned_to', $alice->id)->first();
        $theirs = Lead::where('assigned_to', $bob->id)->first();

        $this->actingAs($alice)->get('/segmentation?cra=all')
            ->assertOk()->assertSee($mine->customer_name)->assertDontSee($theirs->customer_name)->assertDontSee('Sync leads');

        $this->actingAs($alice)->patch("/segmentation/leads/{$mine->id}", ['status' => 'repeat_purchase'])->assertSessionHasNoErrors();
        $this->assertSame('repeat_purchase', $mine->fresh()->status);
        $this->assertSame($alice->id, $mine->fresh()->status_updated_by);

        $this->actingAs($alice)->patch("/segmentation/leads/{$theirs->id}", ['status' => 'blocked'])->assertForbidden();
        $this->actingAs($alice)->patch("/segmentation/leads/{$mine->id}", ['assigned_to' => $bob->id])->assertForbidden();
        $this->actingAs($alice)->patch("/segmentation/leads/{$mine->id}", ['status' => 'made-up'])->assertSessionHasErrors('status');
        $this->actingAs($alice)->post('/segmentation/sync', ['date' => '2026-10-05'])->assertForbidden();
    }

    public function test_manager_can_reassign_only_to_cras_and_sync(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $bob = $this->cra('bob@gmail.com');
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05')]);

        $this->actingAs($this->owner)->post('/segmentation/sync', ['date' => '2026-10-05'])
            ->assertRedirect('/segmentation?date=2026-10-05')->assertSessionHas('status');

        $lead = Lead::first();
        $this->actingAs($this->owner)->patch("/segmentation/leads/{$lead->id}", ['assigned_to' => $bob->id]);
        $this->assertSame($bob->id, $lead->fresh()->assigned_to);

        $this->actingAs($this->owner)->patch("/segmentation/leads/{$lead->id}", ['assigned_to' => $this->owner->id])->assertSessionHasErrors('assigned_to');
        $this->assertSame($bob->id, $lead->fresh()->assigned_to);
    }

    public function test_sync_reports_api_errors(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid or missing API key.'], 401)]);

        $this->actingAs($this->owner)->post('/segmentation/sync', ['date' => '2026-10-05'])->assertSessionHasErrors('sync');
        $this->assertSame(0, Lead::count());
    }

    public function test_cra_can_fill_tracking_fields_via_json(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05')]);
        app(LeadGenerator::class)->generate($this->day);
        $lead = Lead::first();

        $this->actingAs($alice)->patchJson("/segmentation/leads/{$lead->id}", [
            'repeat_purchase' => 'reserve',
            'customer_tag' => 'canpro_warm',
            'contact_date' => '2026-10-05',
            'contact_time' => '11',
            'callback_date' => '2026-10-08',
        ])->assertOk()->assertJson(['saved' => true]);

        $lead->refresh();
        $this->assertSame(['reserve', 'canpro_warm', '11'], [$lead->repeat_purchase, $lead->customer_tag, $lead->contact_time]);
        $this->assertSame('2026-10-05', $lead->contact_date->toDateString());
        $this->assertSame('2026-10-08', $lead->callback_date->toDateString());

        // Clearing a field works; invalid values are rejected.
        $this->actingAs($alice)->patchJson("/segmentation/leads/{$lead->id}", ['customer_tag' => null])->assertOk();
        $this->assertNull($lead->fresh()->customer_tag);
        $this->actingAs($alice)->patchJson("/segmentation/leads/{$lead->id}", ['contact_time' => '23'])->assertUnprocessable();
        $this->actingAs($alice)->patchJson("/segmentation/leads/{$lead->id}", ['repeat_purchase' => 'maybe'])->assertUnprocessable();
        $this->actingAs($alice)->patchJson("/segmentation/leads/{$lead->id}", ['contact_date' => 'tomorrow'])->assertUnprocessable();

        // Feedback only accepts the configured choices.
        $this->actingAs($alice)->patchJson("/segmentation/leads/{$lead->id}", ['feedback' => 'happy'])->assertUnprocessable();
        $this->actingAs($alice)->patchJson("/segmentation/leads/{$lead->id}", ['feedback' => 'no_budget'])->assertOk();
        $this->assertSame('NO BUDGET', Lead::optionLabel('feedback', $lead->fresh()->feedback));
    }

    public function test_notes_can_be_added_edited_and_deleted(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $bob = $this->cra('bob@gmail.com');
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05'), $this->row('2', '9172222222', '2026-10-05')]);
        app(LeadGenerator::class)->generate($this->day);
        $mine = Lead::where('assigned_to', $alice->id)->first();
        $theirs = Lead::where('assigned_to', $bob->id)->first();

        $this->actingAs($alice)->patchJson("/segmentation/leads/{$mine->id}", ['notes' => "  Prefers calls after 5pm\nAsk about CanPro  "])
            ->assertOk()->assertJson(['notes' => "Prefers calls after 5pm\nAsk about CanPro"])
            ->assertJsonPath('notes_meta', fn ($meta) => str_starts_with($meta, 'Alice'));
        $this->assertSame($alice->id, $mine->fresh()->notes_updated_by);

        $this->actingAs($alice)->get('/segmentation')->assertSee('Prefers calls after 5pm');

        $this->actingAs($alice)->patchJson("/segmentation/leads/{$theirs->id}", ['notes' => 'nope'])->assertForbidden();

        $this->actingAs($alice)->patchJson("/segmentation/leads/{$mine->id}", ['notes' => ''])->assertOk()->assertJson(['notes' => null]);
        $this->assertNull($mine->fresh()->notes);
        $this->assertNull($mine->fresh()->notes_updated_by);
    }

    public function test_optional_columns_render_and_coming_soon_column_is_listed_only(): void
    {
        $this->cra('alice@gmail.com');
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05')]);
        app(LeadGenerator::class)->generate($this->day);

        $response = $this->actingAs($this->owner)->get('/segmentation')->assertOk();

        $response->assertSeeInOrder(['Status', 'Repeat Purchase?', 'Customer Tagging', 'Date of Contact', 'Time of Contact', 'Customer&#039;s Feedback', 'Callback Date'], false);
        $response->assertSee('data-column-toggle="call_recording_url"', false)->assertSee('Coming soon');
        $response->assertDontSee('data-col="call_recording_url"', false);
        $response->assertSee('Hot Leads / Recent Buyers (0 to 15 days)')->assertSee('11:00AM-12:00NN')->assertSee('STOPPED BY THE DR.')->assertSee('PURCHASED');
    }

    public function test_the_day_is_split_into_unprocessed_then_processed_and_show_narrows_it(): void
    {
        Http::fake(['*/management/retention-stockout' => Http::response(['stock_outs' => []])]);
        $alice = $this->cra('alice@gmail.com');
        $make = fn (string $id, string $name, array $fields = []) => Lead::create([
            'order_id' => $id, 'customer_name' => $name, 'phone_number' => '917'.$id, 'product_name' => 'Sinuxyl', 'qty' => 1,
            'delivered_date' => '2026-09-05', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-10-05',
            'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $alice->id, ...$fields,
        ]);
        $make('1', 'Waiting Wendy');
        $make('2', 'Status Sam', ['status' => 'active']);
        $make('3', 'Called Carla', ['contact_date' => '2026-10-05']);
        // Earlier day, still unprocessed: carry-over is off, so it isn't listed.
        Lead::create(['order_id' => '4', 'customer_name' => 'Yesterday Yuri', 'phone_number' => '9174', 'product_name' => 'Sinuxyl', 'qty' => 1,
            'delivered_date' => '2026-09-04', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-10-04',
            'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $alice->id]);

        $this->actingAs($alice)->get('/segmentation')->assertOk()
            ->assertSeeInOrder(['Pending', 'Waiting Wendy', 'Catered', 'Called Carla', 'Status Sam'])
            ->assertDontSee('Yesterday Yuri');

        $this->actingAs($alice)->get('/segmentation?show=processed')
            ->assertSee('Status Sam')->assertDontSee('Waiting Wendy');

        // Each list opens in full in a pop-up: just that list, no tiles or filters.
        $this->actingAs($alice)->get('/segmentation')->assertSee('Open Pending in full view')->assertSee('id="expand-dialog"', false);
        $this->actingAs($alice)->get('/segmentation?show=unprocessed&full=1')->assertOk()
            ->assertSee('Waiting Wendy')->assertDontSee('Status Sam')->assertDontSee('data-live-summary', false);
    }

    public function test_product_filter_narrows_the_lists_and_tiles(): void
    {
        Http::fake(['*/management/retention-stockout' => Http::response(['stock_outs' => []])]);
        $alice = $this->cra('alice@gmail.com');
        Product::create(['name' => 'Sinuxyl']);
        Product::create(['name' => 'CanPro']);
        foreach ([['1', 'Sinus Sam', 'Sinuxyl'], ['2', 'Canny Carla', 'CanPro'], ['3', 'Canny Cora', 'CanPro']] as [$id, $name, $product]) {
            Lead::create(['order_id' => $id, 'customer_name' => $name, 'phone_number' => '917'.$id, 'product_name' => $product, 'qty' => 1,
                'delivered_date' => '2026-09-05', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-10-05',
                'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $alice->id]);
        }

        $this->actingAs($this->owner)->get('/segmentation')->assertOk()
            ->assertSee('All products')->assertSee('<option value="CanPro"', false)
            ->assertDontSee('aria-label="Show"', false);

        $this->actingAs($this->owner)->get('/segmentation?product=CanPro')->assertOk()
            ->assertSee('Canny Carla')->assertSee('Canny Cora')->assertDontSee('Sinus Sam')
            ->assertViewHas('tiles', fn (array $tiles) => $tiles['total']['value'] === '2');
    }

    public function test_choosing_pjr_sets_the_feedback_to_no_verbal_conv(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $lead = Lead::create([
            'order_id' => '1', 'customer_name' => 'Pat', 'phone_number' => '9171', 'product_name' => 'Sinuxyl', 'qty' => 1,
            'delivered_date' => '2026-09-05', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-10-05',
            'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $alice->id, 'customer_tag' => 'hot', 'feedback' => 'no_budget',
        ]);

        $this->actingAs($alice)->patchJson(route('segmentation.update', $lead), ['status' => 'active'])
            ->assertOk()->assertJson(['feedback' => 'no_budget']);

        // PJR replaces the feedback (a real Customer's Feedback option), and the response carries it so the row's
        // feedback cell can follow. Customer Tagging is left alone.
        $this->actingAs($alice)->patchJson(route('segmentation.update', $lead), ['status' => 'pjr_drop_call'])
            ->assertOk()->assertJson(['feedback' => 'no_verbal_conv']);
        $lead->refresh();
        $this->assertArrayHasKey($lead->feedback, config('segmentation.feedback'));
        $this->assertSame('no_verbal_conv', $lead->feedback);
        $this->assertSame('hot', $lead->customer_tag);
    }

    public function test_a_cra_searches_their_leads_across_every_lead_day(): void
    {
        Http::fake(['*/management/retention-stockout' => Http::response(['stock_outs' => []])]);
        $alice = $this->cra('alice@gmail.com');
        $bob = $this->cra('bob@gmail.com');
        $make = fn (string $id, string $name, string $phone, string $day, User $cra) => Lead::create([
            'order_id' => $id, 'customer_name' => $name, 'phone_number' => $phone, 'product_name' => 'Sinuxyl', 'qty' => 1,
            'delivered_date' => '2026-09-01', 'consumption_days' => 30, 'est_out_of_stock_date' => $day,
            'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $cra->id,
        ]);
        $make('1374748', 'Marcelina Dado', '9171234567', '2026-09-20', $alice);
        $make('2', 'Today Tess', '9179999999', '2026-10-05', $alice);
        $make('3', 'Marcelina Other', '9175550000', '2026-10-05', $bob);

        // By name, from a lead day other than the one on screen; Bob's lead stays hidden.
        $this->actingAs($alice)->get('/segmentation?q=marcelina')->assertOk()
            ->assertSee('Marcelina Dado')->assertDontSee('Marcelina Other')->assertDontSee('Today Tess')
            ->assertSee('Search results for');
        // By contact number in another format, and by order number.
        $this->actingAs($alice)->get('/segmentation?q='.urlencode('+63 917 123'))->assertSee('Marcelina Dado')->assertDontSee('Today Tess');
        $this->actingAs($alice)->get('/segmentation?q=1374748')->assertSee('Marcelina Dado');
        // Tiles count the search results.
        $this->actingAs($alice)->getJson(route('segmentation.summary', ['q' => 'marcelina']))->assertJsonPath('total.value', '1');
    }

    public function test_a_cra_marks_a_lead_processed_and_back_without_a_status_or_contact_date(): void
    {
        Http::fake(['*/management/retention-stockout' => Http::response(['stock_outs' => []])]);
        $alice = $this->cra('alice@gmail.com');
        $bob = $this->cra('bob@gmail.com');
        $make = fn (string $id, string $name, User $cra) => Lead::create([
            'order_id' => $id, 'customer_name' => $name, 'phone_number' => '917'.$id, 'product_name' => 'Sinuxyl', 'qty' => 1,
            'delivered_date' => '2026-09-05', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-10-05',
            'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $cra->id,
        ]);
        $wendy = $make('1', 'Waiting Wendy', $alice);
        $bobs = $make('2', 'Bobs Lead', $bob);

        // Unprocessed rows offer "Mark as processed" on right-click.
        $this->actingAs($alice)->get('/segmentation')->assertSee('data-mark="processed"', false);

        $this->actingAs($alice)->patchJson(route('segmentation.update', $wendy), ['processed' => true])->assertOk();

        $wendy->refresh();
        $this->assertSame($alice->id, $wendy->processed_by);
        $this->assertNull($wendy->status);
        $this->assertNull($wendy->contact_date);
        $this->actingAs($alice)->get('/segmentation?show=unprocessed')->assertDontSee('Waiting Wendy');
        // Marked rows offer "Mark as unprocessed".
        $this->actingAs($alice)->get('/segmentation?show=processed')->assertSee('Waiting Wendy')->assertSee('data-mark="unprocessed"', false);

        $this->actingAs($alice)->patchJson(route('segmentation.update', $wendy), ['processed' => false])->assertOk();
        $this->actingAs($alice)->get('/segmentation?show=unprocessed')->assertSee('Waiting Wendy');

        // A lead processed by its status and date of contact can be unmarked too: both are cleared (the page confirms first).
        $wendy->forceFill(['status' => 'active', 'contact_date' => '2026-10-05'])->save();
        $this->actingAs($alice)->get('/segmentation?show=processed')->assertSee('data-clears="status Active and date of contact Oct 5"', false);
        $this->actingAs($alice)->patchJson(route('segmentation.update', $wendy), ['processed' => false])->assertOk();
        $wendy->refresh();
        $this->assertNull($wendy->status);
        $this->assertNull($wendy->contact_date);
        $this->actingAs($alice)->get('/segmentation?show=unprocessed')->assertSee('Waiting Wendy');

        // Only the CRA holding the lead (or a manager) can mark it.
        $this->actingAs($alice)->patchJson(route('segmentation.update', $bobs), ['processed' => true])->assertForbidden();
        $this->assertNull($bobs->refresh()->processed_at);
    }

    public function test_a_cra_is_greeted_with_their_numbers_once_a_day(): void
    {
        Http::fake(['*/management/retention-stockout' => Http::response(['stock_outs' => []])]);
        $rose = User::create(['email' => 'rose@example.com', 'display_name' => 'Rose-An', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        foreach (['1' => null, '2' => null, '3' => 'active'] as $id => $status) {
            Lead::create(['order_id' => $id, 'customer_name' => "C{$id}", 'phone_number' => '917'.$id, 'product_name' => 'Sinuxyl', 'qty' => 1,
                'delivered_date' => '2026-09-05', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-10-05',
                'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $rose->id, 'status' => $status]);
        }

        $this->actingAs($rose)->get('/segmentation')->assertOk()
            ->assertSee('Hey Rose-An!')->assertSeeInOrder(['You have', '3', 'leads for today', '2', 'pending'], false);
        $this->actingAs($rose)->get('/segmentation')->assertDontSee('Hey Rose-An!');

        // Supervisors aren't greeted.
        $this->actingAs($this->owner)->get('/segmentation')->assertDontSee('id="greeting-dialog"', false);
    }

    public function test_opening_the_tracker_syncs_today_automatically_once_an_hour(): void
    {
        $this->cra('alice@gmail.com');
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05')]);

        // The sync runs after the page is sent; the next load shows the leads.
        $this->actingAs($this->owner)->get('/segmentation')->assertOk();
        $this->assertNotNull(Lead::first()->assigned_to);
        Http::assertSentCount(1);
        $this->actingAs($this->owner)->get('/segmentation')->assertSee('Customer 1')->assertSee('Auto-synced');

        // Fresh for an hour: no extra API calls.
        $this->travel(30)->minutes();
        $this->actingAs($this->owner)->get('/segmentation')->assertOk();
        Http::assertSentCount(1);

        $this->travel(31)->minutes();
        $this->actingAs($this->owner)->get('/segmentation')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_cras_also_trigger_the_automatic_sync(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05')]);

        $this->actingAs($alice)->get('/segmentation')->assertOk();

        $this->actingAs($alice)->get('/segmentation')->assertSee('Customer 1');
    }

    public function test_tracker_still_loads_when_automatic_sync_fails(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid or missing API key.'], 401)]);

        $this->actingAs($this->owner)->get('/segmentation')->assertOk();

        $this->actingAs($this->owner)->get('/segmentation')
            ->assertOk()
            ->assertSee("Automatic sync couldn't reach the retention API", false);
    }

    public function test_new_cra_gets_todays_unassigned_leads_immediately(): void
    {
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05'), $this->row('2', '9172222222', '2026-10-05')]);
        app(LeadGenerator::class)->generate($this->day);
        $this->assertSame(2, Lead::whereNull('assigned_to')->count());

        $this->actingAs($this->owner)->post('/user-access', [
            'email' => 'newcra@gmail.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id,
        ])->assertSessionHasNoErrors();

        $cra = User::firstWhere('email', 'newcra@gmail.com');
        $this->assertSame(2, Lead::where('assigned_to', $cra->id)->count());
    }

    public function test_promoting_an_existing_user_to_cra_assigns_leads(): void
    {
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05')]);
        app(LeadGenerator::class)->generate($this->day);
        $user = User::create(['email' => 'later@gmail.com', 'role_id' => Role::defaultUser()->id, 'is_active' => true]);

        $this->actingAs($this->owner)->patch("/user-access/{$user->id}", ['role_id' => Role::firstWhere('slug', Role::CRA)->id]);

        $this->assertSame($user->id, Lead::first()->assigned_to);
    }

    public function test_summary_tiles_include_per_cra_and_live_status_updated_count(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $this->cra('bob@gmail.com');
        $this->fakeApi([
            $this->row('1', '9171111111', '2026-10-05'),
            $this->row('2', '9172222222', '2026-10-05'),
            $this->row('3', '9173333333', '2026-10-05'),
        ]);
        app(LeadGenerator::class)->generate($this->day);

        $this->actingAs($this->owner)->get('/segmentation')->assertOk()
            ->assertSeeInOrder(['Leads', 'CRD Leads', 'FSD Leads', 'Per CRA', 'Status Updated'])
            ->assertSee('1–2')->assertSee('2 CRAs · base 2 each')->assertSee('of 3 · 3 pending · 0%');

        $this->actingAs($this->owner)->getJson('/segmentation/summary?date=2026-10-05')->assertOk()
            ->assertJsonPath('total.value', '3')
            ->assertJsonPath('per_cra.value', '1–2')
            ->assertJsonPath('updated.value', '0');

        // A CRA updates a status: the live count moves.
        $lead = Lead::where('assigned_to', $alice->id)->first();
        $this->actingAs($alice)->patchJson("/segmentation/leads/{$lead->id}", ['status' => 'active'])->assertOk();

        $this->actingAs($this->owner)->getJson('/segmentation/summary?date=2026-10-05')
            ->assertJsonPath('updated.value', '1')
            ->assertJsonPath('updated.note', 'of 3 · 2 pending · 33%');

        // Status filter on the table doesn't skew the tiles.
        $this->actingAs($this->owner)->getJson('/segmentation/summary?date=2026-10-05&status=active')->assertJsonPath('total.value', '3');
    }

    public function test_cra_summary_counts_only_their_leads_without_per_cra_tile(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $this->cra('bob@gmail.com');
        $this->fakeApi([$this->row('1', '9171111111', '2026-10-05'), $this->row('2', '9172222222', '2026-10-05')]);
        app(LeadGenerator::class)->generate($this->day);

        $this->actingAs($alice)->getJson('/segmentation/summary?cra=all')->assertOk()
            ->assertJsonPath('total.value', '1')
            ->assertJsonMissingPath('per_cra');
        $this->actingAs($alice)->get('/segmentation')->assertDontSee('Per CRA');
    }

    public function test_conversion_tile_counts_yes_or_no_with_purchased_feedback(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $rows = array_map(fn ($i) => $this->row((string) $i, '91700000'.$i.'0', '2026-10-05'), range(1, 8));
        $this->fakeApi($rows);
        app(LeadGenerator::class)->generate($this->day);
        [$a, $b, $c, $d, $e] = Lead::orderBy('id')->take(5)->get()->all();

        $a->update(['repeat_purchase' => 'yes']);                                   // converted
        $b->update(['repeat_purchase' => 'no', 'feedback' => 'purchased']);         // converted
        $c->update(['repeat_purchase' => 'no', 'feedback' => 'no_budget']);         // not
        $d->update(['repeat_purchase' => 'reserve', 'feedback' => 'purchased']);    // not
        $e->update(['feedback' => 'purchased']);                                    // not (no repeat purchase answer)

        $this->assertSame(2, Lead::converted()->count());
        $this->assertTrue($a->fresh()->isConverted());
        $this->assertFalse($d->fresh()->isConverted());

        $this->actingAs($this->owner)->getJson('/segmentation/summary?date=2026-10-05')
            ->assertJsonPath('converted.value', '25%')
            ->assertJsonPath('converted.note', '2 of 8 converted');

        $this->actingAs($this->owner)->get('/segmentation')->assertSee('Conversion')->assertSee('2 of 8 converted');
    }

    public function test_per_cra_tile_follows_the_cra_filter(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $this->cra('bob@gmail.com');
        $this->fakeApi(array_map(fn ($i) => $this->row((string) $i, '91700000'.$i.'0', '2026-10-05'), range(1, 3)));
        app(LeadGenerator::class)->generate($this->day);

        $this->actingAs($this->owner)->getJson('/segmentation/summary?date=2026-10-05&cra=all')
            ->assertJsonPath('per_cra.value', '1–2')->assertJsonPath('per_cra.note', '2 CRAs · base 2 each');

        $aliceCount = Lead::where('assigned_to', $alice->id)->count();
        $this->actingAs($this->owner)->getJson("/segmentation/summary?date=2026-10-05&cra={$alice->id}")
            ->assertJsonPath('per_cra.value', (string) $aliceCount)
            ->assertJsonPath('per_cra.note', 'Alice · base 2')
            ->assertJsonPath('total.value', (string) $aliceCount);

        // Month view: overall split for the month, no daily base.
        $this->actingAs($this->owner)->getJson('/segmentation/summary?month=2026-10')->assertJsonPath('per_cra.note', '2 CRAs');
    }

    public function test_per_cra_popup_lists_each_cra_and_flags_uneven_counts(): void
    {
        $alice = $this->cra('alice@gmail.com');
        $bob = $this->cra('bob@gmail.com');
        $carl = $this->cra('carl@gmail.com');
        // 7 leads over 3 CRAs => 3 / 2 / 2: one CRA is uneven.
        $this->fakeApi(array_map(fn ($i) => $this->row((string) $i, '91700000'.$i.'0', '2026-10-05'), range(1, 7)));
        app(LeadGenerator::class)->generate($this->day);

        $json = $this->actingAs($this->owner)->getJson('/segmentation/summary?date=2026-10-05')
            ->assertOk()
            ->assertJsonPath('per_cra.period', 'Oct 5, 2026')
            ->json('per_cra.breakdown');

        $this->assertSame(['Alice', 'Bob', 'Carl'], array_column($json, 'name'));
        $this->assertSame(7, array_sum(array_column($json, 'total')));
        $uneven = array_values(array_filter($json, fn ($row) => $row['odd']));
        $this->assertCount(1, $uneven);
        $this->assertSame(3, $uneven[0]['total']);

        // The breakdown covers every CRA even when the table is filtered to one.
        $this->actingAs($this->owner)->getJson("/segmentation/summary?date=2026-10-05&cra={$bob->id}")
            ->assertJsonCount(3, 'per_cra.breakdown');

        $this->actingAs($this->owner)->get('/segmentation')->assertOk()
            ->assertSee('data-per-cra-open', false)
            ->assertSee('Assigned per CRA')
            ->assertSee('Uneven');
    }

    public function test_even_split_has_no_uneven_flags(): void
    {
        $this->cra('alice@gmail.com');
        $this->cra('bob@gmail.com');
        $this->fakeApi(array_map(fn ($i) => $this->row((string) $i, '91700000'.$i.'0', '2026-10-05'), range(1, 4)));
        app(LeadGenerator::class)->generate($this->day);

        $breakdown = $this->actingAs($this->owner)->getJson('/segmentation/summary?date=2026-10-05')->json('per_cra.breakdown');

        $this->assertSame([2, 2], array_column($breakdown, 'total'));
        $this->assertSame([false, false], array_column($breakdown, 'odd'));
    }

    public function test_users_without_permission_are_blocked(): void
    {
        $plain = User::create(['email' => 'plain@gmail.com', 'role_id' => Role::defaultUser()->id, 'is_active' => true]);

        $this->actingAs($plain)->get('/segmentation')->assertForbidden();
    }
}
