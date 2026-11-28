<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Actual Income: one amount typed in for a month's card. user_id is the CRA's card, monthly_cost_row_id a staff card
 * (config actual_income.staff); neither is the overall CRD card.
 */
#[Fillable(['month', 'user_id', 'monthly_cost_row_id', 'field', 'amount', 'updated_by'])]
class ActualIncomeInput extends Model
{
    protected function casts(): array
    {
        return ['month' => 'immutable_date', 'amount' => 'float'];
    }
}
