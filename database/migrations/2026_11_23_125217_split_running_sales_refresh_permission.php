<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Running Sales: viewing every CRA's running sales and refreshing them are now two permissions. Every role that could
     * view keeps refreshing; CRAs now view every CRA's running sales, without refreshing (asked 2026-11-23). Super Admins
     * have every permission.
     */
    public function up(): void
    {
        foreach (DB::table('roles')->get(['id', 'slug', 'permissions']) as $role) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            $added = match (true) {
                in_array('running_sales.view', $current, true) => ['running_sales.refresh'],
                $role->slug === 'cra' => ['running_sales.view'],
                default => [],
            };

            if ($added !== []) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$current, ...$added])))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'slug', 'permissions']) as $role) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            $removed = $role->slug === 'cra' ? ['running_sales.view', 'running_sales.refresh'] : ['running_sales.refresh'];
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_diff($current, $removed)))]);
        }
    }
};
