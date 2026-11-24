<?php

namespace App\Models;

use Database\Factories\DutyScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CRA's planned day off, set ahead in My schedule (on-duty pop-up): on it they are off from the start of the day,
 * unless they log in on duty anyway. A Rest Day, or one of the leaves (Vacation, Sick, Personal, Emergency). Absent is
 * set by a supervisor only, and is what a missed log-in (or a day set before the leaves were split) counts as.
 */
#[Fillable(['user_id', 'date', 'type'])]
class DutySchedule extends Model
{
    /** @use HasFactory<DutyScheduleFactory> */
    use HasFactory;

    public const REST = 'rest';

    public const VACATION = 'vacation';

    public const SICK = 'sick';

    public const PERSONAL = 'personal';

    public const EMERGENCY = 'emergency';

    public const LEAVE = 'leave';

    /** The leaves a CRA can file. */
    public const LEAVES = [
        self::VACATION => 'Vacation Leave',
        self::SICK => 'Sick Leave',
        self::PERSONAL => 'Personal Leave',
        self::EMERGENCY => 'Emergency Leave',
    ];

    /** What a CRA can set in My schedule. */
    public const PLANNABLE = [self::REST => 'Rest Day', ...self::LEAVES];

    /** Every day-off type; Absent is set by a supervisor, or a missed log-in. */
    public const TYPES = [...self::PLANNABLE, self::LEAVE => 'Absent'];

    /**
     * How each type looks: its short name (calendar cells), tag colors, cell border and legend dot.
     *
     * @var array<string, array{short: string, tag: string, border: string, dot: string}>
     */
    public const LOOKS = [
        self::REST => ['short' => 'Rest Day', 'tag' => 'bg-sky/20 text-sky-800', 'border' => 'border-sky', 'dot' => 'bg-sky'],
        self::VACATION => ['short' => 'Vacation', 'tag' => 'bg-brand-100 text-brand-700', 'border' => 'border-brand-400', 'dot' => 'bg-brand-500'],
        self::SICK => ['short' => 'Sick', 'tag' => 'bg-coral/20 text-coral-700', 'border' => 'border-coral', 'dot' => 'bg-coral'],
        self::PERSONAL => ['short' => 'Personal', 'tag' => 'bg-orange-200/60 text-orange-800', 'border' => 'border-orange-400', 'dot' => 'bg-orange-400'],
        self::EMERGENCY => ['short' => 'Emergency', 'tag' => 'bg-coral-700 text-white', 'border' => 'border-coral-700', 'dot' => 'bg-coral-700'],
        self::LEAVE => ['short' => 'Absent', 'tag' => 'bg-[#e4dfe9] text-ink', 'border' => 'border-[#b9b2c2]', 'dot' => 'bg-[#a59db0]'],
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
