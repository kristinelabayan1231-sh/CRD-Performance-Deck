<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A Pancake POS order, kept for Segmentation Productivity conversions.
 */
#[Fillable([
    'pancake_order_id', 'ordered_on', 'ordered_at', 'seller_pancake_id', 'seller_name', 'customer_name',
    'phone_number', 'phone_key', 'status', 'status_name', 'total_price', 'page_name',
])]
class PancakeOrder extends Model
{
    /** Pancake POS statuses that don't count as an order: 6 canceled, 7 deleted. */
    public const NOT_COUNTED_STATUSES = [6, 7];

    protected function casts(): array
    {
        return [
            'ordered_on' => 'immutable_date',
            'ordered_at' => 'datetime',
            'status' => 'integer',
            'total_price' => 'decimal:2',
        ];
    }

    public function scopeCounted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('status')->orWhereNotIn('status', self::NOT_COUNTED_STATUSES));
    }
}
