<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A CRD customer's CRA-handled delivered orders from before the Customer Database's
 * first day (config customers.delivered_from), found once in Pancake POS by contact number.
 */
#[Fillable(['phone_key', 'prior_cra_orders', 'prior_last_ordered_on', 'checked_at'])]
class CustomerHistory extends Model
{
    protected function casts(): array
    {
        return [
            'prior_cra_orders' => 'integer',
            'prior_last_ordered_on' => 'immutable_date',
            'checked_at' => 'datetime',
        ];
    }
}
