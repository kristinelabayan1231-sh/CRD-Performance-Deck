<?php

namespace App\Console\Commands;

use App\Services\LeadConflicts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshLeadConflicts extends Command
{
    protected $signature = 'leads:refresh-conflicts';

    protected $description = "Work out the CRA Dashboard's customers whose CRA assignments disagree ahead of time, so the page opens from the cache";

    public function handle(LeadConflicts $conflicts): int
    {
        $started = microtime(true);
        $rows = $conflicts->refresh();

        $summary = count($rows).' customers with conflicting CRAs, ready in '.round(microtime(true) - $started, 1).'s.';
        $this->info($summary);
        // Runs in the background, so its output is lost: log it for the host's logs.
        Log::info("leads:refresh-conflicts {$summary}");

        return self::SUCCESS;
    }
}
