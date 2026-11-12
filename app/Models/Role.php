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

    /** Created from Settings → roles as "CRA Team Lead" (not a system role). */
    public const CRA_TEAM_LEAD = 'cra-team-lead';

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

    /**
     * Whether the role has $permission. A module's view permission comes with its wider ones: a role that may see every
     * CRA's (".view_all"), manage (".manage") or edit (".edit") a module may also open it, so giving e.g. a Visitor only
     * "every CRA's Daily I.S." shows Daily I.S. in the menu and opens the page.
     */
    public function allows(string $permission): bool
    {
        $granted = $this->grantedPermissions();

        if (in_array($permission, $granted, true)) {
            return true;
        }

        if (! str_ends_with($permission, '.view')) {
            return false;
        }

        $module = substr($permission, 0, -strlen('.view'));

        return array_intersect(["{$module}.view_all", "{$module}.manage", "{$module}.edit"], $granted) !== [];
    }
}
