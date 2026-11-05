<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\ExclusiveLeads;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BuildExclusiveLeads extends Command
{
    protected $signature = 'leads:build-exclusive {--date= : List day (YYYY-MM-DD). Defaults to the current lead day.}';

    protected $description = "Build each exclusive CRA's daily list up to its quota; safe to run again";

    public function handle(ExclusiveLeads $exclusive): int
    {
        $day = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : Lead::today();

        foreach ($exclusive->sync($day) as $name => $added) {
            $this->info("{$name}: {$added} leads added for {$day->toDateString()}.");
        }

        return self::SUCCESS;
    }
}
