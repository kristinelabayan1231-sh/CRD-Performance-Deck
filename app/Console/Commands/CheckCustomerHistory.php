<?php

namespace App\Console\Commands;

use App\Services\CustomerDatabase;
use App\Services\PancakeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckCustomerHistory extends Command
{
    protected $signature = 'customers:check-history
        {--limit=1000 : Customers to look up this run}';

    protected $description = 'Look up CRD customers\' earlier orders in Pancake POS (before the Customer Database\'s first day), so Retained vs Repeat counts them';

    public function handle(CustomerDatabase $customers, PancakeClient $client): int
    {
        $phones = $customers->uncheckedHistories(max(1, (int) $this->option('limit')));

        if ($phones === []) {
            $this->info('No CRD customers to check right now (all checked, or failed lookups waiting to retry).');

            return self::SUCCESS;
        }

        $checked = $customers->checkHistories($phones, $client);
        $progress = $customers->historyProgress();
        Cache::forget('customers.history_progress');
        if ($checked > 0) {
            // Customers found to have earlier CRA orders move from Retained to Repeat.
            CustomerDatabase::flushCache();
        }
        $summary = "Checked {$checked} of ".count($phones)." customers; {$progress['checked']} of {$progress['total']} CRD customers done.";
        $this->info($summary);

        // Runs in the background, so its output is lost: log it (and any failed lookups) for the host's logs.
        $failed = count($phones) - $checked;
        $failed > 0
            ? Log::warning("customers:check-history {$summary} {$failed} Pancake lookups failed; retried in a few hours.")
            : Log::info("customers:check-history {$summary}");

        return self::SUCCESS;
    }
}
