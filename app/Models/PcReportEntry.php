<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PC Report: a CRA's page row for one day: Total Inquiries and Total Engagement, typed in by the CRA or their
 * Supervisor, and the Creation/Update Time (the months the conversations were created in) set by the Supervisor.
 */
#[Fillable(['user_id', 'page', 'category', 'date', 'inquiries', 'engagements', 'from_month', 'to_month'])]
class PcReportEntry extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'inquiries' => 'integer',
            'engagements' => 'integer',
            'from_month' => 'immutable_date',
            'to_month' => 'immutable_date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
