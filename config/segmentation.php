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

    // Most leads a CRA is listed for a day: after their own leads, the backlog fill (BacklogFill) tops each
    // on-duty CRA up to this with their own oldest untouched backlog, then an even share of the unassigned pool.
    'max_leads_per_cra' => (int) env('SEGMENTATION_MAX_LEADS_PER_CRA', 100),

    // First lead day whose untouched leads move to their customer's CRA (LeadGenerator). Lead day Oct 8, 2026 = real
    // Nov 7: the rule that a customer's CRA is who handled the most of their orders starts then, and the lists CRAs
    // were already working stay as they were.
    'reclaim_from' => '2026-10-08',

    // First order day (real date) a CRA's own sale outside their day's list is added to it as catered (PosLeads). Earlier
    // days keep the figures they had.
    'outside_orders_from' => '2026-11-26',

    // Pancake POS order tag ids that make an order a CRD conversion (Conversion
    // Breakdown): "CRD - BROADCAST" and "CRD - SEGMENTATION". An order with
    // both tags counts as segmentation.
    'conversion_tags' => [
        'broadcast' => (int) env('PANCAKE_TAG_BROADCAST', 397),
        'segmentation' => (int) env('PANCAKE_TAG_SEGMENTATION', 398),
    ],

    // First day the CRAs tagged orders CRD - BROADCAST / CRD - SEGMENTATION. Before it every CRA order counts, untagged.
    'conversion_tags_from' => '2026-07-09',

    // First day the Segmentation Tracker holds purchases. Before it, supervisors' Retained and Repeat tiles come
    // from Shecom's deliveries (DeliveredRetention).
    'tracker_repeats_from' => '2026-10-01',

    // Default sales goals until they are set in Settings → Sales Goals (pesos).
    'sales_goals' => [
        'cra_daily' => 77000,
        'crd_monthly' => 1000000,
        // Each CRA's Net Income goal for a month (CRA Gross Sales & IBSC shows a positive Net Income under it in red).
        'cra_net_income_monthly' => 250000,
        // Monthly customer goals: % of all the leads delivered in the dates picked.
        'retained_rate' => 30,
        'repeat_rate' => 30,
    ],

    // CRA Gross Sales & IBSC's ACB: (the year's positive + negative Net Income) × rate. A month before
    // capped_until only counts when its Net Income is below cap; from capped_until on every month counts.
    'acb' => [
        'rate' => 0.014,
        'cap' => 250000,
        'capped_until' => '2026-06-01',
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
        'blocked' => ['Blocked', 'bg-[#3d3d3d] text-white'],
    ],

    // Picking one of these statuses sets the lead's Customer's Feedback too: status => feedback key.
    'status_feedback' => [
        'pjr_drop_call' => 'no_verbal_conv',
    ],

    // Leads with no status, or one of these statuses, carry over to the next
    // day with the same CRA until their status changes to something else.
    'carry_over_statuses' => ['pjr_drop_call', 'inactive'],

    // Every status except these is a follow-up, listed under Reactivation in the Pending full view once the lead
    // day has passed (so statuses added or renamed in Segmentation Options are included).
    'reactivation_skip_statuses' => ['active', 'blocked'],

    // Cold leads (these Customer Tagging keys) get cold_attempts contact attempts, logged by the CRA with the row's
    // Attempt button (one per day, kept when the lead moves to another CRA). Once used up without an Active status
    // the lead leaves Reactivation.
    // cold_attempts is the default until it's set in Settings → Segmentation Tracker.
    'cold_tags' => ['cold', 'canpro_cold'],
    'cold_attempts' => 3,

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

    // Summary → NO VERBAL CONV reasons: a lead's note goes to the first group with a keyword in it
    // (case-insensitive); notes matching none are "Other". A lead with no note goes by its status's label the same way
    // (a status "PJR" joins the PJR group), else under its status, else "No note". Unattended comes first because
    // "unattended" contains "ended".
    'no_verbal_reasons' => [
        'unattended' => ['Unattended', ['unattended', 'uanttended', 'unatended']],
        'ended' => ['Drop call', ['ended', 'end call', 'call end', 'dropped', 'drop call', 'dropcall', 'hung up', 'didn\'t talk', 'did not talk']],
        'wrong_number' => ['Invalid / wrong number', ['invalid', 'incorrect', 'wrong', 'not the customer', 'kapatid', 'anak', 'asawa', 'partner', 'nanay', 'tatay']],
        'busy' => ['Busy', ['busy']],
        'cbr' => ['CBR / out of coverage', ['cbr', 'cannot be reached', 'can\'t be reached', 'out of coverage', 'not reachable', 'unreachable']],
        'pjr' => ['PJR (phone just rings)', ['pjr', 'ring', 'dialling', 'dialing', 'no answer']],
    ],

    // Summary → why customers gave the other Customer's Feedback choices (all but NO VERBAL CONV and PURCHASED): a note
    // counts in every reason with a keyword in it (case-insensitive, Tagalog and English), so "no budget, may stock pa"
    // is both No budget and Still has stock. Notes matching none are "Other". What the CRA did ("offered discount and
    // reservation") has no keyword, so it never counts as a reason. Listed in this order when tied.
    'feedback_reasons' => [
        'stock' => ['Still has stock', ['stock', 'hindi pa ubos', 'di pa ubos', 'marami pa']],
        'budget' => ['No budget', ['budget', 'walang pera', 'gipit']],
        'no_improvement' => ['No improvement seen', ['no effect', 'no improvement', 'walang improvement', 'wala naging improvement', 'wala daw improvement', 'walang epekto', 'not effective', 'hindi effective', 'di effective', 'di raw effective', 'nakikitaan', 'nakikita ang improvement']],
        'irregular' => ['Not used regularly', ['not regular', 'hindi regular', 'di regular', '1 beses', 'isang beses', 'hindi pa nagagamit', 'di pa nagagamit', 'hindi pa nagamit']],
        'feels_better' => ['Feels better / improving', ['okay na', 'ok na', 'may improvement', 'gumaling', 'magaling na']],
        'not_user' => ['Someone else answered (not the user)', ['anak', 'asawa', 'kapatid', 'brother', 'sister', 'partner', 'nanay', 'tatay', 'hindi rin yung gumagamit', 'ang nagamit', 'ang gumagamit']],
        'side_effect' => ['Bad experience (stings, smells, reaction)', ['hapdi', 'mabaho', 'tumubong', 'refund', 'irritat', 'allerg', 'pantal']],
        'doctor' => ['Saw a doctor / surgery', ['doctor', 'doktor', 'check up', 'checkup', 'opera', 'dr.']],
        'stopped' => ['Stopped using', ['stop', 'tinigil', 'hindi na ginagamit', 'di na ginagamit']],
        'later' => ['Will order later / chat instead', ['chat', 'tatawag', 'saka na', 'next time', 'sa susunod', 'mamaya', 'try niya', 'pa order lang']],
        'just_ordered' => ['Just ordered', ['kakabili', 'kakaorder', 'kaka order']],
        'slow' => ['Slow improvement', ['mabagal']],
        'fake' => ["Thinks it's fake", ['fake', 'peke']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Editable dropdowns
    |--------------------------------------------------------------------------
    |
    | Lists that Settings → Segmentation Tracker can change (saved choices
    | replace the defaults above). 'column' is the lead field holding the key;
    | 'locked' keys are used by the deck's rules, so they can be renamed and
    | recoloured but not removed.
    |
    */

    'editable_lists' => [
        'statuses' => ['label' => 'Status', 'column' => 'status', 'locked' => ['inactive', 'pjr_drop_call']],
        'repeat_purchase' => ['label' => 'Repeat Purchase?', 'column' => 'repeat_purchase', 'locked' => ['yes', 'no']],
        'customer_tags' => ['label' => 'Customer Tagging', 'column' => 'customer_tag', 'locked' => ['hot', 'warm', 'cold', 'high_value', 'canpro_hot', 'canpro_warm', 'canpro_cold']],
        'feedback' => ['label' => "Customer's Feedback", 'column' => 'feedback', 'locked' => ['no_verbal_conv', 'purchased', 'still_have_stocks']],
    ],

    // Badge colours an edited choice can use: name => badge classes.
    'option_colors' => [
        'Green' => 'bg-[#d4edbc] text-[#11734b]',
        'Gray' => 'bg-[#e6e6e6] text-[#3d3d3d]',
        'Lavender' => 'bg-[#e6cff2] text-[#5a3286]',
        'Brown' => 'bg-[#753800] text-[#ffcfc9]',
        'Dark brown' => 'bg-[#753800] text-[#ffe5a0]',
        'Steel blue' => 'bg-[#c6dbe1] text-[#0a53a8]',
        'Light blue' => 'bg-[#bfe1f6] text-[#0a53a8]',
        'Blue' => 'bg-[#0a53a8] text-white',
        'Pink' => 'bg-[#ffcfc9] text-[#b10202]',
        'Red' => 'bg-[#b10202] text-[#ffcfc9]',
        'Orange' => 'bg-[#ef6a3a] text-[#3b0d00]',
        'Yellow' => 'bg-[#ffe5a0] text-[#473821]',
        'Charcoal' => 'bg-[#3d3d3d] text-white',
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

    /*
     * The lead's own columns, Customer Name to Assigned to: shown by default, each can be hidden from the column
     * picker like the optional columns (Status always shows).
     */
    'lead_columns' => [
        'customer_name' => ['label' => 'Customer Name'],
        'qty' => ['label' => 'Qty'],
        'product' => ['label' => 'Product'],
        'contact_number' => ['label' => 'Contact #'],
        'delivered_date' => ['label' => 'Delivered Date'],
        'days_since_delivered' => ['label' => 'Days since Delivered'],
        'est_out_of_stock' => ['label' => 'Est. Out of Stock'],
        'replenishment_day' => ['label' => 'Recommended Replenishment Day'],
        'type' => ['label' => 'Type'],
        'assigned_to' => ['label' => 'Assigned to'],
    ],

    'optional_columns' => [
        'repeat_purchase' => ['label' => 'Repeat Purchase?'],
        'customer_tag' => ['label' => 'Customer Tagging'],
        'contact_date' => ['label' => 'Date of Contact'],
        'contact_time' => ['label' => 'Time of Contact'],
        'feedback' => ['label' => "Customer's Feedback"],
        'callback_date' => ['label' => 'Callback Date'],
        // The lead's calls from the CRAs' phones (Call Recordings), plus a link typed in the sheet before.
        'call_recording_url' => ['label' => 'Call Recording'],
    ],

];
