<?php

use App\Services\RunningSales;
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

// Exclusive Lead Tracker: each exclusive CRA's day (leads:generate builds it first too; this catches
// leads added since and a generate run that failed). Safe to run again: it only tops up to the quota.
Schedule::command('leads:build-exclusive')
    ->hourlyAt(15)
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

// CVR Tracker: the orders made since the last run and today's tag changes every minute (one or two small
// Pancake requests), so CRAs see a new order within a minute or two instead of waiting on the full sync.
Schedule::command('pancake:sync --new')
    ->everyMinute()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(5);

// Running Sales: every CRA's sales so far (today, this week, this month) every two hours, on the even hour.
Schedule::command('running-sales:refresh')
    ->everyTwoHours()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(30);

Schedule::command('pancake:sync --date=yesterday')
    ->dailyAt('00:30')
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(60);

// Shecom's sales of the last two weeks' orders again (one request): an amount corrected in Shecom after the
// order's day is otherwise never picked up (Pancake's own price edits come with the day's changed orders).
Schedule::command('pancake:sync --sales --last=14')
    ->dailyAt('01:15')
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(15);

// The CRD team sends its reports at 10 PM, so everything that isn't refreshed every few minutes is brought up to date
// by 9:30 PM: amounts corrected in Shecom, then removed orders, then (after the 9:20 sync and POS sales) Running Sales.
Schedule::command('pancake:sync --sales --last=14')
    ->dailyAt('21:00')
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(15);

// Orders removed from Pancake never send a status, so without this they keep counting as they last were saved.
Schedule::command('pancake:sync --gone --last=14')
    ->dailyAt('21:05')
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(10);

Schedule::command('running-sales:refresh')
    ->dailyAt(RunningSales::BEFORE_REPORTS)
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(5);

// The CRAs' POS sales of the last week in the Segmentation Tracker again: late orders, products added to Settings
// later and orders counted for another CRA in the CVR Tracker (a customer is never added twice).
Schedule::command('leads:record-pos-sales --last=7')
    ->hourlyAt(20)
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(30);

// Customer Database: each CRD customer's earlier orders (before the first covered day),
// looked up once in Pancake, so Retained vs Repeat counts them. New CRD customers are
// picked up within 15 minutes.
Schedule::command('customers:check-history')
    ->everyFifteenMinutes()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(20)
    ->runInBackground();

// High AOV CVR & VIP: each new High AOV / VIP customer gets a CRA for good (the CRA who handled two
// of their orders, else the one whose order made them High AOV / VIP), and their current leads are
// tagged High Value Customers. Runs before the hourly lead assignment so their leads go to that CRA.
Schedule::command('customers:assign-high-aov')
    ->hourlyAt(50)
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(20)
    ->runInBackground();

// Customer Database: the default Customers tab and the High AOV & VIP list worked out
// ahead of time and kept past the next run, so pages open from the cache instead of
// building them inside a page view (too slow on the live server).
Schedule::command('customers:warm-cache')
    ->everyTwoMinutes()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(10)
    ->runInBackground();

// Daily I.S. Product Cost: Shecom's cost of each Pancake item, every hour at :10 (a change counts from its effective day).
Schedule::command('shecom:product-costs')
    ->hourlyAt(10)
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(10);

// Presentation Board: the year's figures, kept as a snapshot because they read every order of the year.
// At :35, away from the top of the hour, when the lead sync, Pancake sync and Running Sales all start.
Schedule::command('presentation-board:refresh')
    ->cron('35 */2 * * *')
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(60)
    ->runInBackground();

// Presentation Board → CRA performance, half an hour after the board so the two don't compete for memory.
Schedule::command('presentation-board:refresh-cras')
    ->cron('5 1-23/2 * * *')
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(60)
    ->runInBackground();

// Presentation Board: older orders' Pancake pages, a little at a time (the web service and syncs share one instance); does nothing once finished.
Schedule::command('pancake:backfill-pages --minutes=8')
    ->everyTenMinutes()
    ->timezone(config('segmentation.timezone'))
    ->withoutOverlapping(20)
    ->runInBackground();

// MC / salaries API: save CRD - MC as this month's payroll every day, so each month has its figures even when nobody
// edits CRD - MC or calls the API, and a month keeps what it had once it ends. Edits in CRD - MC save it too.
Schedule::command('mc:snapshot')
    ->dailyAt('00:10')
    ->timezone(config('segmentation.timezone'));
