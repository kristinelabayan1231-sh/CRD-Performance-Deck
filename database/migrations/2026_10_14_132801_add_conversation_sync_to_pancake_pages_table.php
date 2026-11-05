<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How far back the page's conversations are saved (by creation time) and when their changes were last fetched.
     */
    public function up(): void
    {
        Schema::table('pancake_pages', function (Blueprint $table) {
            $table->timestamp('conversations_from')->nullable()->after('check_message');
            $table->timestamp('conversations_synced_at')->nullable()->after('conversations_from');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pancake_pages', function (Blueprint $table) {
            $table->dropColumn(['conversations_from', 'conversations_synced_at']);
        });
    }
};
