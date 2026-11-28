<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Filled from the app
    |--------------------------------------------------------------------------
    |
    | From this month the CRA cards' Number of Orders, Gross / Cancelled / Fulfilled Sales, Returns/Returning, Delivered
    | Sales and Product Cost come from the CRAs' orders (as the Daily I.S. counts them). A typed amount still wins.
    | Earlier months were typed in or taken from the CRD Sales Report sheet.
    |
    */

    'filled_from' => '2026-09-01',

    /*
    |--------------------------------------------------------------------------
    | Staff cards
    |--------------------------------------------------------------------------
    |
    | CRD - MC staff who get their own card beside the CRAs' from the month they were onboarded, by their CRD - MC
    | position. Their salary leaves the CRAs' shared salaries (Monthly MNI) on this page, so it isn't counted twice.
    | Not on the Daily I.S. or CRA Gross Sales & IBSC.
    |
    */

    'staff' => [
        ['position' => 'Junior AI Engineer - for Automation', 'from' => '2026-10-01'],
    ],

];
