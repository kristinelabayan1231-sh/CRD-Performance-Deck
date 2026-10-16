<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * CVR Tracker: what the CRA picked for one Pancake order. order_type is set only when the CRA changed it from the
 * order's CRD tag; null means the tag decides. From the CRAs' sheets: user_id is the CRA the order counts for when it
 * was made under another seller account (null: its seller), position its place in that CRA's list (null: after the
 * listed ones, by time), lead_source the sheet's Assigned / Pancake (null: worked out from the Segmentation Tracker).
 */
#[Fillable(['pancake_order_id', 'user_id', 'position', 'lead_source', 'upsell', 'order_type', 'point_of_contact', 'updated_by'])]
class CvrOrderEntry extends Model
{
    /** Upsell choices: key => [label, badge classes]. */
    public const UPSELLS = [
        'cra' => ['CRA Upsell', 'bg-[#fde9a6] text-[#5b4300]'],
        'without' => ['W/O Upsell', 'bg-[#fadad6] text-[#a3261d]'],
        'ai' => ['AI Upsell', 'bg-[#ececec] text-[#3b3f46]'],
        'chat' => ['Chat Upsell', 'bg-[#f3d2c3] text-[#8f3413]'],
    ];

    /** Type of Order choices, keyed like PancakeOrder::conversion_type. */
    public const ORDER_TYPES = [
        PancakeOrder::SEGMENTATION => ['Segmentation', 'bg-brand-50 text-brand-700'],
        PancakeOrder::BROADCAST => ['Broadcast', 'bg-[#d9f2ef] text-[#0b6b5d]'],
    ];

    /** Point of Contact choices. */
    public const POINTS_OF_CONTACT = [
        'chat' => ['Chat', 'bg-ink text-white'],
        'called' => ['Called', 'bg-[#d4eef8] text-[#156c8c]'],
        'text' => ['Text/SMS', 'bg-[#e3f3d3] text-[#3d6b12]'],
    ];

    /** lead_source values. */
    public const ASSIGNED = 'assigned';

    public const PANCAKE = 'pancake';

    /** A choice not picked yet. */
    public const UNSET_CLASSES = 'bg-white text-[#8a5f00] ring-1 ring-inset ring-[#e9b949]';
}
