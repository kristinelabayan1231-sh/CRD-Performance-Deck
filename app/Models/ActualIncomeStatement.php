<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Actual Income taken from the CRD Sales Report sheet: one card of a month with every line as the sheet has it
 * (ActualIncome::LINES keys, plus the sheet-only lines). user_id is the CRA's card; null is the overall CRD card.
 */
#[Fillable(['month', 'user_id', 'figures'])]
class ActualIncomeStatement extends Model
{
    protected function casts(): array
    {
        return ['month' => 'immutable_date', 'figures' => 'array'];
    }
}
