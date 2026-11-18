<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A lead a supervisor took off an exclusive CRA (the Tag column's Remove, or the POS tag taken off its order): it never
 * goes on her lists or is held for her again, unless the tag goes back on its order. $via: website or pos.
 */
#[Fillable(['user_id', 'lead_id', 'removed_by', 'via'])]
class ExclusiveRemovedLead extends Model
{
    public const VIA_WEBSITE = 'website';

    public const VIA_POS = 'pos';
}
