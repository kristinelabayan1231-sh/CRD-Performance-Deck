<?php

namespace App\Console\Commands;

use App\Models\CustomerDashboardSnapshot;
use App\Models\FacebookPage;
use App\Models\PosCredential;
use App\Services\PancakeService;
use App\Support\DashboardPeriod;
use Illuminate\Console\Command;

class SyncCustomerDashboard extends Command
{
    protected $signature = 'pancake:sync-customer-dashboard';

    protected $description = 'Precompute the Customers-tab dashboard (top spenders, customers per page, top products) for the current week/month/year';

    /**
     * Order volume scales with period width, so wider periods get a higher
     * cap — but even capped, a year's worth of orders takes minutes to
     * scan. That's fine here (a scheduled CLI run, no request timeout) but
     * is exactly why the Customers page never computes this live itself.
     */
    protected array $maxOrdersByPeriod = [
        'week' => 6000,
        'month' => 15000,
        'year' => 40000,
    ];

    public function handle(PancakeService $pancake): int
    {
        // CLI-only, run by the scheduler with no request timeout to worry
        // about — the year period's tens of thousands of deduped customers
        // need more headroom than the 128M web-facing default to encode
        // into the snapshot payload. Doesn't affect any web request.
        ini_set('memory_limit', '512M');

        $posCredential = PosCredential::current();

        if (! $posCredential) {
            $this->warn('No POS credentials set — nothing to sync. Set them under Admin → Facebook Pages.');

            return self::SUCCESS;
        }

        $pageNames = FacebookPage::pluck('page_name', 'page_id')->all();
        $now = now();

        foreach (['week', 'month', 'year'] as $period) {
            [$start, $end] = DashboardPeriod::bounds($period, $now);
            $this->info("Computing {$period} ({$start->toDateString()} to {$end->toDateString()})…");

            $summary = $pancake->summarizeCustomerActivityInRange(
                $posCredential->shop_id,
                $posCredential->api_key,
                $start->toDateString(),
                $end->toDateString(),
                $pageNames,
                $this->maxOrdersByPeriod[$period],
            );

            CustomerDashboardSnapshot::updateOrCreate(
                ['period' => $period, 'period_key' => DashboardPeriod::key($period, $now)],
                ['computed_at' => now(), 'payload' => $summary],
            );

            $note = $summary['truncated'] ? ' (truncated — only the first '.$summary['orderCount'].' of '.$summary['totalEntries'].' orders scanned)' : '';
            $this->line("  {$summary['customerCount']} customers, {$summary['orderCount']} orders{$note}");
        }

        return self::SUCCESS;
    }
}
