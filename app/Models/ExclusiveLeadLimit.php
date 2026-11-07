<?php

namespace App\Models;

use Database\Factories\ExclusiveLeadLimitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Once;

/**
 * Exclusive Lead Tracker limits from a real date on, until a later one takes over (ExclusiveLimits).
 */
#[Fillable(['effective_from', 'daily_leads', 'month_target', 'orders_per_day', 'set_by'])]
class ExclusiveLeadLimit extends Model
{
    /** @use HasFactory<ExclusiveLeadLimitFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => Once::flush());
        static::deleted(fn () => Once::flush());
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'daily_leads' => 'integer',
            'month_target' => 'integer',
            'orders_per_day' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
