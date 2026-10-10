<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'keywords', 'consumption_days', 'srp', 'not_crd', 'created_by'])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'consumption_days' => 'integer',
            'srp' => 'decimal:2',
            'not_crd' => 'boolean',
        ];
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
