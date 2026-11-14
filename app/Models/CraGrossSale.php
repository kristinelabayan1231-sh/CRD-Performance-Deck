<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRA Gross Sales & IBSC: a CRA's Gross Sales and Net Income for a month, typed in by a supervisor (empty = not filled).
 * Copied once from the Actual Income cards on 2026-11-14; no longer taken from them.
 */
#[Fillable(['month', 'user_id', 'gross', 'net', 'updated_by'])]
class CraGrossSale extends Model
{
    protected function casts(): array
    {
        return ['month' => 'immutable_date', 'user_id' => 'integer', 'gross' => 'float', 'net' => 'float'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
