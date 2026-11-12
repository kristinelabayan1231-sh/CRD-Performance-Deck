<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * CRA Gross Sales & IBSC: CRA Supervisors and CRA Team Leads see it. CRAs get nothing by default (it can be given in
     * User Access → Roles). Super Admins have every permission.
     */
    private array $grants = [
        'cra-supervisor' => ['cra_gross_sales.view'],
        'cra-team-lead' => ['cra_gross_sales.view'],
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
