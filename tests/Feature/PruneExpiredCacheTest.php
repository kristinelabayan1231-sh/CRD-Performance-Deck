<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneExpiredCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_only_expired_rows_in_batches(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00'));
        $store = Cache::store('database');
        foreach (range(1, 5) as $version) {
            $store->put("customers.{$version}.list", 'old page', now()->addMinutes(3));
        }
        $store->put('customers.cache_version', 6, now()->addYear());
        $store->forever('presentation-board.snapshot', 'board');
        $store->put('customers.6.list', 'current page', now()->addMinutes(10));

        $this->travel(5)->minutes();
        $this->artisan('cache:prune-expired', ['--chunk' => 2])->assertSuccessful();

        $this->assertSame(['customers.6.list', 'customers.cache_version', 'presentation-board.snapshot'],
            DB::table('cache')->orderBy('key')->pluck('key')->map(fn (string $key) => substr($key, strlen($store->getPrefix())))->all());
        $this->assertSame('current page', $store->get('customers.6.list'));
    }
}
