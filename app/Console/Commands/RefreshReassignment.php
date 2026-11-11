<?php

namespace App\Console\Commands;

use App\Services\CustomerReassignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshReassignment extends Command
{
    protected $signature = 'customers:refresh-reassignment';

    protected $description = "Work out the CRA Dashboard's customers up for reassignment ahead of time, so the page opens from the cache";

    public function handle(CustomerReassignment $reassignment): int
    {
        $started = microtime(true);
        $pool = $reassignment->refresh();

        $summary = count($pool).' customers open or opening soon, ready in '.round(microtime(true) - $started, 1).'s.';
        $this->info($summary);
        // Runs in the background, so its output is lost: log it for the host's logs.
        Log::info("customers:refresh-reassignment {$summary}");

        return self::SUCCESS;
    }
}
