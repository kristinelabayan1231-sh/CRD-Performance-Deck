<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Monthly MNI: one CRA's typed-in targets for a month and plan (1M / 2M). Percentages as typed (5 = 5%).
 */
#[Fillable(['month', 'plan', 'user_id', 'orders', 'conversion_rate', 'aov', 'cancelled_pct', 'returns_pct', 'product_cost_pct', 'updated_by'])]
class MniInput extends Model
{
    /** The inputs a CRA row has: field => label. */
    public const FIELDS = [
        'orders' => 'Number of Orders',
        'conversion_rate' => 'Conversion Rate %',
        'aov' => 'Average Order Value',
        'cancelled_pct' => '% Projected Cancelled',
        'returns_pct' => '% Projected Returns',
        'product_cost_pct' => '% Product Cost',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'orders' => 'integer',
            'conversion_rate' => 'float',
            'aov' => 'float',
            'cancelled_pct' => 'float',
            'returns_pct' => 'float',
            'product_cost_pct' => 'float',
        ];
    }
}
