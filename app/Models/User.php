<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'display_name', 'pancake_name', 'daily_sales_goal', 'email', 'password', 'google_id', 'avatar', 'role_id', 'is_active', 'granted_by', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
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
            'password' => 'hashed',
            'is_active' => 'boolean',
            'daily_sales_goal' => 'decimal:2',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
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

    /**
     * Where the Segmentation Tracker opens for this user: Summary for supervisors and team leads, else Daily.
     */
    public function segmentationHome(): string
    {
        // Matched on the name too: a role created from the roles screen keeps the slug it was first saved with.
        $summaryFirst = in_array($this->role?->slug, Role::SUMMARY_FIRST, true)
            || in_array(strtolower(trim((string) $this->role?->name)), ['cra supervisor', 'cra team lead'], true);

        return $summaryFirst ? route('segmentation.overview') : route('segmentation.index');
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

    public function initials(): string
    {
        $parts = preg_split('/[\s@._-]+/', $this->displayName(), -1, PREG_SPLIT_NO_EMPTY);

        return strtoupper(substr($parts[0] ?? '?', 0, 1).substr($parts[1] ?? '', 0, 1));
    }
}
