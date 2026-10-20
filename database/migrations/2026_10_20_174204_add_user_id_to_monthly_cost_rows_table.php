<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRD - MC: a row can be a CRA's own position (its salary goes to that CRA in the Monthly MNI). Rows already in are
     * linked to the active CRA whose display name is in the row's name, when exactly one row has it.
     */
    public function up(): void
    {
        Schema::table('monthly_cost_rows', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });

        $cras = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.slug', 'cra')->where('users.is_active', true)->whereNotNull('users.display_name')
            ->pluck('users.display_name', 'users.id');
        $rows = DB::table('monthly_cost_rows')->whereNotNull('name')->pluck('name', 'id');

        foreach ($cras as $userId => $displayName) {
            $matches = $rows->filter(fn (string $name) => str_contains(mb_strtolower($name), mb_strtolower(trim($displayName))));

            if (trim($displayName) !== '' && $matches->count() === 1) {
                DB::table('monthly_cost_rows')->where('id', $matches->keys()->first())->whereNull('user_id')->update(['user_id' => $userId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monthly_cost_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
