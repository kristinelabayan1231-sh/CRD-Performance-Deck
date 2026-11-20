<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A restock text for a product and/or customer type (blank = any). A customer gets the most specific active template
 * that fits: product + type, then product, then type, then any (RestockReminders::templateFor).
 */
#[Fillable(['name', 'product_id', 'customer_type', 'body', 'is_active', 'updated_by'])]
class SmsTemplate extends Model
{
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
}
