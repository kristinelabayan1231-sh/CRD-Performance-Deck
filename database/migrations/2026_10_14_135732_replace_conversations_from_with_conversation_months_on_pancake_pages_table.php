<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track which days of a page's conversations are saved (PC Report's Sync fetches a row's missing days).
     */
    public function up(): void
    {
        Schema::table('pancake_pages', function (Blueprint $table) {
            $table->dropColumn('conversations_from');
            $table->json('conversation_days')->nullable()->after('check_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pancake_pages', function (Blueprint $table) {
            $table->dropColumn('conversation_days');
            $table->timestamp('conversations_from')->nullable()->after('check_message');
        });
    }
};
