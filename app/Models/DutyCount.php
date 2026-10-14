<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * CRAs on duty on a day, entered by hand (without who); used instead of that day's log-ins.
 */
#[Fillable(['date', 'count'])]
class DutyCount extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'count' => 'integer',
        ];
    }
}
