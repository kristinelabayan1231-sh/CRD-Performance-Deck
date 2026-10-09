<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lead day
    |--------------------------------------------------------------------------
    |
    | A customer becomes a lead on the day their product is estimated to run
    | out. "Today" is worked out in this timezone.
    |
    */

    'timezone' => env('SEGMENTATION_TIMEZONE', 'Asia/Manila'),

    // Daily lead quota per CRA. CRD Leads are handed out first; leads beyond
    // everyone's quota are spread evenly rather than left unassigned.
    'leads_per_cra' => (int) env('SEGMENTATION_LEADS_PER_CRA', 70),

    // Pancake POS order tag ids that make an order a CRD conversion (Conversion
    // Breakdown): "CRD - BROADCAST" and "CRD - SEGMENTATION". An order with
    // both tags counts as segmentation.
    'conversion_tags' => [
        'broadcast' => (int) env('PANCAKE_TAG_BROADCAST', 397),
        'segmentation' => (int) env('PANCAKE_TAG_SEGMENTATION', 398),
    ],

    // Default sales goals until they are set in Settings → Sales Goals (pesos).
    'sales_goals' => [
        'cra_daily' => 77000,
        'crd_monthly' => 1000000,
    ],

    // Lead type normally comes from Shecom (CRD- or FSD-delivered order). Only
    // when Shecom is down: a customer with this many delivered orders is a CRD Lead.
    'crd_lead_min_orders' => 2,

    // "Recommended Replenishment Day" = estimated out-of-stock date minus this many days.
    'replenishment_days_before' => 7,

    /*
    |--------------------------------------------------------------------------
    | Statuses
    |--------------------------------------------------------------------------
    |
    | key => [label, badge classes]. Keys are stored on the lead; labels and
    | colours can change freely.
    |
    */

    'statuses' => [
        'active' => ['Active', 'bg-[#d4edbc] text-[#11734b]'],
        'inactive' => ['Inactive', 'bg-[#e6e6e6] text-[#3d3d3d]'],
        'pjr_drop_call' => ['PJR/Inactive/CBR/Drop call', 'bg-[#e6cff2] text-[#5a3286]'],
        'busy_callback' => ['Busy/Callback', 'bg-[#753800] text-[#ffcfc9]'],
        'reminders_ffup' => ['Reminders/FFUP', 'bg-[#c6dbe1] text-[#0a53a8]'],
        'repeat_purchase' => ['Repeat Purchase', 'bg-[#ffcfc9] text-[#b10202]'],
        'blocked' => ['Blocked', 'bg-[#3d3d3d] text-white'],
    ],

    // Picking one of these statuses sets the lead's Customer's Feedback too: status => feedback key.
    'status_feedback' => [
        'pjr_drop_call' => 'no_verbal_conv',
    ],

    // Leads with no status, or one of these statuses, carry over to the next
    // day with the same CRA until their status changes to something else.
    'carry_over_statuses' => ['pjr_drop_call', 'repeat_purchase', 'inactive'],

    /*
    |--------------------------------------------------------------------------
    | Tracking dropdowns
    |--------------------------------------------------------------------------
    |
    | Same shape as statuses: key => [label, badge classes].
    |
    */

    'repeat_purchase' => [
        'yes' => ['Yes', 'bg-[#d4edbc] text-[#11734b]'],
        'no' => ['No', 'bg-[#e6e6e6] text-[#3d3d3d]'],
        'reserve' => ['Reserve', 'bg-[#ffe5a0] text-[#473821]'],
    ],

    'customer_tags' => [
        'hot' => ['Hot Leads / Recent Buyers (0 to 15 days)', 'bg-[#ef6a3a] text-[#3b0d00]'],
        'warm' => ['Warm Leads / Old Customers (16 to 30 days)', 'bg-[#ffe5a0] text-[#473821]'],
        'cold' => ['Cold Leads / No purchase leads (31 + days)', 'bg-[#0a53a8] text-white'],
        'high_value' => ['High Value Customers', 'bg-[#d4edbc] text-[#11734b]'],
        'repeat' => ['Repeat Customers', 'bg-[#ffcfc9] text-[#b10202]'],
        'canpro_hot' => ['CanPro Hot Leads / Recent Buyers (0 to 10 days)', 'bg-[#b10202] text-[#ffcfc9]'],
        'canpro_warm' => ['CanPro Warm Leads / Old Customers (11 to 20 days)', 'bg-[#753800] text-[#ffe5a0]'],
        'canpro_cold' => ['Cold Leads / No purchase leads (21 + days)', 'bg-[#bfe1f6] text-[#0a53a8]'],
    ],

    'contact_times' => [
        '06' => '6:00AM-7:00AM',
        '07' => '7:00AM-8:00AM',
        '08' => '8:00AM-9:00AM',
        '09' => '9:00AM-10:00AM',
        '10' => '10:00AM-11:00AM',
        '11' => '11:00AM-12:00NN',
        '12' => '12:00PM-01:00PM',
        '13' => '1:00PM-2:00PM',
        '14' => '2:00PM-3:00PM',
        '15' => '3:00PM-4:00PM',
        '16' => '4:00PM-5:00PM',
        '17' => '5:00PM-6:00PM',
        '18' => '6:00PM-7:00PM',
        '19' => '7:00PM-8:00PM',
        '20' => '8:00PM-9:00PM',
        '21' => '9:00PM-10:00PM',
    ],

    // Customer's Feedback choices: key => [label, badge classes].
    'feedback' => [
        'still_have_stocks' => ['STILL HAVE STOCKS', 'bg-[#c6dbe1] text-[#0a53a8]'],
        'no_budget' => ['NO BUDGET', 'bg-[#ffe5a0] text-[#473821]'],
        'ineffective' => ['INEFFECTIVE', 'bg-[#ffcfc9] text-[#b10202]'],
        'stopped_by_dr' => ['STOPPED BY THE DR.', 'bg-[#e6cff2] text-[#5a3286]'],
        'not_interested' => ['NOT INTERESTED', 'bg-[#e6e6e6] text-[#3d3d3d]'],
        'no_verbal_conv' => ['NO VERBAL CONV', 'bg-[#e6e6e6] text-[#3d3d3d]'],
        'currently_using' => ['CURRENTLY USING', 'bg-[#bfe1f6] text-[#0a53a8]'],
        'blocked' => ['BLOCKED', 'bg-[#3d3d3d] text-white'],
        'purchased' => ['PURCHASED', 'bg-[#d4edbc] text-[#11734b]'],
    ],

    /*
    | A lead counts as converted when Repeat Purchase is "yes", or Repeat
    | Purchase is "no" and Customer's Feedback is "purchased".
    */

    /*
    |--------------------------------------------------------------------------
    | Optional columns
    |--------------------------------------------------------------------------
    |
    | Columns users can hide/unhide, in display order. The first 11 columns
    | (Customer Name to Status) are always shown. Set 'coming_soon' to list a
    | column in the picker without rendering it yet.
    |
    */

    'optional_columns' => [
        'repeat_purchase' => ['label' => 'Repeat Purchase?'],
        'customer_tag' => ['label' => 'Customer Tagging'],
        'contact_date' => ['label' => 'Date of Contact'],
        'contact_time' => ['label' => 'Time of Contact'],
        'feedback' => ['label' => "Customer's Feedback"],
        'callback_date' => ['label' => 'Callback Date'],
        'call_recording_url' => ['label' => 'Call Recording Link', 'coming_soon' => true],
    ],

];
