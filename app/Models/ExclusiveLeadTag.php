<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An exclusive CRA's lead whose POS order tag (exclusive_leads.order_tag) the website looks after. $tagged_at: when its
 * order was seen with the tag; null with $failed_at when Pancake couldn't tag it, null without when a supervisor took the
 * tag off a lead she had already worked (left alone from then on).
 */
#[Fillable(['user_id', 'lead_id', 'tagged_at', 'failed_at', 'last_error'])]
class ExclusiveLeadTag extends Model
{
    protected function casts(): array
    {
        return [
            'tagged_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
