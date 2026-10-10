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
// Each "already running" lock expires a little after the job's usual run time (minutes),
// so a run cut off by a deploy or restart doesn't block the job for the default 24 hours
// (the locks live in the database cache and survive restarts).
Schedule::command('leads:generate')
    ->hourly()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(30);

// Dashboard, Conversion Breakdown and Segmentation Productivity: today's Pancake
// engagements, orders and tags every ten minutes, and yesterday's once more
// after midnight so its totals are final.
Schedule::command('pancake:sync')
    ->everyTenMinutes()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(30);

// A full sync can take over ten minutes (Pancake sends whole orders), so the
// header's order issues get their own quick refresh: fixed tags clear in minutes.
Schedule::command('pancake:sync --tags')
    ->everyFiveMinutes()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(10);

Schedule::command('pancake:sync --date=yesterday')
    ->dailyAt('00:30')
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(60);

// Customer Database: one-time backfill from Pancake POS, a few days per run, oldest
// first in 2-month windows from January; once it reaches yesterday it does nothing.
Schedule::command('customers:backfill')
    ->everyFifteenMinutes()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(45)
    ->runInBackground();

// Customer Database: each CRD customer's earlier orders (before the first covered day),
// looked up once in Pancake, so Retained vs Repeat counts them. New CRD customers are
// picked up within 15 minutes.
Schedule::command('customers:check-history')
    ->everyFifteenMinutes()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(20)
    ->runInBackground();
