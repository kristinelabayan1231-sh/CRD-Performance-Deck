<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS Reminders
    |--------------------------------------------------------------------------
    |
    | Texts sent from each CRA's own phone (their MacroDroid phone link,
    | Settings → Call Recordings) to the customers on their tracker list for
    | the day, so a text can take the place of a call.
    |
    */

    // The MacroDroid webhook identifier of the "Send reminder" macro: the phone link with its last part swapped for this.
    'webhook_identifier' => 'sms',

    // Texts go out only in these windows (Manila), until Settings → SMS Reminders changes them; what's left after the
    // last window waits for the next day. Short windows with a break look less like a mass blast to the telcos.
    // At one text a minute, a 2½-hour window sends the 150 a window of the daily cap below.
    'sending_windows' => [
        ['from' => '08:30', 'until' => '11:00'],
        ['from' => '13:30', 'until' => '16:00'],
    ],

    // Follow-up texts a CRA sends by hand (one at a time, right away) may go out between these hours (Manila), outside
    // the sending windows too: a customer who replied shouldn't wait for the next window.
    'manual_hours' => ['from' => '08:00', 'until' => '20:00'],

    // Defaults until set in Settings → SMS Reminders. The daily cap is shared between the windows, each window half list
    // texts and half follow-ups (a kind with nothing waiting leaves its share to the other); what isn't sent carries over.
    'send_time' => '08:30',
    'daily_cap' => 300,

    // A phone that doesn't answer is tried again after this many minutes, at most this many times.
    'retry_minutes' => 5,
    'max_attempts' => 3,

    // A text handed to a phone that hasn't confirmed it went out (POST /api/sms-sent) within this many minutes is failed.
    'confirm_minutes' => 15,

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
        '{run_out_date}' => 'Out-of-stock date on the tracker list, e.g. 27 Oct',
        '{cra_name}' => 'Your name in texts (set it above)',
    ],

    // Used when no template fits.
    'default_body' => 'Hi {first_name}! Malapit na maubos ang {product} mo (~{run_out_date}). Reply YES para ma-reserve ang restock mo, o tatawagan ka ni {cra_name}.',

    // Sent instead of any reminder template when the customer's product already ran out before the text goes out (the
    // tracker lists run about a month behind, and leftovers even more): no date, no "malapit na maubos". Settings → SMS
    // Reminders can change it.
    'overdue_body' => 'Hi {first_name}! Kumusta po ang {product} mo? Matagal-tagal na po mula nung huli niyong order, baka ubos na po. Gusto niyo po bang mag-restock? Reply YES para ma-reserve, o tatawagan ka ni {cra_name}.',

    // A follow-up's text when the CRA has no follow-up template that fits.
    'follow_up_body' => 'Hi {first_name}, kumusta po ang {product} mo? Kung paubos na, pwede po namin i-reserve ang next order mo. Reply YES o tatawagan ka ni {cra_name}.',

    // Added to every text that doesn't already say it.
    'opt_out_line' => 'Reply STOP to opt out.',

    // Replies (whole message, any case) that mean the customer wants to reorder, or never wants a text again.
    'yes_words' => ['YES', 'OO', 'Y', 'OPO', 'SIGE'],
    'stop_words' => ['STOP', 'HINTO', 'AYAW', 'UNSUBSCRIBE'],

    // Sent once when a customer replies STOP.
    'stop_confirmation' => 'Noted, {first_name}. Hindi ka na makakatanggap ng reminders mula sa amin.',

];
