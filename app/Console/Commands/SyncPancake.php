<?php

namespace App\Console\Commands;

use App\Services\PancakeSync;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SyncPancake extends Command
{
    protected $signature = 'pancake:sync
        {--date= : Day to sync (YYYY-MM-DD, or "yesterday"). Defaults to today in the segmentation timezone.}
        {--from= : First day of a range to backfill (YYYY-MM-DD)}
        {--to= : Last day of the range (YYYY-MM-DD). Defaults to today.}
        {--delivered : Only save delivered orders (for the lead fallback); quicker for backfills}
        {--sales : Only refresh the orders\' Shecom sales (gross without the child TSD row); one request for the whole range}
        {--tags : Only re-check the flagged orders and pick up the day\'s tag and status changes; takes seconds}';

    protected $description = 'Copy Pancake chat engagements and POS orders for Segmentation Productivity';

    public function handle(PancakeSync $sync): int
    {
        // Pancake activity happens on real days, whatever the tracker's working date.
        $today = WorkingDate::realToday();

        if ($this->option('from')) {
            $from = CarbonImmutable::parse($this->option('from'));
            $to = $this->option('to') ? CarbonImmutable::parse($this->option('to')) : $today;
        } else {
            $from = $to = match ($this->option('date')) {
                null => $today,
                'yesterday' => $today->subDay(),
                default => CarbonImmutable::parse($this->option('date')),
            };
        }

        if ($this->option('sales')) {
            $this->info("Shecom sales {$from->toDateString()} to {$to->toDateString()}: {$sync->syncSales($from, $to)} orders updated.");

            return self::SUCCESS;
        }

        if ($this->option('tags')) {
            $this->info("Pancake flagged orders: {$sync->recheckIssues()} updated.");

            for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                $this->info("Pancake {$day->toDateString()}: {$sync->refreshTags($day)} orders with changed tags or status.");
            }

            return self::SUCCESS;
        }

        $failed = false;

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            try {
                if ($this->option('delivered')) {
                    $this->info("Pancake {$day->toDateString()}: {$sync->syncDelivered($day)} delivered orders saved.");

                    continue;
                }

                $result = $sync->sync($day);
                $this->info("Pancake {$day->toDateString()}: {$result['staff']} staff with engagements, {$result['orders']} orders, {$result['delivered']} delivered, {$result['sales']} Shecom sales.");

                if ($result['engagement_error']) {
                    $this->warn("Pancake {$day->toDateString()}: ".($result['staff'] ? 'engagements saved without some pages.' : 'engagements kept from the last good sync.')." {$result['engagement_error']}");
                }
            } catch (Throwable $e) {
                $this->error("Pancake {$day->toDateString()}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
