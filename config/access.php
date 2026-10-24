<?php

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
|
| Every permission a role can be given, grouped by module. Add a group
| here when a new module is built; it then appears in role creation.
| Creating and editing roles is reserved for Super Admins.
|
*/

$modules = [
    'User Access' => [
        'user_access.view' => 'View users and their roles',
        'user_access.manage' => 'Grant, change, disable and remove user access',
    ],
    'Settings · Product Consumption' => [
        'product_consumption.view' => 'View products and their keywords',
        'product_consumption.manage' => 'Add, edit and delete products',
        'product_consumption.srp' => 'Set each product\'s SRP (sets its CLTV in the Customer Database)',
    ],
    'Settings · Pancake Pages' => [
        'pancake_pages.manage' => 'View, add and edit the Pancake pages and their access tokens',
    ],
    'Settings · Pancake Accounts' => [
        'pancake_accounts.manage' => 'Set the CRD team\'s Pancake accounts counted by the Customer Database',
    ],
    'Settings · Sales Goals' => [
        'sales_goals.manage' => 'Set the CRA daily sales goal and the CRD monthly sales goal',
    ],
    'Settings · Segmentation Tracker' => [
        'segmentation_options.manage' => 'Edit the Segmentation Tracker dropdown choices (status, repeat purchase, customer tagging, feedback)',
    ],
    'Settings · Daily I.S.' => [
        'daily_is_products.manage' => 'Deactivate Daily I.S. products (a deactivated product shows only on a day it has sales, labelled Deactivated)',
    ],
    'Settings · Connections' => [
        'connections.check' => 'Run the connection check (database, Shecom, Pancake) from the server',
    ],
    'Segmentation Tracker' => [
        'segmentation.view' => 'View own assigned leads and update their status',
        'segmentation.view_all' => 'View all leads and filter by CRA',
        'segmentation.manage' => 'Reassign leads, change any status and sync leads from the API',
        'segmentation.transfer' => 'Transfer backlog (unprocessed) leads between CRAs',
    ],
    'Segmentation Productivity' => [
        'productivity.view' => 'View own productivity numbers',
        'productivity.view_all' => 'View and compare every CRA, and sync Pancake data',
    ],
    'Conversion Breakdown' => [
        'conversion.view' => 'View own broadcast and segmentation conversions',
        'conversion.view_all' => 'View and compare every CRA, and sync Pancake data',
    ],
    'Report Summary' => [
        'report_summary.view' => 'View the Report Summary: every CRA\'s weekly, month-to-date and daily orders, sales and per-CRA averages',
    ],
    'Running Sales' => [
        'running_sales.view' => 'View every CRA\'s running sales (overall and per product) against their daily goal, and refresh them',
    ],
    'Call Recordings' => [
        'call_recordings.view' => 'View and play own call recordings and AHT (average call length)',
        'call_recordings.view_all' => 'View and play every CRA\'s call recordings and compare their AHT',
    ],
    'Settings · Call Recordings' => [
        'call_recordings.manage' => 'Connect the Google Drive that keeps the recordings and create each CRA phone\'s upload token',
    ],
    'On Duty' => [
        'on_duty.view' => 'See who is on duty and off today (the list behind the sidebar\'s "CRA on duty") and every CRA\'s schedule (CRA Schedule)',
        'on_duty.manage' => 'Change any CRA\'s day in the CRA Schedule (Absent / Leave, Rest Day or on duty), past days included',
    ],
    'PC Report' => [
        'pc_report.view' => 'View own PC Report sets (each product\'s pages, per day)',
        'pc_report.view_all' => 'View every CRA\'s PC Report sets',
        'pc_report.manage' => 'Set each CRA\'s Creation/Update Time per page',
    ],
    'CVR Tracker' => [
        'cvr_tracker.view' => 'View and fill in own CVR Tracker (each day\'s reorders, upsell, type of order, point of contact and unconfirmed)',
        'cvr_tracker.view_all' => 'View and fill in every CRA\'s CVR Tracker',
    ],
    'Daily I.S.' => [
        'daily_is.view' => 'View own Daily I.S. (income statement per product, per day)',
        'daily_is.view_all' => 'View the department\'s and every CRA\'s Daily I.S.',
    ],
    'Monthly MNI' => [
        'mni.view' => 'View the Monthly MNI (each CRA\'s monthly targets and target income statement)',
        'mni.manage' => 'Type in the Monthly MNI targets (orders, conversion rate, AOV, percentages, Tax Allocation, Advertising Cost)',
    ],
    'Product Performance' => [
        'product_performance.view' => 'View Product Performance: every CRD account\'s sales, delivered, RTS and cancelled orders per product, with trends and insights',
    ],
    'Order Database' => [
        'order_database.view' => 'View own CRA\'s Performance and Product Report (Pancake POS orders per status, product, cost and delivery rates)',
        'order_database.view_all' => 'View every CRD account\'s orders and filter by CRA',
    ],
    'CRD - MC' => [
        'monthly_cost.manage' => 'View and edit CRD - MC: every position\'s salary, the shared salaries, benefits and the monthly cost',
    ],
    'Customer Database' => [
        'customers.view' => 'View every FSD- and CRD-delivered customer, their orders and product CLTV',
        'customers.merge' => 'Merge contact numbers that belong to the same customer, and undo a merge',
        'customers.high_value.view' => 'View the High AOV CVR & VIP lists (without the rest of the Customer Database)',
        'customers.high_value.edit' => 'Claim High AOV / VIP customers as theirs and set their point of contact, PU/Reseller and remarks',
        'customers.high_value.assign' => 'Set or change any High AOV / VIP customer\'s CRA',
    ],
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Super Admin
    |--------------------------------------------------------------------------
    |
    | This Google account is always a super admin. It is created on first
    | sign-in and cannot be demoted, disabled or removed from User Access.
    |
    */

    'super_admin_email' => strtolower((string) env('SUPER_ADMIN_EMAIL', 'kristinelabayan1231@gmail.com')),

    'modules' => $modules,

    'permissions' => array_merge(...array_values($modules)),

];
