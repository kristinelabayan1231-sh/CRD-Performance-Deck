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

// Dashboard, Conversion Breakdown and Segmentation Productivity: today's Pancake
// engagements, orders and tags every ten minutes, and yesterday's once more
// after midnight so its totals are final.
Schedule::command('pancake:sync')
    ->everyTenMinutes()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping();

// A full sync can take over ten minutes (Pancake sends whole orders), so the
// header's order issues get their own quick refresh: fixed tags clear in minutes.
Schedule::command('pancake:sync --tags')
    ->everyFiveMinutes()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping();

Schedule::command('pancake:sync --date=yesterday')
    ->dailyAt('00:30')
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping();

// Customer Database: one-time backfill from Pancake POS, a few days per run, oldest
// first in 2-month windows from January; once it reaches yesterday it does nothing.
Schedule::command('customers:backfill')
    ->everyFifteenMinutes()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping()
    ->runInBackground();
