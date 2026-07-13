<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refresh CRA/PC stats for the trailing week — wide enough that cohorts
// set late in the week backfill their earlier days automatically. Days a
// CRA has finalized are skipped, so the window stays cheap once the week
// is signed off. Requires the scheduler to be running
// (`php artisan schedule:work` or cron).
Schedule::command('pancake:sync-cra-stats --days=7')->hourly();

// Precompute the Customers dashboard (week/month/year). This scans every
// order in each period — a month alone takes ~1 minute — so it only ever
// runs here, never inside a web request.
Schedule::command('pancake:sync-customer-dashboard')->hourly();
