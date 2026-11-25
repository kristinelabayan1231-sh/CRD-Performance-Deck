<?php

namespace App\Console\Commands;

use App\Services\RestockReminders;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BuildRestockReminders extends Command
{
    protected $signature = 'reminders:build
        {--date= : Reminder day (Y-m-d, real date); today when left out}
        {--cra= : Only these CRAs\' lists (user ids, comma separated); every CRA\'s when left out}';

    protected $description = 'List the customers on each CRA\'s tracker list for the day (its lead day) for their CRA\'s text';

    public function handle(RestockReminders $reminders): int
    {
        $day = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : WorkingDate::realToday();
        $craIds = filled($this->option('cra')) ? array_map('intval', explode(',', $this->option('cra'))) : null;
        $result = $reminders->build($day, $craIds);
        $summary = "Reminders for {$day->toDateString()}: {$result['due']} due, {$result['skipped']} skipped (reordered, opted out or already texted).";

        $this->info($summary);
        Log::info("reminders:build {$summary}");

        return self::SUCCESS;
    }
}
