<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer's restock text for one reminder day (their product runs out replenishment_days_before days later), for
 * their own CRA. Pending → (ticked) queued at the send time or on Send now → sent from the CRA's phone; or left out by
 * the CRA (excluded), or skipped by the app (opted out, no phone link), or failed.
 */
#[Fillable([
    'phone_key', 'phone_number', 'customer_name', 'product_id', 'products', 'customer_type', 'run_out_on', 'remind_on',
    'user_id', 'status', 'excluded_reason', 'status_updated_by', 'sms_template_id', 'message', 'attempts',
    'next_attempt_at', 'queued_at', 'sent_at', 'error',
])]
class RestockReminder extends Model
{
    public const PENDING = 'pending';

    public const EXCLUDED = 'excluded';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** Status => [label, badge classes]. */
    public const STATUSES = [
        self::PENDING => ['Will be texted', 'bg-[#fbeccd] text-[#7a4d00]'],
        self::QUEUED => ['Sending…', 'bg-[#dde9f7] text-[#1a4f8c]'],
        self::SENT => ['Sent', 'bg-[#d4edbc] text-[#11734b]'],
        self::EXCLUDED => ['Left out', 'bg-[#e6e6e6] text-[#3d3d3d]'],
        self::SKIPPED => ['Skipped', 'bg-[#e6e6e6] text-[#3d3d3d]'],
        self::FAILED => ['Failed', 'bg-[#fde0e0] text-[#a3271f]'],
    ];

    /** Reasons a CRA can pick when leaving a customer out. */
    public const EXCLUDE_REASONS = [
        'Talked to them already',
        'Already ordered',
        'Asked not to be texted',
        'Other',
    ];

    protected function casts(): array
    {
        return [
            'run_out_on' => 'immutable_date',
            'remind_on' => 'immutable_date',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function cra(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Follow-up texts sent by hand to this customer.
     */
    public function manualMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
    }

    /**
     * Reminders the CRA can still leave out or put back: not yet sent, skipped or failed.
     */
    public function scopeChangeable(Builder $query): void
    {
        $query->whereIn('status', [self::PENDING, self::QUEUED, self::EXCLUDED]);
    }

    public function isChangeable(): bool
    {
        return in_array($this->status, [self::PENDING, self::QUEUED, self::EXCLUDED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusClasses(): string
    {
        return self::STATUSES[$this->status][1] ?? '';
    }

    public function customerTypeLabel(): string
    {
        return config('sms_reminders.customer_types')[$this->customer_type] ?? $this->customer_type;
    }

    public function firstName(): string
    {
        return ucfirst(mb_strtolower(strtok(trim((string) $this->customer_name), ' ') ?: ''));
    }
}
