<?php

namespace App\Console\Commands;

use App\Services\LeadGenerator;
use App\Services\LogisticsRetention;
use App\Services\PancakeSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The syncs a page asks for (stale data, a Sync button), started by BackgroundArtisan in their own process.
 */
class RunPageSync extends Command
{
    protected $signature = 'syncs:page
        {task : leads, pancake, ages or logistics}
        {--date= : The day (YYYY-MM-DD)}
        {--force : Pancake: sync even when the day was synced recently}';

    protected $description = 'Run a sync a page asked for, outside the web server';

    protected $hidden = true;

    public function handle(LeadGenerator $generator, PancakeSync $pancake, LogisticsRetention $logistics): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : null;

        match ($this->argument('task')) {
            'leads' => $generator->syncInThisProcess($date),
            'pancake' => $pancake->syncInThisProcess($date, (bool) $this->option('force')),
            'ages' => $pancake->syncAgesInThisProcess($date),
            'logistics' => $logistics->refreshInThisProcess(),
        };

        return self::SUCCESS;
    }
}
