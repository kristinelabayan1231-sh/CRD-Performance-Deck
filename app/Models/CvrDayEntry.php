<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * CVR Tracker: a CRA's Unconfirmed customers for one day, typed in by the CRA or a supervisor.
 */
#[Fillable(['user_id', 'date', 'unconfirmed'])]
class CvrDayEntry extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'unconfirmed' => 'integer',
        ];
    }
}
