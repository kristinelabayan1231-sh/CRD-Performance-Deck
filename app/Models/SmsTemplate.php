<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A text for a product and/or customer type (blank = any). A reminder template is sent automatically: a customer gets
 * the most specific active one that fits (product + type, then product, then type, then any;
 * RestockReminders::templateFor). Every active template, follow-up ones too, can be picked for a follow-up typed by hand
 * (the Text button); a follow-up template is never sent automatically.
 */
#[Fillable(['name', 'purpose', 'product_id', 'customer_type', 'body', 'is_active', 'updated_by'])]
class SmsTemplate extends Model
{
    public const REMINDER = 'reminder';

    public const FOLLOW_UP = 'follow_up';

    /** Purpose => label. */
    public const PURPOSES = [
        self::REMINDER => 'Restock reminder (sent automatically)',
        self::FOLLOW_UP => 'Follow-up (Text button only)',
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
