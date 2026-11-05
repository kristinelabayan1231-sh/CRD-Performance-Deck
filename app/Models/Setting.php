<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One app-wide value set in Settings, stored by key.
 */
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    /**
     * The saved value for $key, or $default when it was never set.
     */
    public static function value(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, mixed $value, ?User $by = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
