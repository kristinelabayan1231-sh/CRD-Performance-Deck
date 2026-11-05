<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A backlog lead handed to a CRA on a lead day: by the daily fill (their own untouched lead or one from the
 * pool) or a transfer. Segmentation Productivity's Backlog progress counts these.
 */
#[Fillable(['lead_id', 'user_id', 'handed_on', 'source'])]
class BacklogHandout extends Model
{
    public const OWN = 'own';

    public const POOL = 'pool';

    public const TRANSFER = 'transfer';

    protected function casts(): array
    {
        return ['handed_on' => 'immutable_date'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
