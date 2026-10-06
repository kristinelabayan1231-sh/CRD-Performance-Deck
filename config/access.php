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
