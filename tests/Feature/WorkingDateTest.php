<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_the_app_shows_the_working_date_as_today_until_it_is_cleared(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/management/retention-stockout' => Http::response(['stock_outs' => []])]);
        $this->lead('1', '2026-09-07', 'September Customer');
        $this->lead('2', '2026-10-07', 'October Customer');

        $this->actingAs($this->supervisor)->put(route('settings.working-date.update'), ['working_date' => '2026-09-07'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Lead::today()->isSameDay('2026-09-07'));
        $this->get(route('segmentation.index'))
            ->assertSee('Working date: Sep 7, 2026')
            ->assertSee('September Customer')
            ->assertDontSee('October Customer');

        $this->put(route('settings.working-date.update'), ['working_date' => '']);

        $this->get(route('segmentation.index'))
            ->assertDontSee('Working date:')
            ->assertSee('October Customer');
    }

    public function test_the_working_date_cannot_be_in_the_future(): void
    {
        $this->actingAs($this->supervisor)->put(route('settings.working-date.update'), ['working_date' => '2026-10-08'])
            ->assertSessionHasErrors('working_date');
    }

    public function test_cras_cannot_change_the_working_date(): void
    {
        $cra = User::create(['email' => 'lhea@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);

        $this->actingAs($cra)->put(route('settings.working-date.update'), ['working_date' => '2026-09-07'])->assertForbidden();
    }
}
