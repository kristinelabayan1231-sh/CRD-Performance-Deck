<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Laravel's database cache only deletes an expired row when the same key is read again. The Customer Database's keys
 * carry a version that goes up after every sync, so its old rows are never read again and would fill the disk.
 */
class PruneExpiredCache extends Command
{
    protected $signature = 'cache:prune-expired {--chunk=500 : Rows deleted per statement}';

    protected $description = 'Delete the database cache rows whose time has run out (they already count as missing)';

    public function handle(): int
    {
        $connection = DB::connection(config('cache.stores.database.connection'));
        $table = config('cache.stores.database.table', 'cache');
        $now = now()->getTimestamp();
        $chunk = max(1, (int) $this->option('chunk'));
        $deleted = 0;

        // Small batches keep each statement short on the small database.
        do {
            $keys = $connection->table($table)->where('expiration', '<=', $now)->limit($chunk)->pluck('key');
            $deleted += $keys->isEmpty() ? 0 : $connection->table($table)->whereIn('key', $keys->all())->delete();
        } while ($keys->count() === $chunk);

        $summary = "{$deleted} expired cache rows deleted.";
        $this->info($summary);
        Log::info("cache:prune-expired {$summary}");

        return self::SUCCESS;
    }
}
