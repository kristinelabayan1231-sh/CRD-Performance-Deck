<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each CRA phone's upload token for call recordings (stored as a SHA-256 hash).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('recording_token', 64)->nullable()->unique();
            $table->timestamp('recording_token_created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['recording_token']);
            $table->dropColumn(['recording_token', 'recording_token_created_at']);
        });
    }
};
