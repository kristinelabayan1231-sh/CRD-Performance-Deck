<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CraDayFinalization extends Model
{
    // date is stored and compared as a plain Y-m-d string, like
    // CraPcAssignment::week_start.
    protected $fillable = [
        'cra_id',
        'date',
        'finalized_by',
    ];

    public function cra(): BelongsTo
    {
        return $this->belongsTo(Cra::class);
    }
}
