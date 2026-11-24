<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MonthlyCostRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A position in CRD - MC and its monthly salary, typed in. A row without a name is a vacant position and costs nothing.
 * pay_type is always fixed now; Trainee, Probationary and Regular were daily rate × working days before. user_id = the
 * CRA whose own position this is (Monthly MNI gives that CRA its salary).
 *
 * A row counts from the start until the day it's deleted, and a CRA's own row until the day that CRA is disabled: the
 * day they're removed and the days after leave it out, earlier days keep it (countsOn).
 */
#[Fillable(['position', 'name', 'user_id', 'pay_type', 'salary', 'sort_order', 'updated_by'])]
class MonthlyCostRow extends Model
{
    /** @use HasFactory<MonthlyCostRowFactory> */
    use HasFactory, SoftDeletes;

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

    /**
     * The rows that count on $day (default today), deleted ones included up to the day before they were deleted. Pass
     * $rows (every row, deleted ones too, with their CRA) to filter them instead of reading them again.
     *
     * @param  Collection<int, MonthlyCostRow>|null  $rows
     * @return Collection<int, MonthlyCostRow>
     */
    public static function countingOn(?CarbonImmutable $day = null, ?Collection $rows = null): Collection
    {
        $day ??= CarbonImmutable::now(config('segmentation.timezone'));
        $rows ??= self::withTrashed()->with('cra')->orderBy('sort_order')->orderBy('id')->get();

        return $rows->filter(fn (MonthlyCostRow $row) => $row->countsOn($day))->values();
    }

    /**
     * Counted on $day: not deleted by then, and, for a CRA's own row, that CRA still active that day.
     */
    public function countsOn(CarbonImmutable $day): bool
    {
        if ($this->deleted_at !== null && $this->deleted_at->copy()->timezone(config('segmentation.timezone'))->toDateString() <= $day->toDateString()) {
            return false;
        }

        return $this->cra === null || $this->cra->wasActiveOn($day);
    }

    /**
     * Linked to a CRA who is disabled now: the row stays listed but no longer counts.
     */
    public function isForDisabledCra(): bool
    {
        return $this->cra !== null && ! $this->cra->is_active;
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
