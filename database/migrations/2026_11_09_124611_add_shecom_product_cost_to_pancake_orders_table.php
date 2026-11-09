<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The order's product cost from Shecom (the Logistics dashboard's /orders), which the Daily I.S. Product Cost
     * uses when set, so each CRA's product cost matches Shecom's sales report.
     */
    public function up(): void
    {
        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->decimal('shecom_product_cost', 12, 2)->nullable()->after('shecom_sales');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->dropColumn('shecom_product_cost');
        });
    }
};
