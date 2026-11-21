<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS Reminders
    |--------------------------------------------------------------------------
    |
    | Restock texts sent from each CRA's own phone (their MacroDroid phone link,
    | Settings → Call Recordings) to their customers whose product runs out in
    | segmentation.replenishment_days_before days, by the real date.
    |
    */

    // The MacroDroid webhook identifier of the "Send reminder" macro: the phone link with its last part swapped for this.
    'webhook_identifier' => 'sms',

    // Texts go out only in these windows (Manila), until Settings → SMS Reminders changes them; what's left after the
    // last window waits for the next day. Short windows with a break look less like a mass blast to the telcos.
    'sending_windows' => [
        ['from' => '09:00', 'until' => '11:00'],
        ['from' => '14:00', 'until' => '16:00'],
    ],

    // Defaults until set in Settings → SMS Reminders.
    'send_time' => '09:30',
    'daily_cap' => 200,

    // A phone that doesn't answer is tried again after this many minutes, at most this many times.
    'retry_minutes' => 5,
    'max_attempts' => 3,

    // Queued texts are still sent this many days after their reminder day (a phone that was off); older ones are dropped.
    'stale_after_days' => 2,

    // Customer types a template can be for: key => label.
    'customer_types' => [
        'first' => 'First reorder',
        'repeat' => 'Repeat',
        'vip' => 'High AOV / VIP',
        'cold' => 'Cold',
    ],

    // Placeholders a template can use: placeholder => what it becomes.
    'placeholders' => [
        '{first_name}' => 'Customer\'s first name',
        '{product}' => 'Product(s) running out',
        '{run_out_date}' => 'Run-out date, e.g. 27 Nov',
        '{cra_name}' => 'Their CRA\'s name',
    ],

    // Used when no template fits.
    'default_body' => 'Hi {first_name}! Malapit na maubos ang {product} mo (~{run_out_date}). Reply YES para ma-reserve ang restock mo, o tatawagan ka ni {cra_name}.',

    // Added to every text that doesn't already say it.
    'opt_out_line' => 'Reply STOP to opt out.',

    // Replies (whole message, any case) that mean the customer wants to reorder, or never wants a text again.
    'yes_words' => ['YES', 'OO', 'Y', 'OPO', 'SIGE'],
    'stop_words' => ['STOP', 'HINTO', 'AYAW', 'UNSUBSCRIBE'],

    // Sent once when a customer replies STOP.
    'stop_confirmation' => 'Noted, {first_name}. Hindi ka na makakatanggap ng reminders mula sa amin.',

];
