<?php

namespace App\Console\Commands;

use App\Services\HighValueCustomers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AssignHighAovCras extends Command
{
    protected $signature = 'customers:assign-high-aov';

    protected $description = 'Give each new High AOV / VIP customer a CRA for good (handled two orders, else the order that made them High AOV / VIP) and tag their current leads High Value Customers';

    public function handle(HighValueCustomers $highValue): int
    {
        $assigned = $highValue->assignNewCustomers();
        $tagged = $highValue->tagLeads();
        $summary = "{$assigned} new High AOV / VIP customers given a CRA; {$tagged} leads tagged High Value Customers.";
        $this->info($summary);

        // Runs in the background, so its output is lost: log it for the host's logs.
        Log::info("customers:assign-high-aov {$summary}");

        return self::SUCCESS;
    }
}
