<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * date is stored and compared as a plain Y-m-d string, not cast to Carbon —
 * casting it broke updateOrCreate's unique-constraint matching on other
 * daily-stat tables (pc_day_stats/cra_pc_day_stats) in this app previously.
 */
class CraCallStat extends Model
{
    protected $fillable = ['cra_id', 'date', 'total_calls', 'answered_calls'];

    public function cra(): BelongsTo
    {
        return $this->belongsTo(Cra::class);
    }

    public function pickUpRate(): float
    {
        return $this->total_calls > 0 ? $this->answered_calls / $this->total_calls : 0.0;
    }
}
