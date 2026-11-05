<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exclusive Lead Tracker
    |--------------------------------------------------------------------------
    |
    | CRAs transferred out of the Segmentation Tracker (Settings, users.exclusive_from).
    | Each day's list is daily_quota leads, except the days in quota_overrides. Days before
    | build_from keep the leads the CRA already had and are never topped up.
    |
    */

    'daily_quota' => 250,

    // Nov 1–4, 2026 ran at the old 100 a day.
    'quota_overrides' => [
        '2026-11-01' => 100,
        '2026-11-02' => 100,
        '2026-11-03' => 100,
        '2026-11-04' => 100,
    ],

    'build_from' => '2026-11-06',

    // The goal is constant; the days of a month may plan fewer (November 2026 plans 6,900).
    'month_target' => 7000,

    'repeat_goal_percent' => 20,

    'confirmed_orders_target' => 54,

    // This module's High AOV line. customers.high_aov_min stays the rule for every other CRA.
    'high_aov_min' => 895,

];
