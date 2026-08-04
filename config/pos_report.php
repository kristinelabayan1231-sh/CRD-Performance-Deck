<?php

return [

    // Published Google Sheets (File > Share > Publish to web > CSV) that
    // mirror the Pancake POS order data. Google's publish-to-web export
    // only ever returns the current month's tab, so each month gets its
    // own URL here rather than replacing the previous one.
    'csv_urls' => array_values(array_filter([
        env('POS_REPORT_CSV_URL'),
        env('POS_REPORT_CSV_URL_AUGUST'),
    ])),

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
        'CRD Anna Paclibare' => 'CRD ANNA PACLIBARE',
    ],

];
