<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Tests\TestCase;

class WorkingDateTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shecom.key' => 'test-key']);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00', 'Asia/Manila'));
        $this->supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
    }

    private function lead(string $orderId, string $day, string $name): void
    {
        Lead::create([
            'order_id' => $orderId, 'customer_name' => $name, 'phone_number' => '9171111111', 'product_name' => 'Sinuxyl',
            'qty' => 1, 'delivered_date' => '2026-08-01', 'consumption_days' => 30, 'est_out_of_stock_date' => $day,
            'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $this->supervisor->id,
        ]);
    }

    public function test_the_working_date_starts_tomorrow_and_moves_forward_with_each_real_day(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/management/retention-stockout' => Http::response(['stock_outs' => []])]);
        $this->lead('1', '2026-09-07', 'September Seventh Customer');
        $this->lead('2', '2026-09-08', 'September Eighth Customer');
        $this->lead('3', '2026-10-08', 'October Customer');

        $this->actingAs($this->supervisor)->put(route('settings.working-date.update'), ['start' => '2026-09-08'])
            ->assertSessionHasNoErrors();

        // Saved on Oct 7: today shows the day before the start.
        $this->assertTrue(Lead::today()->isSameDay('2026-09-07'));
        $this->get(route('segmentation.index'))
            ->assertSee('Working date: Sep 7, 2026')
            ->assertSee('September Seventh Customer')
            ->assertDontSee('October Customer');

        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00', 'Asia/Manila'));
        Once::flush();

        $this->assertTrue(Lead::today()->isSameDay('2026-09-08'));
        $this->get(route('segmentation.index'))->assertSee('September Eighth Customer');

        $this->put(route('settings.working-date.update'), ['start' => '']);

        $this->get(route('segmentation.index'))
            ->assertDontSee('Working date:')
            ->assertSee('October Customer');
    }

    public function test_a_working_date_saved_without_an_anchor_counts_from_the_day_it_was_saved(): void
    {
        Setting::put('working_date', '2026-09-07');
        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00', 'Asia/Manila'));

        $this->assertTrue(Lead::today()->isSameDay('2026-09-09'));
    }

    public function test_the_start_cannot_be_after_tomorrow(): void
    {
        $this->actingAs($this->supervisor)->put(route('settings.working-date.update'), ['start' => '2026-10-09'])
            ->assertSessionHasErrors('start');
    }

    public function test_cras_cannot_change_the_working_date(): void
    {
        $cra = User::create(['email' => 'lhea@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);

        $this->actingAs($cra)->put(route('settings.working-date.update'), ['start' => '2026-09-08'])->assertForbidden();
    }
}
