<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A key for the external API (Settings → API Keys). The key itself is shown once when made; only its SHA-256 hash is
 * kept. A revoked key stops working at once.
 */
#[Fillable(['name', 'prefix', 'key_hash', 'created_by', 'last_used_at', 'revoked_at'])]
class ApiKey extends Model
{
    /** Every key starts with this, so it is easy to spot in code and logs. */
    public const PREFIX = 'crd_';

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Make a key for $name; returns the saved key and the key itself (shown only this once).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, ?User $by = null): array
    {
        $token = self::PREFIX.Str::random(40);

        $key = static::create([
            'name' => $name,
            'prefix' => substr($token, 0, 10),
            'key_hash' => hash('sha256', $token),
            'created_by' => $by?->id,
        ]);

        return [$key, $token];
    }

    /**
     * The working (not revoked) key matching $token, if any.
     */
    public static function findActive(?string $token): ?self
    {
        return $token ? static::active()->where('key_hash', hash('sha256', $token))->first() : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
