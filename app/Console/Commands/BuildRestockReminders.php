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
        {--date= : Reminder day (Y-m-d, real date); today when left out}';

    protected $description = 'List the customers whose product runs out in 7 days (by the real date) for their CRA\'s restock text';

    public function handle(RestockReminders $reminders): int
    {
        $day = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : WorkingDate::realToday();
        $result = $reminders->build($day);
        $summary = "Reminders for {$day->toDateString()}: {$result['due']} due, {$result['skipped']} skipped (reordered or opted out).";

        $this->info($summary);
        Log::info("reminders:build {$summary}");

        return self::SUCCESS;
    }
}
