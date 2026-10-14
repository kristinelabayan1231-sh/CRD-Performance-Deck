<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Order Database: CRAs see their own accounts; CRA Supervisors and CRA Team Leads see every CRD account. Super Admins have every permission.
     */
    private array $grants = [
        'cra' => ['order_database.view'],
        'cra-supervisor' => ['order_database.view', 'order_database.view_all'],
        'cra-team-lead' => ['order_database.view', 'order_database.view_all'],
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
