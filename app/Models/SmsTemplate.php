<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CRA's own text for a product and/or customer type (blank = any); one CRA's templates never change another's. A
 * reminder template is sent automatically for its CRA's customers: a customer gets the most specific active one of
 * their CRA's that fits (product + type, then product, then type, then any; RestockReminders::templateFor), else the
 * built-in default. A follow-up template is used the same way for their follow-up texts (sms_reminders.follow_up_body
 * when none fits), and the CRA can pick one when scheduling a follow-up. Every active template can be picked for a text
 * typed by hand (the Text button, Conversations).
 */
#[Fillable(['user_id', 'name', 'purpose', 'product_id', 'customer_type', 'body', 'is_active', 'updated_by'])]
class SmsTemplate extends Model
{
    public const REMINDER = 'reminder';

    public const FOLLOW_UP = 'follow_up';

    /** Purpose => label. */
    public const PURPOSES = [
        self::REMINDER => 'Restock reminder (sent automatically)',
        self::FOLLOW_UP => 'Follow-up (follow-up texts and the Text button)',
    ];

    protected $attributes = [
        'purpose' => self::REMINDER,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** The CRA whose template this is. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function customerTypeLabel(): string
    {
        return config('sms_reminders.customer_types')[$this->customer_type] ?? 'Any customer';
    }

    public function isFollowUp(): bool
    {
        return $this->purpose === self::FOLLOW_UP;
    }
}
