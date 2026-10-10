<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Delivered from
    |--------------------------------------------------------------------------
    |
    | The Customer Database covers deliveries from this day on: older
    | deliveries are neither saved nor counted.
    |
    | The logistics retention report starts on logistics_from; the days before
    | it come from Pancake POS deliveries, CRD = sold by a CRD account.
    |
    */

    'delivered_from' => '2026-01-01',

    'logistics_from' => '2026-04-05',

    // Pancake seller accounts of the CRD team, current and past, besides the CRA users' own
    // Pancake accounts (User Access). Orders they sold count as handled by a CRA.
    // Matched ignoring case and extra spaces.
    'crd_accounts' => [
        'CRD Joanne Maclang',
        'CRD Rej Vergara',
        'CRD JULY ANN',
        'CRD ANNA PACLIBARE 2',
        'CRD Rose-An Orbaneja',
        'CRD Lhei',
        'Lhea Gatdula',
        'CRD ANNA PACLIBARE',
        'CRD Joanna Paclibare',
        'CRD JULY DE LOS SANTOS',
        'CRD Sha Galano',
    ],

    /*
    |--------------------------------------------------------------------------
    | Product CLTV
    |--------------------------------------------------------------------------
    |
    | A product's customer lifetime value is its SRP (Settings → Product
    | Consumption) × this many units. A customer whose units × SRP reach it
    | is marked "Reached CLTV".
    |
    */

    'cltv_units' => 30,

    /*
    |--------------------------------------------------------------------------
    | Churn
    |--------------------------------------------------------------------------
    |
    | Days a CRD customer has after their supply runs out to order again
    | before the dashboard's churn rate counts them as lost (like the Cold
    | tag: no purchase 31+ days).
    |
    */

    'churn_grace_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Pancake POS order statuses
    |--------------------------------------------------------------------------
    |
    | status code => [label, badge classes], in the order the customer pop-up
    | lists them. Deleted orders (7) are left out.
    |
    */

    'pos_statuses' => [
        0 => ['New', 'bg-[#c6dbe1] text-[#0a53a8]'],
        17 => ['Waiting for confirmation', 'bg-[#c6dbe1] text-[#0a53a8]'],
        11 => ['Waiting for goods', 'bg-[#ffe5a0] text-[#473821]'],
        20 => ['Purchased', 'bg-[#ffe5a0] text-[#473821]'],
        12 => ['Waiting for print', 'bg-[#ffe5a0] text-[#473821]'],
        13 => ['Printed', 'bg-[#ffe5a0] text-[#473821]'],
        1 => ['Confirmed', 'bg-[#ffe5a0] text-[#473821]'],
        8 => ['Packing', 'bg-[#ffe5a0] text-[#473821]'],
        9 => ['Waiting for pickup', 'bg-[#ffe5a0] text-[#473821]'],
        2 => ['Shipped', 'bg-[#e6cff2] text-[#5a3286]'],
        3 => ['Delivered', 'bg-[#d4edbc] text-[#11734b]'],
        16 => ['Payment collected', 'bg-[#d4edbc] text-[#11734b]'],
        4 => ['Returning', 'bg-[#ffcfc9] text-[#b10202]'],
        15 => ['Partially returned', 'bg-[#ffcfc9] text-[#b10202]'],
        5 => ['Returned', 'bg-[#ffcfc9] text-[#b10202]'],
        6 => ['Canceled', 'bg-[#e6e6e6] text-[#3d3d3d]'],
    ],

    // Statuses whose products count as bought for product CLTV.
    'delivered_statuses' => [3, 16],

    /*
    |--------------------------------------------------------------------------
    | One-time backfill
    |--------------------------------------------------------------------------
    |
    | `customers:backfill` fetches Pancake POS orders (and, before
    | logistics_from, POS deliveries) day by day in windows of this many
    | months, oldest first, up to yesterday; then it is done for good and the
    | regular syncs keep the database current. Orders start a week before
    | delivered_from: they are placed a few days before they are delivered.
    |
    */

    'backfill_from' => '2025-12-25',

    'backfill_window_months' => 2,

];
