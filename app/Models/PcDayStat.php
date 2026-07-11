<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PcDayStat extends Model
{
    protected $fillable = [
        'pc_id',
        'date',
        'engagement',
        'orders',
    ];

    // date is stored and compared as a plain Y-m-d string.
    protected $casts = [
        'engagement' => 'integer',
        'orders' => 'integer',
    ];

    public function pc(): BelongsTo
    {
        return $this->belongsTo(Pc::class);
    }
}
