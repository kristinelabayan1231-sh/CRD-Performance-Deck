<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'is_testing', 'is_tsd', 'keywords', 'exclude_keywords', 'consumption_days', 'age_min', 'age_max', 'srp', 'unit_cost', 'counts_from', 'created_by'])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'is_testing' => 'boolean',
            'is_tsd' => 'boolean',
            'consumption_days' => 'integer',
            'age_min' => 'integer',
            'age_max' => 'integer',
            'counts_from' => 'immutable_date',
            'srp' => 'decimal:2',
            'unit_cost' => 'decimal:2',
        ];
    }

    /**
     * The CRD products: the TSD upsell products left out (they count only in the CRAs' sales).
     */
    public function scopeCrd(Builder $query): Builder
    {
        return $query->where('is_tsd', false);
    }

    /**
     * Customer lifetime value: SRP × 30 units (config customers.cltv_units); null without an SRP.
     */
    public function cltv(): ?float
    {
        return $this->srp === null ? null : (float) $this->srp * config('customers.cltv_units');
    }

    /**
     * Extra keywords besides the product's own name, e.g. other spellings.
     *
     * @return list<string>
     */
    public function keywordList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->keywords))));
    }

    /**
     * Words that make an item not this product even though it matches (e.g. "Nasal Inhaler" for Sinuxyl).
     *
     * @return list<string>
     */
    public function excludeList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->exclude_keywords))));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
