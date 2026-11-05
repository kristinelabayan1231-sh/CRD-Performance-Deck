<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Facebook page and chat conversation an order came from, for PC Report.
     */
    public function up(): void
    {
        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->string('page_id')->nullable()->after('page_name')->index();
            $table->string('conversation_id')->nullable()->after('page_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->dropColumn(['page_id', 'conversation_id']);
        });
    }
};
