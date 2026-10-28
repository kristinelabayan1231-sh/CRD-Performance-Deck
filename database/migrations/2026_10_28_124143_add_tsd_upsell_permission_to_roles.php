<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Settings → Product Consumption → TSD upsell products: CRA Supervisors; Super Admins have every permission.
     */
    private array $grants = [
        'cra-supervisor' => ['product_consumption.tsd'],
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
