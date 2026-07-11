<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refresh CRA/PC stats for today and yesterday (late-arriving data).
// Requires the scheduler to be running (`php artisan schedule:work` or cron).
Schedule::command('pancake:sync-cra-stats --days=2')->hourly();

// Precompute the Customers dashboard (week/month/year). This scans every
// order in each period — a month alone takes ~1 minute — so it only ever
// runs here, never inside a web request.
Schedule::command('pancake:sync-customer-dashboard')->hourly();
