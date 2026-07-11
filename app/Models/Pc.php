<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pc extends Model
{
    protected $fillable = [
        'label',
        'facebook_page_id',
        'pancake_user_id',
        'pancake_user_name',
    ];

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CraPcAssignment::class);
    }

    public function dayStats(): HasMany
    {
        return $this->hasMany(PcDayStat::class);
    }
}
