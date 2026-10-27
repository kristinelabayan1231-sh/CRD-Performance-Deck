<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'display_name', 'cra_order', 'pancake_name', 'daily_sales_goal', 'show_product_qty', 'email', 'password', 'google_id', 'avatar', 'role_id', 'is_active', 'granted_by', 'last_login_at'])]
#[Hidden(['password', 'remember_token', 'recording_token', 'call_webhook_url'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'recording_token_created_at' => 'datetime',
            'call_webhook_url' => 'encrypted',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'show_product_qty' => 'boolean',
            'daily_sales_goal' => 'decimal:2',
        ];
    }

    /**
     * Google-only accounts have no password; Laravel ignores the "remember me"
     * cookie unless this is a string, so those users would be signed out.
     */
    public function getAuthPassword(): string
    {
        return $this->password ?? '';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Settings → Niche: the only products whose leads this CRA gets (none = every product).
     */
    public function niche(): BelongsTo
    {
        return $this->belongsTo(Niche::class);
    }

    public function callRecordings(): HasMany
    {
        return $this->hasMany(CallRecording::class);
    }

    /**
     * Their own spiels (Settings → My Spiels), in their order.
     */
    public function spiels(): HasMany
    {
        return $this->hasMany(Spiel::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The user who granted this account access.
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'granted_by');
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->role?->isSuperAdmin();
    }

    public function hasPermission(string $permission): bool
    {
        return (bool) $this->role?->allows($permission);
    }

    /**
     * The configured default super admin, which can never lose access.
     */
    public function isOwner(): bool
    {
        return strtolower($this->email) === config('access.super_admin_email');
    }

    public function roleLabel(): string
    {
        return $this->role?->name ?? 'No role';
    }

    /**
     * The name shown across the site: the display name set in User Access,
     * then the Google account name, then the email.
     */
    public function displayName(): string
    {
        return $this->display_name ?: ($this->name ?: $this->email);
    }

    /**
     * The order every module lists CRAs in (users.cra_order: Regina, July Ann, Joanna Rose, Lhea, Rose-An);
     * anyone without one comes after, oldest account first.
     */
    public function scopeInCraOrder(Builder $query): void
    {
        $query->orderByRaw('cra_order is null')->orderBy('cra_order')->orderBy('id');
    }

    /**
     * The same order as scopeInCraOrder(), for sorting a collection.
     *
     * @return array{int, int}
     */
    public function craSortKey(): array
    {
        return [$this->cra_order ?? PHP_INT_MAX, $this->id];
    }

    /**
     * A new call recording upload token for this CRA's phone, replacing any earlier one. Only its hash is kept,
     * so the token is shown once.
     */
    public function issueRecordingToken(): string
    {
        $token = Str::random(48);
        $this->forceFill(['recording_token' => hash('sha256', $token), 'recording_token_created_at' => now()])->save();

        return $token;
    }

    public static function findByRecordingToken(?string $token): ?self
    {
        return $token ? static::where('recording_token', hash('sha256', $token))->where('is_active', true)->first() : null;
    }

    public function initials(): string
    {
        $parts = preg_split('/[\s@._-]+/', $this->displayName(), -1, PREG_SPLIT_NO_EMPTY);

        return strtoupper(substr($parts[0] ?? '?', 0, 1).substr($parts[1] ?? '', 0, 1));
    }
}
