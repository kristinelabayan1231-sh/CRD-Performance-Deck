<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Settings → Niche: the products whose Segmentation Tracker leads go to the niche's CRAs, and only those
 * (NicheRules). A CRA's own High AOV / VIP customers stay with them whatever the product.
 */
#[Fillable(['name', 'updated_by'])]
class Niche extends Model
{
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function cras(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
