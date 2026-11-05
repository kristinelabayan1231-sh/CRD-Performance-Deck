<?php

namespace App\Models;

use Database\Factories\DutyScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CRA's planned day off, set ahead in My schedule (on-duty pop-up): on it they are Absent / Rest Day from the start
 * of the day, unless they log in on duty anyway.
 */
#[Fillable(['user_id', 'date', 'type'])]
class DutySchedule extends Model
{
    /** @use HasFactory<DutyScheduleFactory> */
    use HasFactory;

    public const LEAVE = 'leave';

    public const REST = 'rest';

    public const TYPES = [
        self::LEAVE => 'Absent / Leave',
        self::REST => 'Rest Day',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
