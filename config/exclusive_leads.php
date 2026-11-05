<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exclusive Lead Tracker
    |--------------------------------------------------------------------------
    |
    | CRAs transferred out of the Segmentation Tracker (Settings, users.exclusive_from).
    | Each day's list is daily_quota leads, except the days in quota_overrides. Days before
    | build_from keep the leads the CRA already had and are never topped up. All dates here
    | are lead days (real Nov 1, 2026 = lead day Oct 2). The page shows the real dates.
    |
    */

    'daily_quota' => 250,

    // Lead days Oct 2–5, 2026 (real Nov 1–4) ran at the old 100 a day.
    'quota_overrides' => [
        '2026-10-02' => 100,
        '2026-10-03' => 100,
        '2026-10-04' => 100,
        '2026-10-05' => 100,
    ],

    // Lead day Oct 7, 2026 = real Nov 6.
    'build_from' => '2026-10-07',

    // The goal is constant; the days of a month may plan fewer (November 2026 plans 6,900).
    'month_target' => 7000,

    'repeat_goal_percent' => 20,

    // A daily target: the month's is this × her working days (days off have none).
    'confirmed_orders_per_day' => 54,

    // This module's High AOV line. customers.high_aov_min stays the rule for every other CRA.
    'high_aov_min' => 895,

];
