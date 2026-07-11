<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CraPcDayStat extends Model
{
    protected $fillable = [
        'cra_id',
        'pc_id',
        'date',
        'inquiries',
        'engagement',
        'amount',
        'tagging',
    ];

    // date is stored and compared as a plain Y-m-d string.
    protected $casts = [
        'inquiries' => 'integer',
        'engagement' => 'integer',
        'amount' => 'float',
    ];

    public function cra(): BelongsTo
    {
        return $this->belongsTo(Cra::class);
    }

    public function pc(): BelongsTo
    {
        return $this->belongsTo(Pc::class);
    }
}
