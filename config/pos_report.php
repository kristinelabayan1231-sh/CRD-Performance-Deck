<?php

return [

    // Published Google Sheet (File > Share > Publish to web > CSV) that
    // mirrors the Pancake POS order data.
    'csv_url' => env('POS_REPORT_CSV_URL'),

    // Only assigning sellers whose name contains this (case-insensitive)
    // are shown on the deck.
    'seller_filter' => 'CRD',

];
