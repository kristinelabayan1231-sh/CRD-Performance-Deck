<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer a CRA claimed from the up-for-reassignment list, by the customer's main number. The claim beats who handled
 * the most orders until another CRA claims the customer (CustomerReassignment).
 */
#[Fillable(['phone_key', 'user_id', 'previous_cra_id', 'claimed_at'])]
class CustomerClaim extends Model
{
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'previous_cra_id' => 'integer',
            'claimed_at' => 'immutable_datetime',
        ];
    }

    public function cra(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
