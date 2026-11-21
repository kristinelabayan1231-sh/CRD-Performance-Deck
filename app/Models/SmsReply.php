<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A text a customer sent to a CRA's phone, passed on by its "Forward replies" macro (POST /api/sms-replies).
 * YES means they want to reorder, STOP puts the number on the do-not-text list.
 */
#[Fillable(['user_id', 'restock_reminder_id', 'phone_key', 'phone_number', 'body', 'kind', 'received_at', 'handled_at', 'handled_by'])]
class SmsReply extends Model
{
    public const YES = 'yes';

    public const STOP = 'stop';

    public const OTHER = 'other';

    /** Kind => [label, badge classes]. */
    public const KINDS = [
        self::YES => ['Wants to reorder', 'bg-[#d4edbc] text-[#11734b]'],
        self::STOP => ['Opted out', 'bg-[#e6e6e6] text-[#3d3d3d]'],
        self::OTHER => ['Needs an answer', 'bg-[#dde9f7] text-[#1a4f8c]'],
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * Customers who replied YES and haven't been handled yet (the CRA still has to call them).
     */
    public function scopeWantsToReorder(Builder $query): void
    {
        $query->where('kind', self::YES)->whereNull('handled_at');
    }

    public function cra(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(RestockReminder::class, 'restock_reminder_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * What a reply means: STOP and YES words count only as the whole message (any case, punctuation ignored).
     */
    public static function kindOf(string $body): string
    {
        $word = strtoupper(trim(preg_replace('/[^\p{L}\s]+/u', '', $body)));

        return match (true) {
            in_array($word, config('sms_reminders.stop_words'), true) => self::STOP,
            in_array($word, config('sms_reminders.yes_words'), true) => self::YES,
            default => self::OTHER,
        };
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind][0] ?? $this->kind;
    }

    public function kindClasses(): string
    {
        return self::KINDS[$this->kind][1] ?? '';
    }
}
