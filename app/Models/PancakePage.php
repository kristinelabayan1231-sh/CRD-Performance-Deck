<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A Facebook page connected to Pancake, read for chat engagements.
 */
#[Fillable(['name', 'page_id', 'access_token', 'is_active', 'checked_at', 'check_ok', 'check_message'])]
#[Hidden(['access_token'])]
class PancakePage extends Model
{
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'is_active' => 'boolean',
            'checked_at' => 'datetime',
            'check_ok' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The token's last four characters, for showing which token is saved.
     */
    public function maskedToken(): string
    {
        return '••••'.substr((string) $this->access_token, -4);
    }
}
