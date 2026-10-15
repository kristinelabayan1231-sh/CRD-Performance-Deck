<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PC Report: a CRA's page row with its Tagging and Creation/Update Time (the months its
 * conversations were created in). A split page has a DORMANT and an ACTIVE row instead.
 */
#[Fillable(['user_id', 'page', 'category', 'from_month', 'to_month', 'tagging'])]
class PcReportRow extends Model
{
    public const DORMANT = 'DORMANT';

    public const ACTIVE = 'ACTIVE';

    protected function casts(): array
    {
        return [
            'from_month' => 'immutable_date',
            'to_month' => 'immutable_date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
