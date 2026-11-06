<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lead on an exclusive CRA's list for one day, and why it is there (ExclusiveLeads). A lead carried over is on
 * both days' lists; rows are never removed, so a day's list stays what the CRA was given.
 */
#[Fillable(['user_id', 'list_date', 'lead_id', 'source'])]
class ExclusiveListLead extends Model
{
    protected function casts(): array
    {
        return [
            'list_date' => 'immutable_date',
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
