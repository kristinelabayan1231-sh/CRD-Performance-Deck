<?php

namespace Tests\Feature;

use App\Models\PancakeOrder;
use App\Services\PancakeSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class BackfillMissingDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-05-06 10:00', 'Asia/Manila'));
        // May 2 has a normal day's orders; May 1, 3, 4 and 5 are missing (May 4 has only one).
        foreach (range(1, 3) as $i) {
            PancakeOrder::create(['pancake_order_id' => "A{$i}", 'ordered_on' => '2026-05-02', 'status' => 3, 'total_price' => 100]);
        }
        PancakeOrder::create(['pancake_order_id' => 'B1', 'ordered_on' => '2026-05-04', 'status' => 3, 'total_price' => 100]);
    }

    public function test_it_fetches_each_missing_past_day_newest_first_and_only_once(): void
    {
        $fetched = [];
        $sync = Mockery::mock(PancakeSync::class);
        $sync->shouldReceive('sync')->andReturnUsing(function (CarbonImmutable $day) use (&$fetched) {
            $fetched[] = $day->toDateString();

            return ['orders' => 0, 'staff' => 0, 'sales' => 0];
        });
        $this->app->instance(PancakeSync::class, $sync);

        $this->artisan('pancake:backfill-missing', ['--from' => '2026-05-01', '--min' => 2])->assertSuccessful();

        // Today (May 6) is left to the regular sync; May 2 has enough orders.
        $this->assertSame(['2026-05-05', '2026-05-04', '2026-05-03', '2026-05-01'], $fetched);

        // A second run (next deploy) doesn't fetch them again, though they still have few orders.
        $this->artisan('pancake:backfill-missing', ['--from' => '2026-05-01', '--min' => 2])
            ->expectsOutput('Pancake backfill: no missing days.')
            ->assertSuccessful();
        $this->assertCount(4, $fetched);
    }

    public function test_a_failed_day_is_tried_again_and_a_second_run_stops_while_one_is_going(): void
    {
        $sync = Mockery::mock(PancakeSync::class);
        $sync->shouldReceive('sync')->andThrow(new \RuntimeException('Pancake is down'));
        $this->app->instance(PancakeSync::class, $sync);

        $this->artisan('pancake:backfill-missing', ['--from' => '2026-05-05', '--min' => 2])->assertFailed();
        $this->artisan('pancake:backfill-missing', ['--from' => '2026-05-05', '--dry-run' => true, '--min' => 2])
            ->expectsOutput('1 days to fetch: 2026-05-05')
            ->assertSuccessful();

        Cache::put('pancake:backfill-missing:running', true, 60);
        $this->artisan('pancake:backfill-missing', ['--from' => '2026-05-05', '--min' => 2])
            ->expectsOutput('Pancake backfill: already running.')
            ->assertSuccessful();
    }
}
