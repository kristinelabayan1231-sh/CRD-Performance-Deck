<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move users from a fixed `role` string to editable roles with permissions.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('roles')->insert([
            ['slug' => 'super-admin', 'name' => 'Super Admin', 'description' => 'Full access to every module, including roles.', 'permissions' => '[]', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'user', 'name' => 'User', 'description' => 'Can sign in and view the dashboard.', 'permissions' => '[]', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('avatar')->constrained('roles')->restrictOnDelete();
        });

        $roleIds = DB::table('roles')->pluck('id', 'slug');
        DB::table('users')->where('role', 'super_admin')->update(['role_id' => $roleIds['super-admin']]);
        DB::table('users')->where('role', '!=', 'super_admin')->update(['role_id' => $roleIds['user']]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('avatar');
        });

        $superAdminId = DB::table('roles')->where('slug', 'super-admin')->value('id');
        DB::table('users')->where('role_id', $superAdminId)->update(['role' => 'super_admin']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('roles');
    }
};
