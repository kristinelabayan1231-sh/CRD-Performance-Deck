<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\PancakeSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SyncPancake extends Command
{
    protected $signature = 'pancake:sync
        {--date= : Day to sync (YYYY-MM-DD, or "yesterday"). Defaults to today in the segmentation timezone.}
        {--from= : First day of a range to backfill (YYYY-MM-DD)}
        {--to= : Last day of the range (YYYY-MM-DD). Defaults to today.}
        {--delivered : Only save delivered orders (for the lead fallback); quicker for backfills}';

    protected $description = 'Copy Pancake chat engagements and POS orders for Segmentation Productivity';

    public function handle(PancakeSync $sync): int
    {
        $today = Lead::today();

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

        $failed = false;

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            try {
                if ($this->option('delivered')) {
                    $this->info("Pancake {$day->toDateString()}: {$sync->syncDelivered($day)} delivered orders saved.");

                    continue;
                }

                $result = $sync->sync($day);
                $this->info("Pancake {$day->toDateString()}: {$result['staff']} staff with engagements, {$result['orders']} orders, {$result['delivered']} delivered.");
            } catch (Throwable $e) {
                $this->error("Pancake {$day->toDateString()}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
