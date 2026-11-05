<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contact attempt on a cold lead (Segmentation Tracker → Log attempt), by the CRA who had it that day.
 */
#[Fillable(['lead_id', 'user_id', 'attempted_on'])]
class LeadAttempt extends Model
{
    protected function casts(): array
    {
        return [
            'attempted_on' => 'immutable_date',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
