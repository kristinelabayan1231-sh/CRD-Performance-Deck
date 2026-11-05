<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a delivered order came from: the logistics retention report (from
     * Apr 5, 2026) or Pancake POS deliveries for the days before it.
     */
    public function up(): void
    {
        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->string('source', 10)->default('logistics')->after('team');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
