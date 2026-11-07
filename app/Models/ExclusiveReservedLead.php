<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A lead held for an exclusive CRA's days ahead (ExclusiveLeads): no other CRA gets it, and it isn't theirs to work until
 * it is handed to them on a day's list. $month is the first day of the real month it is held for.
 */
#[Fillable(['user_id', 'month', 'lead_id', 'source'])]
class ExclusiveReservedLead extends Model
{
    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
        ];
    }
}
