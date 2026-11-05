<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A CRA phone another CRA uses for their shift: its uploads called from shared_phone_from until shared_phone_until
     * (Manila time) go to shared_phone_cra_id instead of the token's CRA.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('shared_phone_cra_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('shared_phone_from', 5)->nullable();
            $table->string('shared_phone_until', 5)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shared_phone_cra_id');
            $table->dropColumn(['shared_phone_from', 'shared_phone_until']);
        });
    }
};
