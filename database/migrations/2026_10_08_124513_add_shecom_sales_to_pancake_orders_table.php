<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The order's sales from Shecom, which leaves out the child (TSD) row
     * that Pancake's total_price includes. Gross sales use it when set.
     */
    public function up(): void
    {
        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->decimal('shecom_sales', 12, 2)->nullable()->after('total_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->dropColumn('shecom_sales');
        });
    }
};
