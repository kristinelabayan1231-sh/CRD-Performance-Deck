<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A delivered order from the retention API or Pancake POS, kept so leads can
 * still be worked out when the retention API is down.
 */
#[Fillable([
    'order_id', 'tracking_number', 'customer_name', 'phone_number', 'product_raw', 'qty',
    'delivered_date', 'consumption_days_per_unit', 'source',
])]
class DeliveredOrder extends Model
{
    public const SOURCE_SHECOM = 'shecom';

    public const SOURCE_PANCAKE = 'pancake';

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'delivered_date' => 'immutable_date',
            'consumption_days_per_unit' => 'integer',
        ];
    }
}
