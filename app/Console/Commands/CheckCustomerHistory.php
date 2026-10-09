<?php

namespace App\Console\Commands;

use App\Services\CustomerDatabase;
use App\Services\PancakeClient;
use Illuminate\Console\Command;

class CheckCustomerHistory extends Command
{
    protected $signature = 'customers:check-history
        {--limit=1000 : Customers to look up this run}';

    protected $description = 'Look up CRD customers\' earlier orders in Pancake POS (before the Customer Database\'s first day), so Retained vs Repeat counts them';

    public function handle(CustomerDatabase $customers, PancakeClient $client): int
    {
        $phones = $customers->uncheckedHistories(max(1, (int) $this->option('limit')));

        if ($phones === []) {
            $this->info('Every CRD customer\'s earlier history is checked.');

            return self::SUCCESS;
        }

        $checked = $customers->checkHistories($phones, $client);
        $progress = $customers->historyProgress();
        $this->info("Checked {$checked} of ".count($phones)." customers; {$progress['checked']} of {$progress['total']} CRD customers done.");

        return self::SUCCESS;
    }
}
