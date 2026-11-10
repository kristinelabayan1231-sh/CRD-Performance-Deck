<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * CRD - MC as it stood in a month (Y-m): what the MC / salaries API returns for that month (McPayroll).
 */
#[Fillable(['month', 'summary', 'roster', 'content_hash', 'changed_at'])]
class MonthlyCostSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'roster' => 'array',
            'changed_at' => 'datetime',
        ];
    }
}
