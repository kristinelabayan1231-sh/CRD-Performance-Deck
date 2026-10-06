<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync and assign today's Segmentation Tracker leads every hour (catches
// late-recorded orders and newly added CRAs). The tracker page also syncs
// on open when the last sync is over an hour old.
Schedule::command('leads:generate')
    ->hourly()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping();

// Segmentation Productivity: today's Pancake engagements and orders every
// hour, and yesterday's once more after midnight so its totals are final.
Schedule::command('pancake:sync')
    ->hourly()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping();

Schedule::command('pancake:sync --date=yesterday')
    ->dailyAt('00:30')
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping();
