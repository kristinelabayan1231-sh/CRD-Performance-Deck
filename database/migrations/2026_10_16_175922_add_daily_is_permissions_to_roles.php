<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Daily I.S.: CRA Supervisors see the department and every CRA. CRAs get nothing by default (it can be given in
     * User Access → Roles). Super Admins have every permission.
     */
    private array $grants = [
        'cra-supervisor' => ['daily_is.view', 'daily_is.view_all'],
    ];

    public function up(): void
    {
        foreach ($this->grants as $slug => $permissions) {
            $role = DB::table('roles')->where('slug', $slug)->first();

            if ($role) {
                $current = json_decode($role->permissions ?? '[]', true) ?: [];
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode(array_values(array_unique([...$current, ...$permissions]))),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->grants as $slug => $permissions) {
            $role = DB::table('roles')->where('slug', $slug)->first();

            if ($role) {
                $current = json_decode($role->permissions ?? '[]', true) ?: [];
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode(array_values(array_diff($current, $permissions))),
                ]);
            }
        }
    }
};
