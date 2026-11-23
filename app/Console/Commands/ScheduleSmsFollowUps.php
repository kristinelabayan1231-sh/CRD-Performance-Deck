<?php

namespace App\Console\Commands;

use App\Services\RestockReminders;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScheduleSmsFollowUps extends Command
{
    protected $signature = 'reminders:follow-up
        {--date= : The day the texts were sent (Y-m-d, real date); today when left out}';

    protected $description = 'Add a follow-up text 7 days later for customers texted today who haven\'t reordered, or move them to No response after the last one';

    public function handle(RestockReminders $reminders): int
    {
        $day = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : WorkingDate::realToday();
        $result = $reminders->scheduleFollowUps($day);

        $summary = "Follow-ups for texts sent {$day->toDateString()}: {$result['follow_ups']} added, {$result['no_response']} moved to No response.";
        $this->info($summary);
        Log::info("reminders:follow-up {$summary}");

        return self::SUCCESS;
    }
}
