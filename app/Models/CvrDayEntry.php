<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * CVR Tracker: a CRA's Unconfirmed customers for one day, typed in by the CRA or a supervisor, and on backfilled
 * days the rows of their manual sheet that match no Pancake order (sheet_missing: [{customer, amount}]).
 */
#[Fillable(['user_id', 'date', 'unconfirmed', 'sheet_missing'])]
class CvrDayEntry extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'unconfirmed' => 'integer',
            'sheet_missing' => 'array',
        ];
    }
}
