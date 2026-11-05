<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The day a product (Settings → Product Consumption) or a Pancake page (Settings → Pancake Pages) starts counting:
     * one added later leaves earlier days as they were. Products and pages already there stay blank: they count on
     * every day, as before.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->date('counts_from')->nullable()->after('unit_cost');
        });
        Schema::table('pancake_pages', function (Blueprint $table) {
            $table->date('counts_from')->nullable()->after('page_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('counts_from');
        });
        Schema::table('pancake_pages', function (Blueprint $table) {
            $table->dropColumn('counts_from');
        });
    }
};
