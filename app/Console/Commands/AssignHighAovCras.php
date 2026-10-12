<?php

namespace App\Console\Commands;

use App\Services\HighValueCustomers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AssignHighAovCras extends Command
{
    protected $signature = 'customers:assign-high-aov';

    protected $description = 'Give each High AOV customer the CRA of the first CRA-handled order at which their running AOV (CRA orders only) reached the High AOV minimum';

    public function handle(HighValueCustomers $highValue): int
    {
        $changed = $highValue->assignByFirstReorder();
        $summary = "{$changed} High AOV customers moved to the CRA of their first reorder.";
        $this->info($summary);

        // Runs in the background, so its output is lost: log it for the host's logs.
        Log::info("customers:assign-high-aov {$summary}");

        return self::SUCCESS;
    }
}
