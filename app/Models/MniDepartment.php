<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Monthly MNI: the department's Tax Allocation and Advertising Cost for a month and plan, split evenly across the CRAs.
 */
#[Fillable(['month', 'plan', 'tax_allocation', 'advertising_cost', 'updated_by'])]
class MniDepartment extends Model
{
    /** The department's inputs: field => label. */
    public const FIELDS = [
        'tax_allocation' => 'Tax Allocation',
        'advertising_cost' => 'Advertising Cost',
    ];

    /** Plans: key => label. */
    public const PLANS = ['1m' => '1M', '2m' => '2M'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'tax_allocation' => 'float',
            'advertising_cost' => 'float',
        ];
    }
}
