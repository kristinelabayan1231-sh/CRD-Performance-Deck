<?php

namespace App\Models;

use Database\Factories\DutyLoginFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CRA on duty: they logged in from the Segmentation Tracker on this real day (one per CRA per day).
 */
#[Fillable(['user_id', 'date', 'logged_in_at'])]
class DutyLogin extends Model
{
    /** @use HasFactory<DutyLoginFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'logged_in_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
