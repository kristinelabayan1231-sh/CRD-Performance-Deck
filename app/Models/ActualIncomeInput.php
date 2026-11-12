<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Actual Income: one amount typed in for a month's card. user_id is the CRA's card; null is the overall CRD card.
 */
#[Fillable(['month', 'user_id', 'field', 'amount', 'updated_by'])]
class ActualIncomeInput extends Model
{
    protected function casts(): array
    {
        return ['month' => 'immutable_date', 'amount' => 'float'];
    }
}
