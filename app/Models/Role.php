<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'description', 'permissions', 'is_system'])]
class Role extends Model
{
    public const SUPER_ADMIN = 'super-admin';

    public const USER = 'user';

    public const CRA = 'cra';

    public const CRA_SUPERVISOR = 'cra-supervisor';

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public static function superAdmin(): self
    {
        return static::where('slug', self::SUPER_ADMIN)->firstOrFail();
    }

    public static function defaultUser(): self
    {
        return static::where('slug', self::USER)->firstOrFail();
    }

    public function isSuperAdmin(): bool
    {
        return $this->slug === self::SUPER_ADMIN;
    }

    /**
     * Permission keys this role grants. Super Admin implicitly has all of them.
     *
     * @return list<string>
     */
    public function grantedPermissions(): array
    {
        if ($this->isSuperAdmin()) {
            return array_keys(config('access.permissions'));
        }

        return array_values(array_intersect($this->permissions ?? [], array_keys(config('access.permissions'))));
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->grantedPermissions(), true);
    }
}
