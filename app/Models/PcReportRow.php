<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PC Report: a CRA's page row with its Tagging. A split page has a DORMANT and an ACTIVE row instead.
 * The Creation/Update Time is set per day on PcReportEntry; from_month / to_month here are no longer read.
 */
#[Fillable(['user_id', 'page', 'category', 'from_month', 'to_month', 'tagging'])]
class PcReportRow extends Model
{
    public const DORMANT = 'DORMANT';

    public const ACTIVE = 'ACTIVE';

    /** A split page's rows and the months each starts with: Dormant May 2024 – Dec 2025, Active 2026 onwards. */
    public const SPLIT = [
        self::DORMANT => ['2024-05', '2025-12'],
        self::ACTIVE => ['2026-01', null],
    ];

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
