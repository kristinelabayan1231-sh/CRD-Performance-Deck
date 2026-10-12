<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A High AOV / VIP customer's CRA (assigning seller) and the CRA's notes on them, by the customer's main number.
 */
#[Fillable(['phone_key', 'assigned_cra_id', 'assigned_at', 'cra_set_by_hand', 'point_of_contact', 'buyer_type', 'remarks', 'updated_by'])]
class CustomerAccount extends Model
{
    protected function casts(): array
    {
        return [
            'assigned_cra_id' => 'integer',
            'assigned_at' => 'datetime',
            'cra_set_by_hand' => 'boolean',
        ];
    }

    public function cra(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_cra_id');
    }
}
