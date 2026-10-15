<?php

namespace App\Console\Commands;

use App\Services\CustomerChurn;
use App\Services\CustomerDatabase;
use App\Services\HighValueCustomers;
use App\Support\DashboardRange;
use App\Support\WorkingDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WarmCustomerCache extends Command
{
    protected $signature = 'customers:warm-cache';

    protected $description = 'Work out the Customer Database\'s default tab (tiles, first page, Possible match, earlier-orders progress) the High AOV & VIP list and the Dashboard\'s customer tiles and churn for this month ahead of time, so the pages open from the cache';

    public function handle(CustomerDatabase $customers, HighValueCustomers $highValue, CustomerChurn $churn): int
    {
        $started = microtime(true);
        CustomerDatabase::$refreshing = true;

        try {
            // The same filters the Customers tab opens with: every delivery, no search, highest total spent first.
            $counts = $customers->cachedCounts([]);
            foreach ([null, 'possible_match'] as $segment) {
                $customers->cachedList(['from' => null, 'to' => null, 'search' => null, 'segment' => $segment, 'sort' => 'spent'], $counts);
            }
            $customers->cachedHistoryProgress();
            $highValue->all();

            // The Dashboard as it opens (this month to today): Retained / Repeat counts and churn.
            $month = DashboardRange::fromFilters([], WorkingDate::realToday());
            $customers->cachedCounts(['from' => $month->from, 'to' => $month->to, 'search' => null]);
            $churn->cached($month->from, $month->to);
        } finally {
            CustomerDatabase::$refreshing = false;
        }

        $summary = 'Customer Database cache ready in '.round(microtime(true) - $started, 1).'s.';
        $this->info($summary);
        // Runs in the background, so its output is lost: log it for the host's logs.
        Log::info("customers:warm-cache {$summary}");

        return self::SUCCESS;
    }
}
