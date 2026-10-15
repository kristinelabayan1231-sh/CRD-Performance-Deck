<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PC Report: a CRA's page row's Total Inquiries and Total Engagement for one day, typed in by the CRA or their Supervisor.
 */
#[Fillable(['user_id', 'page', 'category', 'date', 'inquiries', 'engagements'])]
class PcReportEntry extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'inquiries' => 'integer',
            'engagements' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
