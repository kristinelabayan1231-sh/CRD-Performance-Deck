<?php

return [

    // Published Google Sheet (File > Share > Publish to web > CSV) that
    // mirrors the Pancake POS order data.
    'csv_url' => env('POS_REPORT_CSV_URL'),

    // Only assigning sellers whose name contains this (case-insensitive)
    // are shown on the deck.
    'seller_filter' => 'CRD',

    // Seller names that are actually the same person under multiple CSV
    // name variants — each key is merged into its value everywhere
    // (dropdown, filtering, report totals).
    'seller_aliases' => [
        'CRD JULY DE LOS SANTOS' => 'CRD JULY ANN',
        'CRD ANNA PACLIBARE 2' => 'CRD ANNA PACLIBARE',
        'CRD Joanna Paclibare' => 'CRD ANNA PACLIBARE',
    ],

];
