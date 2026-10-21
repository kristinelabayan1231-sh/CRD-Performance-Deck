<?php

namespace App\Models;

use Database\Factories\MonthlyCostRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A position in CRD - MC and its monthly salary, typed in. A row without a name is a vacant position and costs nothing.
 * pay_type is always fixed now; Trainee, Probationary and Regular were daily rate × working days before. user_id = the
 * CRA whose own position this is (Monthly MNI gives that CRA its salary).
 */
#[Fillable(['position', 'name', 'user_id', 'pay_type', 'salary', 'sort_order', 'updated_by'])]
class MonthlyCostRow extends Model
{
    /** @use HasFactory<MonthlyCostRowFactory> */
    use HasFactory;

    public const FIXED = 'fixed';

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function cra(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isFilled(): bool
    {
        return trim((string) $this->name) !== '';
    }

    public function monthlySalary(): float
    {
        return $this->isFilled() ? (float) $this->salary : 0.0;
    }
}
