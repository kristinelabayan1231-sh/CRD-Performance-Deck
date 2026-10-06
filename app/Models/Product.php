<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'keywords', 'consumption_days', 'created_by'])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'consumption_days' => 'integer',
        ];
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
