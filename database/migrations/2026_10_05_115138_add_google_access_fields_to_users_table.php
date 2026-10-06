<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('name')->nullable()->change();
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->text('avatar')->nullable()->after('google_id');
            $table->string('role')->default('user')->after('avatar');
            $table->boolean('is_active')->default(true)->after('role');
            $table->foreignId('granted_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable()->after('granted_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('granted_by');
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'avatar', 'role', 'is_active', 'last_login_at']);
        });
    }
};
